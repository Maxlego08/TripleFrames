<?php

namespace App\ValueObjects\Game;

use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use App\Support\Draw\PoolReport;
use App\Support\Game\SoloPresets;
use InvalidArgumentException;

/**
 * Les réglages d'une partie solo, tirés d'un preset du site selon D19 du
 * 23/09 (spec 60 § 16.3, contrat C7 § 4.12) — produits par
 * {@see SoloPresets::resolve()} seul.
 *
 * - `settings` : les réglages à jouer — ceux du preset tels quels si le
 *   vivier catalogue tient ses `M` œuvres à son `N`, sinon ceux du preset au
 *   `N` jouable le plus proche, champs dérivés redérivés par la règle Simple
 *   (D34 du 23/09) ; `null` quand aucun `N` n'est jouable (refus
 *   `game.errors.pool_too_small`) ;
 * - `report` : le rapport de vivier **au `N` du preset**, en données
 *   (contrat C2, `PoolReport::toArray()`) — celui qui accompagne un refus ;
 * - `requestedFramesPerRound` : le `N` du preset.
 *
 * Rien, ici, ne quitte le serveur tel quel : la page ne reçoit que
 * {@see self::notice()}, et le rapport n'accompagne qu'un refus.
 */
final readonly class SoloPresetSettings
{
    /**
     * @throws InvalidArgumentException Réglages jouables dont le `N` dépasse
     *                                  celui du preset (D19 ne descend
     *                                  jamais que vers le bas, 30 § 4.4).
     */
    public function __construct(
        public SettingPresetKey $preset,
        public ?RoomSettings $settings,
        public PoolReport $report,
        public int $requestedFramesPerRound,
    ) {
        if ($settings !== null && $settings->framesPerRound > $requestedFramesPerRound) {
            throw new InvalidArgumentException(
                'SoloPresetSettings : le N appliqué ne dépasse jamais le N du preset (30 § 4.4).',
            );
        }
    }

    /** Vrai si un `N` est jouable : la partie peut naître. */
    public function playable(): bool
    {
        return $this->settings !== null;
    }

    /** Vrai si D19 a ramené le `N` du preset au `N` jouable le plus proche. */
    public function adjusted(): bool
    {
        return $this->settings !== null && $this->settings->framesPerRound !== $this->requestedFramesPerRound;
    }

    /**
     * La prop `settingsNotice` de la page `game/solo` (contrat C7 § 4.12) —
     * annoncée par `game.solo.frames_adjusted` —, ou `null` quand le preset
     * se joue tel quel. Des données, jamais une chaîne formatée (règle 4).
     *
     * @return array{preset: string, requestedFramesPerRound: int, appliedFramesPerRound: int}|null
     */
    public function notice(): ?array
    {
        if (! $this->adjusted() || $this->settings === null) {
            return null;
        }

        return [
            'preset' => $this->preset->value,
            'requestedFramesPerRound' => $this->requestedFramesPerRound,
            'appliedFramesPerRound' => $this->settings->framesPerRound,
        ];
    }
}
