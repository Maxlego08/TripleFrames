<?php

namespace App\ValueObjects\Room;

use App\Enums\RoomRefusal;

/**
 * Issue d'une écriture de réglages par l'hôte (spec 50 § 2.5) : écrite, avec
 * son rapport de changements, ou refusée par une règle de salon.
 *
 * Même patron que `LaunchOutcome` (contrat C6) : un refus de règle
 * (`not_host`, `not_in_lobby`) est une DONNÉE que le contrôleur traduit en
 * réponse, jamais une exception. Une entrée invalide, elle, lève une
 * `ValidationException` : erreurs indexées par champ, dans la langue de l'hôte.
 *
 * `changes` est le rapport ciblé vers l'auteur seul (`settingsChanges`, § 2.6),
 * déjà sous les clés client (`themeKeys`, jamais `themeIds`) : il ne part
 * jamais au salon.
 */
final readonly class SettingsWriteOutcome
{
    /**
     * @param  array<string, string>  $changes  Champ client → code `RoomSettings::CHANGE_*`.
     */
    private function __construct(
        public ?RoomRefusal $refusal,
        public array $changes,
    ) {}

    /**
     * @param  array<string, string>  $changes
     */
    public static function written(array $changes): self
    {
        return new self(null, $changes);
    }

    public static function refused(RoomRefusal $refusal): self
    {
        return new self($refusal, []);
    }

    public function isWritten(): bool
    {
        return $this->refusal === null;
    }
}
