<?php

namespace Tests\Support\Retention;

use App\Enums\PurgeScope;
use App\Models\AudienceDaily;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\GameTrace;
use App\Models\PerfSample;
use App\Models\Player;
use App\Models\PurgeRun;
use App\Models\Room;
use App\Support\Retention\RetentionWindows;
use App\Support\Room\RoomCode;
use App\Support\Room\SeatPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Des lignes de chaque périmètre de purge, datées par rapport à sa fenêtre de
 * 10 § 11.1 — aucune donnée réelle : identifiants de session, adresses et
 * jetons sont fabriqués, et les adresses IP viennent des plages de
 * documentation (RFC 5737).
 *
 * Chaque ligne porte un **marqueur** (`seed-` en tête de clé, file `seed`,
 * scope `stale_lobby` pour `purge_run`, code `SEED..` pour un salon,
 * `public_id` `SEED…` pour un siège solo) :
 * {@see self::seeded()} ne compte qu'elles, jamais les lignes que la purge
 * écrit elle-même.
 *
 * {@see self::seed()} connaît chaque périmètre implémenté et LÈVE pour un
 * périmètre inconnu : un périmètre ajouté à `PurgeScope::implemented()` sans
 * jeu de lignes ici fait échouer les tests qui balaient tous les périmètres,
 * au lieu de passer sans rien prouver.
 */
final class RetentionRows
{
    public const string MARKER = 'seed-';

    public const string QUEUE_MARKER = 'seed';

    /** `purge_run` n'a pas de clé à marquer : le scope du balayage de 50, que la purge n'écrit jamais. */
    public const PurgeScope PURGE_RUN_MARKER = PurgeScope::StaleLobby;

    /**
     * Les quatre premiers signes du code d'un salon de test (`stale_room`),
     * tous de `RoomCode::ALPHABET` ; les deux derniers le numérotent.
     */
    public const string ROOM_CODE_MARKER = 'SEED';

    /**
     * Les quatre premiers signes du `public_id` d'un siège solo de test
     * (`orphan_player`), tous de `SeatPublicId::ALPHABET` : le marqueur
     * survit à l'effacement des identifiants, que le pseudo ne survivrait pas.
     */
    public const string SEAT_ID_MARKER = 'SEED';

    /** Rang du prochain salon marqué, pour des codes distincts dans un test. */
    private static int $rooms = 0;

    /**
     * Deux lignes éligibles et deux lignes conservées pour `$scope`, à
     * l'instant `$now` : l'une juste au-delà de la fenêtre, l'une loin
     * au-delà ; l'une exactement à la borne (non éligible : la borne est
     * stricte), l'une récente.
     *
     * @return array{eligible: int, kept: int}
     */
    public static function seed(PurgeScope $scope, CarbonImmutable $now): array
    {
        $cutoff = self::cutoff($scope, $now);

        foreach ([$cutoff->subSecond(), $cutoff->subDays(40)] as $eligible) {
            self::insert($scope, $eligible);
        }

        foreach ([$cutoff, $now->subMinute()] as $kept) {
            self::insert($scope, $kept);
        }

        return ['eligible' => 2, 'kept' => 2];
    }

