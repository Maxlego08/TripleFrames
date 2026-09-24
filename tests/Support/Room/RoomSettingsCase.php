<?php

namespace Tests\Support\Room;

use App\Settings\RoomSettings;

/**
 * Un cas de la matrice des réglages (spec 100 § 4, contrat C18 § 2.7).
 *
 * Il porte une charge postée et son verdict ATTENDU, écrit à la main par
 * {@see RoomSettingsMatrix} à partir des bornes nommées : jamais recalculé par
 * le code qu'il éprouve, sans quoi la matrice prouverait qu'une copie de
 * {@see RoomSettings::validate()} est égale à elle-même.
 */
final readonly class RoomSettingsCase
{
    /**
     * @param  string  $label  Étiquette stable, ex. « N3·D14·R5·points:default ».
     * @param  array<string, mixed>  $input  Charge postée : clés camelCase de
     *                                       {@see RoomSettings::FIELDS} et
     *                                       {@see RoomSettings::INPUT_ROUND_DURATION}.
     * @param  list<string>  $refusedFields  Clés du sac d'erreurs attendues,
     *                                       telles que `validate()` les indexe
     *                                       (`tierPoints.0` pour un palier).
     * @param  list<string>  $warnings  Codes `RoomSettings::WARNING_*` attendus si
     *                                  l'entrée est acceptée ; toujours vide
     *                                  sinon.
     */
    public function __construct(
        public string $label,
        public array $input,
        public array $refusedFields,
        public array $warnings,
    ) {}

    public function accepted(): bool
    {
        return $this->refusedFields === [];
    }
}
