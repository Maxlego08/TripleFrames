<?php

namespace App\Support\Ops;

use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameProbes;
use Carbon\CarbonImmutable;

/**
 * La sonde `integrity` — spec 100 § 15 : toutes ses requêtes doivent compter
 * **zéro**.
 *
 * - Sondes SQL 1 à 3 de 10 § 11.3 : partie jamais clôturée, salon jamais
 *   archivé, frame retirée dont les fichiers n'ont pas été supprimés. Elles
 *   sont écrites ici, sur les colonnes pilotes indexées que 10 leur donne.
 * - Les quatre sondes de frame de 20 § 5.9 (E10-18, E10-19, A16), dont 20
 *   écrit les tests : elles sont APPELÉES dans {@see FrameProbes}, jamais
 *   recopiées, pour qu'une seule copie de chaque requête existe. La requête
 *   d'audit du plancher tourne aux valeurs COURANTES de `PlatformLimits` :
 *   c'est elle qui rend l'engagement « image transformée » démontrable sur
 *   les frames réelles (D6 du 23/09, A-36).
 *
 * La réparation d'une partie signalée par la sonde n° 1 passe par l'action de
 * gel de 80, jamais par une écriture directe (10 § 11.3) ; un balayage
 * `stale_lobby` bloqué se voit par la sonde n° 2.
 */
final class IntegrityProbe
{
    /** Sonde n° 1 de 10 § 11.3 : une partie sans `ended_at` depuis 24 h. */
    public const int UNFINISHED_GAME_HOURS = 24;

    /** Sonde n° 2 de 10 § 11.3 : un salon sans `archived_at` inactif depuis 48 h. */
    public const int UNARCHIVED_ROOM_HOURS = 48;

    /**
     * Le compte de chaque requête, par nom.
     *
     * @return array<string, int>
     */
    public function counts(CarbonImmutable $now): array
    {
        $limits = PlatformLimits::current();

        return [
            'unfinished_game' => Game::query()
                ->whereNull('ended_at')
                ->where('started_at', '<', $now->subHours(self::UNFINISHED_GAME_HOURS))
                ->count(),
            'unarchived_room' => Room::query()
                ->whereNull('archived_at')
                ->where('last_activity_at', '<', $now->subHours(self::UNARCHIVED_ROOM_HOURS))
                ->count(),
            'withdrawn_files' => Frame::query()
                ->where('availability', ContentAvailability::Withdrawn->value)
                ->whereNull('files_deleted_at')
                ->count(),
            'published_without_review' => FrameProbes::publishedWithoutReview()->count(),
            'published_hash_mismatch' => FrameProbes::publishedHashMismatch()->count(),
            'ready_out_of_format' => FrameProbes::readyOutOfFormat()->count(),
            'crop_floor_violations' => FrameProbes::cropFloorViolations($limits)->count(),
        ];
    }

    /**
     * Motifs d'alerte : une entrée par requête non nulle.
     *
     * @return list<string>
     */
    public function failures(CarbonImmutable $now): array
    {
        $failures = [];

        foreach ($this->counts($now) as $name => $count) {
            if ($count > 0) {
                $failures[] = "{$name} : {$count}";
            }
        }

        return $failures;
    }
}