    /**
     * La borne d'éligibilité de `$scope` à `$now`, calculée ici depuis les
     * fenêtres de 10 § 11.1 et non depuis le gestionnaire : c'est ce que le
     * test compare au prédicat du gestionnaire.
     */
    public static function cutoff(PurgeScope $scope, CarbonImmutable $now): CarbonImmutable
    {
        return match ($scope) {
            PurgeScope::StaleRoom => $now->subHours(RetentionWindows::STALE_ROOM_HOURS),
            PurgeScope::OrphanPlayer => $now->subMinutes(RetentionWindows::SOLO_SEAT_IDLE_MINUTES),
            PurgeScope::FrameworkSessions => $now->subMinutes(RetentionWindows::sessionLifetimeMinutes()),
            PurgeScope::FrameworkFailedJobs => $now->subDays(RetentionWindows::FAILED_JOBS_DAYS),
            PurgeScope::FrameworkResetTokens => $now->subMinutes(RetentionWindows::resetTokenMinutes()),
            PurgeScope::PurgeRun => $now->subMonthsNoOverflow(RetentionWindows::PURGE_RUN_MONTHS),
            PurgeScope::Perf, PurgeScope::GameTrace => $now->subDays(RetentionWindows::PERF_DAYS),
            PurgeScope::Audience => $now->startOfDay()->subMonthsNoOverflow(RetentionWindows::AUDIENCE_MONTHS),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /** Insère une ligne marquée de `$scope`, datée de `$at` sur sa colonne pilote. */
    public static function insert(PurgeScope $scope, CarbonImmutable $at, ?string $key = null): void
    {
        $key ??= self::MARKER.Str::lower(Str::random(12));

        match ($scope) {
            PurgeScope::StaleRoom => self::room($at),
            PurgeScope::OrphanPlayer => self::soloSeat($at),
            PurgeScope::FrameworkSessions => self::session($key, $at),
            PurgeScope::FrameworkFailedJobs => self::failedJob($at),
            PurgeScope::FrameworkResetTokens => self::resetToken($key.'@example.com', $at),
            PurgeScope::PurgeRun => self::purgeRun($at),
            PurgeScope::Perf => self::perfSample($key, $at),
            PurgeScope::GameTrace => self::gameTrace($key, $at),
            PurgeScope::Audience => self::audienceCounter($key, $at),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /**
     * Le nombre de lignes marquées encore présentes dans le périmètre de
     * `$scope` : dans sa table, ou, pour `stale_room`, qui archive sans jamais
     * supprimer, encore non archivées, et, pour `orphan_player`, qui efface
     * des colonnes sans jamais supprimer, les sièges solo qui portent encore
     * un identifiant d'invité.
     */
    public static function seeded(PurgeScope $scope): int
    {
        return match ($scope) {
            PurgeScope::StaleRoom => Room::query()
                ->where('room_code', 'like', self::ROOM_CODE_MARKER.'%')
                ->whereNull('archived_at')
                ->count(),
            PurgeScope::OrphanPlayer => Player::query()
                ->whereNull('room_id')
                ->where('public_id', 'like', self::SEAT_ID_MARKER.'%')
                ->where(static fn (Builder $identity): Builder => $identity
                    ->whereNotNull('nickname')
                    ->orWhereNotNull('nickname_normalized')
                    ->orWhereNotNull('player_token_hash')
                    ->orWhereNotNull('solo_token_hash')
                    ->orWhereHas('gamePlayers', static fn (Builder $participation): Builder => $participation->whereNotNull('display_nickname')))
                ->count(),
            PurgeScope::FrameworkSessions => DB::table(Config::string('session.table'))
                ->where('id', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::FrameworkFailedJobs => DB::table(Config::string('queue.failed.table'))
                ->where('queue', self::QUEUE_MARKER)
                ->count(),
            PurgeScope::FrameworkResetTokens => DB::table(self::resetTokenTable())
                ->where('email', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::PurgeRun => PurgeRun::query()
                ->where('scope', self::PURGE_RUN_MARKER->value)
                ->count(),
            PurgeScope::Perf => PerfSample::query()
                ->where('name', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::GameTrace => GameTrace::query()
                ->where('event', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::Audience => AudienceDaily::query()
                ->where('dimension', 'like', self::MARKER.'%')
                ->count(),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /**
     * Un salon au lobby, jamais archivé, dernière activité à `$lastActivityAt`,
     * au code marqué ; un siège y garde un pseudo et une empreinte de jeton,
     * les identifiants d'invité que l'archivage efface.
     */
    public static function room(CarbonImmutable $lastActivityAt): Room
    {
        $rank = self::$rooms++ % (strlen(RoomCode::ALPHABET) ** 2);
        $code = self::ROOM_CODE_MARKER
            .RoomCode::ALPHABET[intdiv($rank, strlen(RoomCode::ALPHABET))]
            .RoomCode::ALPHABET[$rank % strlen(RoomCode::ALPHABET)];

        $room = Room::factory()->create([
            'room_code' => $code,
            'room_code_active' => $code,
            'last_activity_at' => $lastActivityAt,
        ]);

        Player::factory()->for($room)->create([
            'joined_at' => $lastActivityAt,
            'last_seen_at' => $lastActivityAt,
        ]);

        return $room;
    }

    /**
     * Un siège solo, dernière activité à `$lastSeenAt`, au `public_id` marqué,
     * tel que le démarrage solo l'écrit : pseudo et forme normalisée, empreinte
     * du jeton et créneau d'unicité `solo_token_hash` (sa copie, dans la même
     * écriture) ; une partie solo figée y garde le pseudo figé.
     */
    public static function soloSeat(CarbonImmutable $lastSeenAt): Player
    {
        $tokenHash = hash('sha256', self::MARKER.Str::random(40));
        $publicId = self::SEAT_ID_MARKER;

        while (strlen($publicId) < SeatPublicId::LENGTH) {
            $publicId .= SeatPublicId::ALPHABET[random_int(0, strlen(SeatPublicId::ALPHABET) - 1)];
        }

        $seat = Player::factory()->solo()->create([
            'public_id' => $publicId,
            'player_token_hash' => $tokenHash,
            'solo_token_hash' => $tokenHash,
            'joined_at' => $lastSeenAt->subHour(),
            'last_seen_at' => $lastSeenAt,
        ]);

        $game = Game::factory()->solo()->completed()->create([
            'started_at' => $lastSeenAt->subHour(),
            'ended_at' => $lastSeenAt->subMinutes(30),
        ]);

        GamePlayer::factory()->for($game)->frozenFrom($seat)->create();

        return $seat;
    }

    /** Une session, avec l'adresse IP et l'agent que la migration du starter y stocke. */
    public static function session(string $id, CarbonImmutable $lastActivity): void
    {
        DB::table(Config::string('session.table'))->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '192.0.2.'.random_int(1, 254),
            'user_agent' => 'Agent de test',
            'payload' => base64_encode('charge de test'),
            'last_activity' => $lastActivity->getTimestamp(),
        ]);
    }

    public static function failedJob(CarbonImmutable $failedAt): void
    {
        DB::table(Config::string('queue.failed.table'))->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => self::QUEUE_MARKER,
            'payload' => '{"displayName":"Tâche de test"}',
            'exception' => 'Exception de test',
            'failed_at' => $failedAt,
        ]);
    }

    public static function resetToken(string $email, CarbonImmutable $createdAt): void
    {
        DB::table(self::resetTokenTable())->insert([
            'email' => $email,
            'token' => hash('sha256', $email),
            'created_at' => $createdAt,
        ]);
    }

    private static function resetTokenTable(): string
    {
        return Config::string('auth.passwords.'.RetentionWindows::passwordBroker().'.table');
    }

    /** Un échantillon de mesure marqué par son nom (périmètre `perf`, D47 du 01/10). */
    public static function perfSample(string $key, CarbonImmutable $recordedAt): void
    {
        PerfSample::factory()->create(['name' => $key, 'recorded_at' => $recordedAt]);
    }

    /** Une ligne de chronologie marquée par son événement (périmètre `game_trace`). */
    public static function gameTrace(string $key, CarbonImmutable $recordedAt): void
    {
        GameTrace::factory()->create(['event' => $key, 'recorded_at' => $recordedAt]);
    }

    /**
     * Un compteur d'audience marqué par sa dimension (périmètre `audience`).
     * La colonne pilote est un jour : une borne au jour près, et deux lignes
     * du même jour se distinguent par leur dimension.
     */
    public static function audienceCounter(string $key, CarbonImmutable $day): void
    {
        AudienceDaily::factory()->counter($day->toDateString(), 'pageviews', $key, 1)->create();
    }

    public static function purgeRun(CarbonImmutable $ranAt): void
    {
        PurgeRun::factory()->forScope(self::PURGE_RUN_MARKER)->create([
            'started_at' => $ranAt,
            'finished_at' => $ranAt->addMinute(),
            'ran_at' => $ranAt,
        ]);
    }
}
