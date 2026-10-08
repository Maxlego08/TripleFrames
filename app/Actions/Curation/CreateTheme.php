<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Jobs\Catalog\DeriveMovieDifficulty;
use App\Jobs\Catalog\SyncThemeMembership;
use App\Models\Theme;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Admin\ThemeDesignation;
use App\Support\Admin\ThemeKeyGenerator;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Créer un thème, toute nature créable ou sans règle — spec 20 § 9.6, ligne 28
 * de la matrice (`can:create`, `throttle:admin-curation` ; D43 du 01/10).
 *
 * - **Clé** dérivée du libellé anglais par {@see ThemeKeyGenerator}, immuable ;
 *   unique (`theme_key_uq`) : le pré-contrôle sous verrou ET la collision
 *   d'index sont tous deux convertis en refus `admin.themes.key_taken`, jamais
 *   une erreur 500.
 * - **Gardes de désignation** — une collection par thème de saga, une société
 *   par thème studio — relues **sous le verrou de désignation**, dans la
 *   transaction ({@see ThemeDesignation}) : la base n'a aucune contrainte
 *   pour elles (critique C5).
 * - Le thème **naît non publié** ; publier est un geste à part, sous seuil.
 * - `sort_order` facultatif : rangé par défaut en fin de bloc de sa nature
 *   ({@see self::endOfBlock()}, spec 30 § 12.5).
 * - Journal `theme.created` dans la transaction ; **après commit**,
 *   `SyncThemeMembership`, envoyé par `DB::afterCommit()` pour que son verrou
 *   d'unicité ne se prenne qu'une fois l'état visible ; pour une saga,
 *   `DeriveMovieDifficulty` aussi (spec 30 § 14.2, L30-10) : la collection
 *   désignée gagne son bonus de déciles.
 *
 * Un thème ne touche jamais `answer_key` ni l'ambiguïté d'un préfixe (spec 30
 * § 13.3) : rien n'est reprojeté.
 */
final class CreateTheme
{
    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

    /**
     * @param  string|null  $ruleValue  forme canonique de la règle ; `null` = thème manuel
     * @param  array<string, string>  $labels  locale d'interface => libellé rogné, chaque locale activée
     *
     * @throws AuthorizationException le compte a perdu son rôle entre la garde et le verrou
     * @throws ValidationException nature refusée, clé vide ou prise, collection ou société déjà désignée
     * @throws Throwable
     */
    public function handle(
        User $curator,
        ThemeKind $kind,
        ?string $ruleValue,
        bool $negated,
        array $labels,
        ?int $sortOrder = null,
    ): Theme {
        if (! $kind->isCreatable()) {
            throw ValidationException::withMessages([
                'theme_kind' => __('admin.themes.kind_forbidden'),
            ]);
        }

        $key = ThemeKeyGenerator::for($kind, $labels[Locale::English->value] ?? '');

        if ($key === null) {
            throw ValidationException::withMessages([
                'labels.'.Locale::English->value => __('admin.themes.key_invalid'),
            ]);
        }

        try {
            return ThemeDesignation::exclusively(fn (): Theme => DB::transaction(
                function () use ($curator, $kind, $key, $ruleValue, $negated, $labels, $sortOrder): Theme {
                    Gate::forUser($curator)->authorize('create', Theme::class);

                    if (Theme::query()->where('key', $key)->exists()) {
                        throw self::keyTaken($key);
                    }

                    ThemeDesignation::assertFree($kind, $ruleValue);

                    $theme = new Theme([
                        'key' => $key,
                        'theme_kind' => $kind,
                        'rule_value' => $ruleValue,
                        'rule_negated' => $negated,
                        'sort_order' => $sortOrder ?? self::endOfBlock($kind),
                    ]);
                    $theme->is_published = false;
                    $theme->save();

                    foreach (Locale::cases() as $locale) {
                        $theme->labels()->create([
                            'locale' => $locale,
                            'label' => $labels[$locale->value],
                        ]);
                    }

                    $this->journal->record(
                        $curator,
                        AdminActionType::ThemeCreated,
                        $theme->id,
                        details: AdminActionDetails::themeCreated($key, $kind, $ruleValue, $negated, $labels),
                    );

                    $themeId = $theme->id;

                    // Envoyé APRÈS le commit, pas seulement poussé après lui :
                    // le verrou d'unicité du job se prend à l'envoi ; pris
                    // dans la transaction, il pourrait jeter cet envoi pendant
                    // qu'un job déjà en cours lit encore l'état d'avant.
                    $isSaga = $kind === ThemeKind::Saga;

                    DB::afterCommit(static function () use ($themeId, $isSaga): void {
                        SyncThemeMembership::dispatch($themeId);

                        if ($isSaga) {
                            DeriveMovieDifficulty::dispatch();
                        }
                    });

                    return $theme;
                },
            ));
        } catch (UniqueConstraintViolationException) {
            // Une création simultanée sous la même clé a gagné entre le
            // pré-contrôle et l'insertion : refus traduit, jamais une 500.
            throw self::keyTaken($key);
        }
    }

    /**
     * La fin du bloc de la nature dans `sort_order` (spec 30 § 12.5) : un de
     * plus que le dernier thème du bloc, borné au bloc ; premier rang du bloc
     * (`bloc × 100 + 1`) pour un bloc vide.
     */
    public static function endOfBlock(ThemeKind $kind): int
    {
        $first = $kind->sortBlock() * ThemeKind::SORT_BLOCK_WIDTH;
        $last = $first + ThemeKind::SORT_BLOCK_WIDTH - 1;

        $max = Theme::query()
            ->where('theme_kind', $kind)
            ->whereBetween('sort_order', [$first, $last])
            ->max('sort_order');

        return is_numeric($max) ? min($last, (int) $max + 1) : $first + 1;
    }

    private static function keyTaken(string $key): ValidationException
    {
        return ValidationException::withMessages([
            'labels.'.Locale::English->value => __('admin.themes.key_taken', ['key' => $key]),
        ]);
    }
}
