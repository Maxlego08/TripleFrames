<?php

namespace App\Actions\Game;

use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\InputClosed;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Answers\DecoyPicker;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Game\GameJournal;
use App\Support\I18n\DisplayTitleResolver;
use App\ValueObjects\Answers\ChoicesPayload;
use App\ValueObjects\Answers\DecoyPick;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Composition du QCM d'une manche (spec 70 § 10.1, § 10.5-10.7, contrat C11)
 * — **une fois par manche, jamais par joueur**, à la première ouverture du
 * palier `InputDifficulty::choicesOpenTierIndex(N)` : `T₁` en Facile, `T_N`
 * en Normal, jamais en Expert, jamais au lancement.
 *
 * Appelée par 60 dans la transition qui ouvre ce palier (`OpenTier`, ou
 * `CatchUpGame` qui la remplace), **après** la création des lignes
 * `round_player` à `T₁` (E10-49) et **avant** l'émission, qui suit le commit.
 * `$at` est l'instant THÉORIQUE `round.started_at + starts_at_offset_ms` du
 * palier du QCM, jamais l'heure d'exécution : les leurres, `composed_at` et
 * `choices_composed_at` ne dépendent pas du retard d'un job. Un autre instant
 * est refusé.
 *
 * **Idempotente** : la manche est relue `FOR UPDATE` ; déjà composée
 * (`decoy_movie_id_1` non nul), rien n'est écrit et la méthode rend vrai.
 *
 * **Deux phases** (§ 10.7, arbitrage 15) :
 *
 * 1. **Calcul, sans écriture** : tirage des trois leurres par
 *    {@see DecoyPicker::pick()} — tirage déjà homogène, contrôle de locale
 *    atteinte compris (E77-1) —, puis rendu des quatre chaînes dans chaque
 *    locale activée par {@see DisplayTitleResolver} (la chaîne de repli en
 *    mode normal, `original()` pour les quatre films et toutes les locales en
 *    mode dégradé), nettoyées de leurs bords par {@see Str::trim()} — la
 *    fonction du middleware global `TrimStrings`, **jamais** `trim()` de PHP,
 *    qui garde U+00A0, U+200B, U+FEFF… et rendrait la bonne proposition
 *    incliquable. Une exception de cette phase est rapportée par `report()`
 *    **sans titre ni chaîne** et traitée comme le cas terminal : une donnée de
 *    catalogue qui fait lever le tirage ne bloque jamais l'ouverture du
 *    palier. Les erreurs de base (connexion, verrou) remontent toujours : un
 *    interblocage MySQL annule la transaction entière, et continuer à écrire
 *    laisserait des lignes hors transaction.
 * 2. **Écriture**, dans la transaction : `round.decoy_movie_id_1..3` et
 *    `choices_use_original_title` ; une ligne `round_choice_set` par locale
 *    activée (`choice_1` = la cible, `choice_2..4` = les leurres dans l'ordre
 *    de `decoy_movie_id_1..3`, `rendered_locale`, `composed_at = $at`) ;
 *    `round_player.choices_locale = player.locale` et
 *    `choices_composed_at = $at` pour chaque siège `open` ou
 *    `text_exhausted`. Une exception de cette phase remonte et annule tout :
 *    aucune ligne partielle.
 *
 * **Cas terminal** (§ 10.7) — moins de trois leurres même après R4, ou échec
 * du calcul : aucune ligne `round_choice_set`, leurres NULL, la méthode rend
 * faux. En Normal, les sièges `text_exhausted` passent `attempts_exhausted`
 * (`input_closed_at = $at`), avec `InputClosed` après commit ; en Facile, 60
 * annule la manche (`choices_unavailable`). Rappelée, la composition
 * recommence. **Toujours tracé** (D54 du 02/10) : une ligne
 * `game.choices_unavailable` au journal `game` et à `game_trace`
 * ({@see GameJournal::choicesUnavailable()}), cause `no_decoys` ou
 * `compute_failed`, après commit seulement, jamais un titre ni un
 * identifiant de film ; le signal aux joueurs (Normal) est la charge de 60
 * (`tier.opened.choicesUnavailable`, `RoundState.choicesUnavailable`).
 *
 * `rendered_locale` = la locale atteinte par les quatre chaînes de la ligne
 * (rang 1 ou 2), NULL au rang 3 ou en mode dégradé. L'ordre d'affichage n'est
 * jamais stocké : {@see ChoicesPresenter} le dérive par siège à chaque envoi.
 */
