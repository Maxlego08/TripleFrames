<?php

namespace App\Models;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Database\Factories\ThemeLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le libellé d'un thème dans une locale d'interface (§ 3.7).
 *
 * **`theme_label.locale` est une locale d'INTERFACE**, `string(5)` castée par
 * {@see Locale} — la seule des trois familles de contenu traduit en base
 * (`movie_title`, `alias`, `theme_label`) dans ce cas (§ 1.3). Son contenu est
 * une chaîne d'interface éditable, affichée au joueur et obligatoire dans chaque
 * locale activée pour publier le thème, pas une donnée de catalogue : un
 * `theme_label` en `ja` n'existera jamais. Non castée, la colonne accepterait
 * silencieusement `frn` ou `en-GB` et le thème resterait éternellement non
 * publiable sans message.
 *
 * Ne jamais copier ce cast sur `movie_title.locale`, `alias.locale` ni
 * `answer_key.source_locale` : ces trois-là sont `string(12)`, jamais castées,
 * et doivent accepter `ja`, `ko`, `zh-Hant`.
 *
 * `theme_id` n'est pas mass-assignable : un libellé se crée par
 * `$theme->labels()->create([...])`, qui pose lui-même la clé étrangère.
 *
 * @property int $id
 * @property int $theme_id
 * @property Locale $locale
 * @property string $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Theme $theme
 */
#[Table('theme_label')]
#[Fillable(['locale', 'label'])]
class ThemeLabel extends Model
{
    /** @use HasFactory<ThemeLabelFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Theme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
        ];
    }
}
