<?php

namespace Tests\Support\Game;

use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/**
 * Présence d'un siège (spec 60 § 13, lot L60-13) : sièges tenus par un
 * `player_token`, battement envoyé PAR LA ROUTE comme le client l'envoie
 * (cookie du jeton, JSON, horloge figée à l'instant d'envoi), et balayage de
 * présence exécuté comme le worker de la file `game` l'exécute — le verrou
 * d'unicité libéré au début du traitement (`ShouldBeUniqueUntilProcessing`),
 * horloge avancée à l'instant de disponibilité du job, jamais une transition
 * appelée à la main.
 *
 * Exige `Queue::fake([SweepSeatPresence::class, …])` : les balayages armés
 * restent en file simulée, où le test les relit ; leur unicité joue comme en
 * production (le verrou est pris au dispatch, dans le cache).
 */
final class PresenceFixtures
{
    /**
     * Un siège de la partie tenu par un jeton neuf (ligne `game_player`
     * gelée depuis lui) ; l'écriture du lancement, ou d'un retardataire admis.
     *
     * @param  array<string, mixed>  $player  Colonnes du siège imposées (état de connexion…).
     * @return array{0: Player, 1: PlayerToken}
     */
    public static function heldSeat(
        Game $game,
        array $player = [],
        ?int $firstRoundNumber = null,
        GamePlayerStatus $status = GamePlayerStatus::Playing,
    ): array {
        $token = PlayerToken::mint(Locale::French);
        $seat = EngineFixtures::seat($game, ['player_token_hash' => $token->hash(), ...$player], $firstRoundNumber, $status);

        return [$seat, $token];
    }

    /**
     * Le battement du siège de ce jeton, par la route, reçu à `$at`
     * (l'horloge courante sinon).
     *
     * @return TestResponse<Response>
     */
    public static function beat(TestCase $test, Room $room, PlayerToken $token, ?CarbonImmutable $at = null): TestResponse
    {
        if ($at instanceof CarbonImmutable) {
            Date::setTestNow($at);
        }

        LobbyWrites::actAs($test, $token);

        return $test->postJson(route('room.heartbeat', $room));
    }

    /**
     * Les balayages armés, dans l'ordre de leur dispatch.
     *
     * @return list<SweepSeatPresence>
     */
    public static function sweeps(): array
    {
        return array_values(Queue::pushed(SweepSeatPresence::class)->all());
    }

    /**
     * Le dernier balayage armé.
     *
     * @throws LogicException Aucun balayage armé.
     */
    public static function lastSweep(): SweepSeatPresence
    {
        $sweeps = self::sweeps();

        return $sweeps === [] ? throw new LogicException('Aucun balayage armé.') : $sweeps[array_key_last($sweeps)];
    }

    /**
     * L'instant de disponibilité d'un balayage : son délai, un instant.
     *
     * @throws LogicException Délai qui n'est pas un instant.
     */
    public static function dueAt(SweepSeatPresence $sweep): CarbonImmutable
    {
        return $sweep->delay instanceof CarbonImmutable
            ? $sweep->delay
            : throw new LogicException('Le délai d’un balayage est toujours un instant.');
    }

    /**
     * Exécute un balayage comme le worker : horloge à `$at` (son instant de
     * disponibilité par défaut), verrou d'unicité libéré, puis `handle()`.
     */
    public static function run(SweepSeatPresence $sweep, ?CarbonImmutable $at = null): void
    {
        Date::setTestNow($at ?? self::dueAt($sweep));

        (new UniqueLock(app(Repository::class)))->release($sweep);

        app()->call([$sweep, 'handle']);
    }
}
