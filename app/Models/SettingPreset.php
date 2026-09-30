<?php

namespace App\Models;

use App\Casts\RoomSettingsCast;
use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use Carbon\CarbonImmutable;
use Database\Factories\SettingPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un preset de réglages livré par le site (§ 6.3).
 *
 * **Aucune clé étrangère : un preset n'a pas de propriétaire, et c'est ce qui le rend
 * accessible à un invité.** Aucune relation, ni dans un sens ni dans l'autre.
 *
 * **Aucune colonne de libellé et aucune table de libellé par locale** : contrairement
 * aux `theme_label`, éditables en back-office, un preset est du contenu livré avec le
 * code — nom et description sont des clés de `lang/`, rendues par
 * {@see SettingPresetKey::labelKey()} et {@see SettingPresetKey::descriptionKey()}.
 *
 * **Aucune route d'écriture, aucune policy accordant `update` ou `delete`, y compris à
 * un admin** : le seeder idempotent, qui réconcilie par `key`, est le seul écrivain.
 * Le grisage d'un preset au vivier insuffisant est calculé au rendu, jamais stocké.
 *
 * `position` fixe l'ordre d'affichage côté site, pour ne pas dépendre d'un tri
 * alphabétique qui change de langue en langue. Aucun index : quatre lignes.
 *
 * @property int $id
 * @property SettingPresetKey $key
 * @property int $position
 * @property RoomSettings $settings
 * @property int $settings_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('setting_preset')]
// Le seeder est le seul écrivain et aucune route ne poste ici : les trois colonnes
// qu'il réconcilie sont assignables, `settings_version` exceptée — elle est écrite par
// RoomSettingsCast::set() en même temps que la charge utile, jamais séparément.
#[Fillable(['key', 'position', 'settings'])]
class SettingPreset extends Model
{
    /** @use HasFactory<SettingPresetFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => SettingPresetKey::class,
            'position' => 'integer',
            'settings' => RoomSettingsCast::class,
            'settings_version' => 'integer',
        ];
    }
}