final readonly class ComposeChoiceSets
{
    public function __construct(
        private DecoyPicker $decoys,
        private DisplayTitleResolver $titles,
    ) {}

    /**
     * Compose le QCM de la manche à l'instant théorique `$at` ; vrai si les
     * quatre propositions existent après l'appel (composées maintenant ou
     * déjà), faux dans le cas terminal.
     *
     * L'instance `$round` passée n'est pas modifiée : la manche est relue sous
     * verrou.
     *
     * @throws LogicException Partie en Expert, manche non démarrée ou palier du QCM non matérialisé.
     * @throws InvalidArgumentException `$at` n'est pas l'instant théorique d'ouverture du QCM.
     */
    public function handle(Round $round, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($round, $at): bool {
            $locked = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->decoy_movie_id_1 !== null) {
                return true;
            }

            $game = Game::query()->findOrFail($locked->game_id);

            self::assertChoicesOpenAt($locked, $game, $at);

            $composition = $this->computeOrReport($locked, $game, $at);

            if (is_string($composition)) {
                GameJournal::choicesUnavailable(
                    $game,
                    $locked,
                    (int) $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round),
                    $composition,
                );
                self::closeAwaitingSeats($locked, $game, $at);

                return false;
            }

            self::write($locked, $composition['decoys'], $composition['rows'], $at);

            return true;
        });
    }

    /**
     * Garde d'entrée : le QCM existe dans cette partie, et `$at` est
     * l'instant théorique d'ouverture de son palier, à la milliseconde
     * **tronquée** — la précision de stockage (`timestamp(3)`, format
     * `Y-m-d H:i:s.v`), jamais l'arrondi de `getTimestampMs()`, qui refuserait
     * un `$at` à microsecondes qu'Eloquent écrirait à l'identique.
     *
     * @throws LogicException
     * @throws InvalidArgumentException
     */
    private static function assertChoicesOpenAt(Round $round, Game $game, CarbonImmutable $at): void
    {
        $tierIndex = $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round)
            ?? throw new LogicException('ComposeChoiceSets : aucun QCM n’est composé en difficulté Expert.');

        $offsetMs = RoundTier::query()
            ->where('round_id', $round->id)
            ->where('tier_index', $tierIndex)
            ->value('starts_at_offset_ms');

        if ($round->started_at === null || $offsetMs === null) {
            throw new LogicException('ComposeChoiceSets : la manche n’est pas démarrée, ou le palier du QCM n’est pas matérialisé.');
        }

        $theoretical = $round->started_at->addMilliseconds((int) $offsetMs);

        if (self::storedMilliseconds($theoretical) !== self::storedMilliseconds($at)) {
            throw new InvalidArgumentException(
                'ComposeChoiceSets : $at doit être l’instant théorique d’ouverture du palier du QCM, jamais l’heure d’exécution.',
            );
        }
    }

    /**
     * L'instant en millisecondes Unix, tronqué comme le stocke une colonne
     * `timestamp(3)` : le `v` du format est celui de `#[DateFormat]`, et `U`
     * ne dépend d'aucun fuseau.
     */
    private static function storedMilliseconds(CarbonImmutable $instant): int
    {
        return (int) $instant->format('Uv');
    }

    /**
     * Phase de calcul, dont tout échec autre qu'une erreur de base vaut cas
     * terminal. Le rapport ne porte ni le message ni la trace de l'exception
     * d'origine, qui peuvent citer un titre : sa classe et son emplacement
     * suffisent à la retrouver.
     *
     * Rend la composition, ou, dans le cas terminal, sa cause pour le
     * journal : {@see GameJournal::CHOICES_CAUSE_NO_DECOYS} (moins de trois
     * leurres) ou {@see GameJournal::CHOICES_CAUSE_COMPUTE_FAILED}.
     *
     * @return array{decoys: DecoyPick, rows: list<array{locale: Locale, choices: list<string>, rendered: Locale|null}>}|string
     */
    private function computeOrReport(Round $round, Game $game, CarbonImmutable $at): array|string
    {
        try {
            return $this->compute($round, $game, $at) ?? GameJournal::CHOICES_CAUSE_NO_DECOYS;
        } catch (Throwable $exception) {
            if (self::isDatabaseFailure($exception)) {
                throw $exception;
            }

            report(new RuntimeException(sprintf(
                'ComposeChoiceSets : calcul du QCM impossible (manche %d, séquence %d), traité comme le cas terminal — %s levée en %s:%d.',
                $round->id,
                $round->sequence_index,
                $exception::class,
                $exception->getFile(),
                $exception->getLine(),
            )));

            return GameJournal::CHOICES_CAUSE_COMPUTE_FAILED;
        }
    }

    /**
     * Une erreur de base — requête, pilote, connexion perdue — n'est jamais un
     * cas terminal : elle remonte et annule la transition, que 60 rejoue.
     */
    private static function isDatabaseFailure(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            || $exception instanceof PDOException
            || $exception instanceof LostConnectionException;
    }

    /**
     * Le tirage des leurres, puis les quatre chaînes de chaque locale
     * activée ; NULL dans le cas terminal du tirage.
     *
     * @return array{decoys: DecoyPick, rows: list<array{locale: Locale, choices: list<string>, rendered: Locale|null}>}|null
     *
     * @throws LogicException Film introuvable, chaîne vide, formes en collision ou locales atteintes hétérogènes.
     */
    private function compute(Round $round, Game $game, CarbonImmutable $at): ?array
    {
        $pick = $this->decoys->pick($round, $game, $at);

        if ($pick === null) {
            return null;
        }

        // La cible d'abord, puis les leurres dans l'ordre du tirage : c'est
        // l'ordre de `choice_1..4`, donc celui du jugement d'un clic.
        $ids = [$round->movie_id, ...$pick->movieIds];
        $loaded = Movie::query()->with('titles')->findMany($ids)->keyBy('id');
        $movies = array_map(
            static fn (int $id): Movie => $loaded->get($id)
                ?? throw new LogicException('ComposeChoiceSets : un film du QCM est introuvable.'),
            $ids,
        );

        $rows = [];

        foreach (Locale::cases() as $locale) {
            $rows[] = $this->render($movies, $locale, $pick->useOriginalTitle);
        }

        return ['decoys' => $pick, 'rows' => $rows];
    }

    /**
     * Les quatre chaînes d'une locale de ligne et la locale qu'elles
     * atteignent.
     *
     * @param  list<Movie>  $movies  La cible, puis les trois leurres.
     * @return array{locale: Locale, choices: list<string>, rendered: Locale|null}
     *
     * @throws LogicException
     */
    private function render(array $movies, Locale $locale, bool $useOriginalTitle): array
    {
        $choices = [];
        $reached = [];

        foreach ($movies as $movie) {
            if ($useOriginalTitle) {
                $text = $this->titles->original($movie);
                $reached[] = null;
            } else {
                $resolved = $this->titles->resolve($movie, $locale);
                $text = $resolved->text;
                $reached[] = $resolved->locale;
            }

            $choices[] = Str::trim($text);
        }

        // Le tirage rend des films qui atteignent la même locale (E77-1) : un
        // écart ici serait un QCM linguistiquement hétérogène, donc une fuite.
        $rendered = $reached[0] ?? null;

        foreach ($reached as $reachedLocale) {
            if ($reachedLocale !== $rendered) {
                throw new LogicException('ComposeChoiceSets : les quatre films n’atteignent pas la même locale.');
            }
        }

        if (in_array('', $choices, true)) {
            throw new LogicException('ComposeChoiceSets : une proposition est vide après nettoyage.');
        }

        $forms = array_map(AnswerKeyNormalizer::normalize(...), $choices);

        if (count(array_unique($forms)) !== ChoicesPayload::COUNT) {
            throw new LogicException('ComposeChoiceSets : deux propositions ont la même forme normalisée.');
        }

        return ['locale' => $locale, 'choices' => $choices, 'rendered' => $rendered];
    }

    /**
     * Phase d'écriture : la manche, une ligne par locale activée, et la
     * langue de composition de chaque siège dont la saisie n'est pas close.
     *
     * @param  list<array{locale: Locale, choices: list<string>, rendered: Locale|null}>  $rows
     */
    private static function write(Round $round, DecoyPick $decoys, array $rows, CarbonImmutable $at): void
    {
        $attributes = ['choices_use_original_title' => $decoys->useOriginalTitle];

        foreach ($decoys->movieIds as $index => $movieId) {
            $attributes['decoy_movie_id_'.($index + 1)] = $movieId;
        }

        $round->forceFill($attributes)->save();

        foreach ($rows as $row) {
            $set = [
                'round_id' => $round->id,
                'locale' => $row['locale'],
                'rendered_locale' => $row['rendered'],
                'composed_at' => $at,
            ];

            foreach ($row['choices'] as $index => $choice) {
                $set['choice_'.($index + 1)] = $choice;
            }

            (new RoundChoiceSet)->forceFill($set)->save();
        }

        // `open` et `text_exhausted` : le scope lit la liste des états non
        // clos, seule source. La langue est celle du siège À CET INSTANT, et ne
        // bouge plus : un changement de langue en manche ne recompose jamais.
        $seats = RoundPlayer::query()
            ->where('round_id', $round->id)
            ->open()
            ->with('player:id,locale')
            ->get();

        /** @var array<string, list<int>> $seatIdsByLocale */
        $seatIdsByLocale = [];

        foreach ($seats as $seat) {
            $seatIdsByLocale[$seat->player->locale->value][] = $seat->id;
        }

        $composedAt = (new RoundPlayer)->fromDateTime($at);

        foreach ($seatIdsByLocale as $locale => $seatIds) {
            RoundPlayer::query()
                ->whereKey($seatIds)
                ->update([
                    'choices_locale' => $locale,
                    'choices_composed_at' => $composedAt,
                ]);
        }
    }

    /**
     * Cas terminal en Normal : les sièges qui attendaient le QCM
     * (`text_exhausted`) n'ont plus rien à attendre, leur saisie se ferme à
     * l'instant théorique de composition. `text_exhausted` n'existant qu'en
     * Normal (§ 3.4), rien d'autre n'est touché.
     */
    private static function closeAwaitingSeats(Round $round, Game $game, CarbonImmutable $at): void
    {
        if ($game->input_difficulty !== InputDifficulty::Normal) {
            return;
        }

        $seats = RoundPlayer::query()
            ->where('round_id', $round->id)
            ->where('input_state', RoundPlayerInputState::TextExhausted->value)
            ->get(['id', 'player_id']);

        if ($seats->isEmpty()) {
            return;
        }

        RoundPlayer::query()
            ->whereKey($seats->modelKeys())
            ->where('input_state', RoundPlayerInputState::TextExhausted->value)
            ->update([
                'input_state' => RoundPlayerInputState::AttemptsExhausted->value,
                'input_closed_at' => (new RoundPlayer)->fromDateTime($at),
            ]);

        // Émis dans la transaction, délivré après son commit.
        foreach ($seats as $seat) {
            InputClosed::dispatch($round->id, $seat->player_id, RoundPlayerInputState::AttemptsExhausted);
        }
    }
}
