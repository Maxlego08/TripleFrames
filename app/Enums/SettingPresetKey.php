<?php

namespace App\Enums;

/** Les quatre presets livrés avec le code, dont le seeder idempotent est le seul écrivain : cast de `setting_preset.key`. */
enum SettingPresetKey: string
{
    case Classic = 'classic';

    case Fast = 'fast';

    case Hardcore = 'hardcore';

    case Discovery = 'discovery';

    /** Un preset n'a aucune colonne de libellé : son nom est une clé du domaine `room` de `lang/`. */
    public function labelKey(): string
    {
        return 'room.presets.'.$this->value.'.label';
    }

    /** Idem pour la description affichée sous le nom dans le sélecteur de preset. */
    public function descriptionKey(): string
    {
        return 'room.presets.'.$this->value.'.description';
    }
}
