<?php

namespace App\ValueObjects\Room;

use App\Enums\RoomRefusal;
use App\Models\Game;
use App\Support\Draw\PoolReport;

/**
 * Issue d'un lancement de partie (spec 50 § 12.1, contrat C6 § 2) : lancée,
 * avec sa partie, ou refusée par une règle de salon.
 *
 * Un refus de règle est une DONNÉE, jamais une exception : l'action le rend,
 * le contrôleur le traduit en réponse dans la langue de la requête (§ 12.5).
 * Un échec technique (tirage, matérialisation, programmation, base) n'en est
 * pas un : il lève, la transaction est annulée, et {@see RoomRefusal} n'est
 * jamais étendu pour lui.
 *
 * - `replace` : les paramètres du message de refus, des entiers seulement
 *   (`:min` de `not_enough_players`, `:playable` et `:required` de
 *   `pool_insufficient`) ;
 * - `pool` : le rapport de vivier du refus `pool_insufficient`, en données
 *   (contrat C2, R-11) ;
 * - `changes` : le rapport de normalisation du refus `settings_outdated`,
 *   déjà sous les clés client (`themeKeys`, jamais `themeIds`), ciblé vers
 *   l'auteur seul (§ 2.6).
 *
 * Rien, ici, ne quitte le serveur tel quel : la partie ne sert qu'au
 * contrôleur et aux tests, et aucune réponse ne porte ni la graine, ni un
 * film, ni `game.id`, ni `draw_pool_size` (§ 12.5).
 */
final readonly class LaunchOutcome
{
    /**
     * @param  array<string, int>  $replace  Paramètres du message de refus.
     * @param  array<string, string>  $changes  Champ client → code `RoomSettings::CHANGE_*`.
     */
    private function __construct(
        public ?Game $game,
        public ?RoomRefusal $refusal,
        public array $replace,
        public ?PoolReport $pool,
        public array $changes,
    ) {}

    public static function launched(Game $game): self
    {
        return new self($game, null, [], null, []);
    }

    /**
     * @param  array<string, int>  $replace
     * @param  array<string, string>  $changes
     */
    public static function refused(RoomRefusal $r, array $replace = [], ?PoolReport $pool = null, array $changes = []): self
    {
        return new self(null, $r, $replace, $pool, $changes);
    }

    public function isLaunched(): bool
    {
        return $this->refusal === null;
    }
}
