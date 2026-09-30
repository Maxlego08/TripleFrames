<?php

use App\Actions\Game\MaterializeDraw;
use App\Enums\RoundStatus;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Draw\DrawnRound;
use App\Support\Draw\DrawnTier;
use App\Support\Draw\DrawResult;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Matérialisation des paliers — spec 60 § 5.1, lot L60-5 ; contrats C3 § 3
| et C6 O8
|--------------------------------------------------------------------------
|
| Consommateur du jeu `room_settings.accepted` (contrat C18, spec 100 § 4) :
| chaque combinaison que l'hôte peut réellement poser. Le paramètre est typé
| `RoomSettings` : le jeu rend des fermetures liées, que Pest résout dans le
| test (E8-3).
|
| Pour chaque combinaison, `MaterializeDraw` écrit les `K` manches d'un
| tirage construit à la main (réserve comprise) et leurs `N` paliers. Les
| attendus sont RECALCULÉS depuis les champs bruts du réglage (`tierDurations`
| en secondes, `tierPoints`), jamais par `tierStartOffsetMs()` que l'action
| appelle : chaque palier s'ouvre en millisecondes entières là où le
| précédent se ferme, le premier à 0, le dernier se ferme exactement à `D`,
| chaque durée est un multiple de 1000, et la valeur est le barème figé.
|
| Le tirage n'a besoin que de clés étrangères valides : un film et une
| variante brouillon par niveau suffisent (aucun octet, aucune frappe ici).
|
*/

/**
 * Un tirage de `M + marge` manches sur un seul film, palier `i` sur la
 * variante du niveau nominal `i`.
 *
 * @param  array<int, int>  $frameIdsByLevel  niveau → `frame.id`
 */
function materializationDraw(Game $game, Movie $movie, array $frameIdsByLevel): DrawResult
{
    $rounds = [];
    $count = $game->rounds_count + PlatformLimits::drawSubstituteMargin();

    for ($sequenceIndex = 1; $sequenceIndex <= $count; $sequenceIndex++) {
        $tiers = [];

        foreach (FrameLevelCoverage::nominal($game->frames_per_round) as $index => $level) {
            $tiers[] = new DrawnTier($index + 1, $frameIdsByLevel[$level->value], $level);
        }

        $rounds[] = new DrawnRound(
            $sequenceIndex,
            $sequenceIndex <= $game->rounds_count ? $sequenceIndex : null,
            $movie->id,
            $tiers,
        );
    }

    return new DrawResult($rounds, $count, $game->rounds_count);
}

test('matérialise des décalages de palier entiers dont la somme vaut D pour chaque combinaison acceptée', function (RoomSettings $settings): void {
    $game = Game::factory()->withSettings($settings)->create();
    $movie = Movie::factory()->create();
    $frameIdsByLevel = [];

    foreach (FrameLevelCoverage::nominal($settings->framesPerRound) as $level) {
        $frameIdsByLevel[$level->value] = Frame::factory()->for($movie)->level($level)->create()->id;
    }

    $draw = materializationDraw($game, $movie, $frameIdsByLevel);

    DB::transaction(static fn () => app(MaterializeDraw::class)->handle($game, $draw));

    $rounds = Round::query()->where('game_id', $game->id)->orderBy('sequence_index')->get();
    $durationMs = array_sum($settings->tierDurations) * 1000;

    expect($rounds)->toHaveCount(count($draw->rounds));

    foreach ($rounds as $position => $round) {
        $drawn = $draw->rounds[$position];

        // La manche : pending, jamais programmée, `D` dénormalisée, salon
        // recopié de la partie, réserve sans numéro.
        expect([
            $round->sequence_index, $round->round_number, $round->movie_id, $round->room_id,
            $round->status, $round->started_at, $round->duration_ms,
        ])->toBe([
            $drawn->sequenceIndex, $drawn->roundNumber, $movie->id, $game->room_id,
            RoundStatus::Pending, null, $durationMs,
        ]);

        // Les paliers : décalages entiers enchaînés depuis 0, recalculés
        // depuis les secondes du réglage ; durées multiples de 1000 ;
        // barème figé ; variante et niveau du tirage ; colonnes de service
        // laissées à leurs écrivains uniques (`MintTierServeToken`,
        // `OpenTier`).
        $expected = [];
        $offsetMs = 0;

        foreach ($drawn->tiers as $index => $drawnTier) {
            $tierDurationMs = $settings->tierDurations[$index] * 1000;
            $expected[] = [
                'tier_index' => $index + 1,
                'starts_at_offset_ms' => $offsetMs,
                'duration_ms' => $tierDurationMs,
                'remainder' => 0,
                'points' => $settings->tierPoints[$index],
                'frame_id' => $drawnTier->frameId,
                'frame_level' => $drawnTier->frameLevel,
                'service' => [null, null, null, null],
            ];
            $offsetMs += $tierDurationMs;
        }

        $actual = RoundTier::query()
            ->where('round_id', $round->id)
            ->orderBy('tier_index')
            ->get()
            ->map(static fn (RoundTier $tier): array => [
                'tier_index' => $tier->tier_index,
                'starts_at_offset_ms' => $tier->starts_at_offset_ms,
                'duration_ms' => $tier->duration_ms,
                'remainder' => $tier->duration_ms % 1000,
                'points' => $tier->points,
                'frame_id' => $tier->frame_id,
                'frame_level' => $tier->frame_level,
                'service' => [$tier->serve_token, $tier->served_frame_id, $tier->served_at, $tier->substitution_reason],
            ])
            ->all();

        // Le dernier palier se ferme exactement à `D` : la somme des
        // durées vaut la durée de la manche.
        expect($actual)->toBe($expected)
            ->and($offsetMs)->toBe($round->duration_ms);
    }
})->with('room_settings.accepted');
