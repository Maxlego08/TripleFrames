<?php

namespace App\ValueObjects\Game;

use App\Actions\Game\StartSoloGame;
use App\Enums\SoloRefusal;
use App\Models\Game;
use App\Models\Player;
use App\Support\Draw\PoolReport;
use LogicException;

/**
 * Issue d'un démarrage solo ({@see StartSoloGame}, spec 60 § 16.2) : partie
 * née, sur le siège solo pris ou repris, avec l'annonce D19 éventuelle — ou
 * refus de règle, avec le rapport de vivier d'un refus `pool_too_small`.
 *
 * Rien, ici, ne quitte le serveur tel quel : ni la partie, ni le siège, ni un
 * identifiant interne. Le contrôleur n'en rend que `notice` (prop
 * `settingsNotice`, contrat C7 § 4.12), le message traduit d'un refus et le
 * rapport `PoolReport::toArray()`.
 */
final readonly class SoloStartOutcome
{
    /**
     * @param  array{preset: string, requestedFramesPerRound: int, appliedFramesPerRound: int}|null  $notice
     */
    private function __construct(
        public ?Game $game,
        public ?Player $seat,
        public ?array $notice,
        public ?SoloRefusal $refusal,
        public ?PoolReport $pool,
    ) {}

    /**
     * @param  array{preset: string, requestedFramesPerRound: int, appliedFramesPerRound: int}|null  $notice
     */
    public static function started(Game $game, Player $seat, ?array $notice): self
    {
        return new self($game, $seat, $notice, null, null);
    }

    public static function refused(SoloRefusal $refusal, ?PoolReport $pool = null): self
    {
        return new self(null, null, null, $refusal, $pool);
    }

    public function isStarted(): bool
    {
        return $this->refusal === null;
    }

    /**
     * Le refus, pour un démarrage refusé.
     *
     * @throws LogicException L'issue est une partie née.
     */
    public function refusal(): SoloRefusal
    {
        return $this->refusal ?? throw new LogicException('SoloStartOutcome : une partie née n’est pas un refus.');
    }
}
