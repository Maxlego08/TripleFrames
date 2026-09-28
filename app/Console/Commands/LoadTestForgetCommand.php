<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Room\RoomCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `loadtest:forget {file} {--dry-run}` — le nettoyage des données
 * synthétiques du test de charge (spec 100 § 16.5, lot L100-12).
 *
 * Les parties du test créent de vrais faits de partie dans la base de
 * production ; laissés en place, ils fausseraient pendant douze mois les
 * films « jamais trouvés » et les incidents par film (20). La commande est
 * jouée **avant l'archivage** des salons, tant que les pseudos existent :
 * c'est le pseudo qui prouve qu'un siège est synthétique.
 *
 * Un salon n'est oublié que s'il réunit les trois conditions, relues sous son
 * verrou :
 *
 * 1. son code figure dans le fichier que le scénario k6 a écrit sous
 *    `tests/Load/.data/` (un code par ligne ; lignes vides et lignes
 *    `#` ignorées), et désigne un salon **actif** — un salon archivé a perdu
 *    ses pseudos et n'est plus reconnaissable ;
 * 2. **chacun** de ses sièges, partis et expulsés compris, porte un pseudo
 *    synthétique : {@see self::SYNTHETIC_NICKNAME_PREFIX} suivi d'un numéro
 *    ({@see self::isSyntheticNickname()}). Un seul siège réel suffit à écarter
 *    le salon entier ;
 * 3. aucune de ses parties n'est en cours : supprimer les lignes d'une
 *    partie dont les jobs de frontière attendent encore ferait échouer ces
 *    jobs. La commande se relance une fois les parties terminées.
 *
 * Pour chaque salon retenu, dans **une** transaction : ses faits dans
 * l'ordre imposé par les `restrictOnDelete` (10 § 11.3) — `guess`,
 * `round_choice_set`, `round_tier`, `round_player`, `round`, `game_player`,
 * `game` —, puis ses sièges (`player`) et le salon (`room`), dont la
 * cascade emporte les `seen_frame`. Un salon dont l'oubli échoue reste
 * intact et n'empêche pas les suivants.
 *
 * Elle ne touche **aucune** table du catalogue (10 § 11.2) : la règle 12
 * (instantané préalable) ne la concerne pas. Aucune ligne `admin_action` :
 * la liste fermée appartient à 10, et ce nettoyage n'est pas un geste sur un
 * sujet du catalogue ni sur un compte.
 *
 * Sortie en français par clés littérales, locale `fr` forcée (§ 11.3). Le
 * journal de l'application ne reçoit que des nombres : jamais un pseudo ni un
 * `room_code`. Codes de sortie : 0 si chaque salon retenu a été oublié (ou,
 * sous `--dry-run`, aurait pu l'être) ; 1 si le fichier est illisible ou si
 * l'oubli d'un salon a échoué.
 */
class LoadTestForgetCommand extends Command
{
    /**
     * Préfixe des pseudos des joueurs simulés (spec 100 § 16.1), suivi d'un
     * numéro : `k6-17`. Le scénario `tests/Load/game-load.js` en porte le
     * miroir, que `LoadScenarioTest` compare à cette constante.
     *
     * « Réservé » par convention du test, et non par la règle de pseudo de
     * 40 : le refuser aux joueurs réels le refuserait aussi aux joueurs
     * simulés, qui prennent leur siège par les vraies routes. La protection
     * tient à la conjonction des conditions ci-dessus.
     */
    public const string SYNTHETIC_NICKNAME_PREFIX = 'k6-';

    /** Issue : le salon est (ou serait) oublié. */
    private const string FORGOTTEN = 'forgotten';

    /** Issue : au moins un siège sans pseudo synthétique, ou aucun siège. */
    private const string REAL_SEAT = 'real_seat';

    /** Issue : une partie du salon est encore en cours. */
    private const string IN_PROGRESS = 'in_progress';

    /** Issue : le salon a été archivé ou supprimé entre le relevé et son verrou. */
    private const string GONE = 'gone';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loadtest:forget
        {file : liste des codes de salon produite par le scénario}
        {--dry-run : Dire ce qui serait oublié, sans rien supprimer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Oublie les salons synthétiques du test de charge : leurs faits de partie, leurs sièges et le salon';

    /**
     * Vrai si `$nickname` est un pseudo synthétique : le préfixe, puis un
     * numéro, rien d'autre. Un pseudo effacé (NULL) ne l'est jamais.
     */
    public static function isSyntheticNickname(?string $nickname): bool
    {
        if ($nickname === null) {
            return false;
        }

        return preg_match('/^'.preg_quote(self::SYNTHETIC_NICKNAME_PREFIX, '/').'[0-9]+$/D', $nickname) === 1;
    }

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $codes = self::readCodes($file);

        if ($codes === null) {
            $this->components->error(self::text(trans('admin.console.loadtest.missing_file', ['file' => $file], Locale::French->value)));

            return self::FAILURE;
        }

        $rooms = $codes === []
            ? collect()
            : Room::query()->whereIn('room_code_active', $codes)->orderBy('id')->get();

        $notFound = count($codes) - $rooms->count();
        $skippedRealSeat = 0;
        $skippedInProgress = 0;
        $eligible = [];

        foreach ($rooms as $room) {
            match (self::verdict($room)) {
                self::REAL_SEAT => $skippedRealSeat++,
                self::IN_PROGRESS => $skippedInProgress++,
                default => $eligible[] = $room->id,
            };
        }

        if ($this->option('dry-run')) {
            $this->reportSkipped($notFound, $skippedRealSeat, $skippedInProgress);
            $this->components->info(self::text(trans('admin.console.loadtest.dry_run', ['rooms' => count($eligible)], Locale::French->value)));

            return self::SUCCESS;
        }

        $forgotten = 0;
        $failed = 0;

        foreach ($eligible as $roomId) {
            try {
                // Relu sous le verrou du salon : la prise de siège le prend
                // aussi, et un siège réel arrivé entre-temps écarte le salon.
                $outcome = DB::transaction(static fn (): string => self::forget($roomId));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);

                continue;
            }

            match ($outcome) {
                self::FORGOTTEN => $forgotten++,
                self::REAL_SEAT => $skippedRealSeat++,
                self::IN_PROGRESS => $skippedInProgress++,
                default => $notFound++,
            };
        }

        $this->reportSkipped($notFound, $skippedRealSeat, $skippedInProgress);
        $this->components->info(self::text(trans('admin.console.loadtest.deleted', ['rooms' => $forgotten], Locale::French->value)));

        if ($failed > 0) {
            $this->components->error(self::text(trans('admin.console.loadtest.failed', ['count' => $failed], Locale::French->value)));
        }

        Log::notice('Salons synthétiques du test de charge oubliés.', [
            'rooms' => $forgotten,
            'failed' => $failed,
            'skipped_real_seat' => $skippedRealSeat,
            'skipped_in_progress' => $skippedInProgress,
            'not_found' => $notFound,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Les codes du fichier, normalisés comme à la saisie
     * ({@see RoomCode::normalize()}) et dédoublonnés ; un code mal formé est
     * gardé tel quel, et ne désignera aucun salon. `null` si le fichier est
     * illisible.
     *
     * @return list<string>|null
     */
    private static function readCodes(string $file): ?array
    {
        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $codes = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $codes[RoomCode::normalize($line)] = true;
        }

        return array_map('strval', array_keys($codes));
    }

    /**
     * Ce que la commande ferait du salon `$room` : l'écarter pour un siège
     * réel ({@see self::REAL_SEAT}) ou une partie en cours
     * ({@see self::IN_PROGRESS}), sinon l'oublier ({@see self::FORGOTTEN}).
     * Un salon sans aucun siège n'est pas prouvé synthétique.
     */
    private static function verdict(Room $room): string
    {
        $nicknames = Player::query()->where('room_id', $room->id)->pluck('nickname');

        if ($nicknames->isEmpty() || ! $nicknames->every(static fn (?string $nickname): bool => self::isSyntheticNickname($nickname))) {
            return self::REAL_SEAT;
        }

        if (Game::query()->where('room_id', $room->id)->inProgress()->exists()) {
            return self::IN_PROGRESS;
        }

        return self::FORGOTTEN;
    }

    /**
     * Oublie le salon `$roomId`, dans la transaction ouverte par l'appelant,
     * sous le verrou de sa ligne, et rend l'issue : {@see self::FORGOTTEN},
     * ou le motif qui l'écarte une fois relu sous ce verrou — rien n'est alors
     * supprimé ; {@see self::GONE} pour un salon archivé ou disparu
     * entre-temps.
     */
    private static function forget(int $roomId): string
    {
        $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();

        if ($room === null || $room->archived_at !== null) {
            return self::GONE;
        }

        $verdict = self::verdict($room);

        if ($verdict !== self::FORGOTTEN) {
            return $verdict;
        }

        $gameIds = Game::query()->where('room_id', $room->id)->pluck('id');
        $roundIds = Round::query()->whereIn('game_id', $gameIds)->pluck('id');

        // Ordre imposé par les `restrictOnDelete` (10 § 11.3) : feuilles
        // d'abord. Un autre ordre échouerait sur une contrainte, et la
        // transaction entière serait annulée.
        Guess::query()->whereIn('round_id', $roundIds)->delete();
        RoundChoiceSet::query()->whereIn('round_id', $roundIds)->delete();
        RoundTier::query()->whereIn('round_id', $roundIds)->delete();
        RoundPlayer::query()->whereIn('round_id', $roundIds)->delete();
        Round::query()->whereKey($roundIds)->delete();
        GamePlayer::query()->whereIn('game_id', $gameIds)->delete();
        Game::query()->whereKey($gameIds)->delete();
        Player::query()->where('room_id', $room->id)->delete();
        // La cascade de `seen_frame.room_id` emporte la mémoire d'images.
        Room::query()->whereKey($room->id)->delete();

        return self::FORGOTTEN;
    }

    private function reportSkipped(int $notFound, int $skippedRealSeat, int $skippedInProgress): void
    {
        if ($notFound > 0) {
            $this->components->warn(self::text(trans('admin.console.loadtest.not_found', ['count' => $notFound], Locale::French->value)));
        }

        if ($skippedRealSeat > 0) {
            $this->components->warn(self::text(trans('admin.console.loadtest.skipped_real_seat', ['count' => $skippedRealSeat], Locale::French->value)));
        }

        if ($skippedInProgress > 0) {
            $this->components->warn(self::text(trans('admin.console.loadtest.skipped_in_progress', ['count' => $skippedInProgress], Locale::French->value)));
        }
    }

    /** Une traduction de `trans()`, ramenée à une chaîne (clés littérales, § 11.3). */
    private static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
