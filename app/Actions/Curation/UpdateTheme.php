<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Jobs\Catalog\DeriveMovieDifficulty;
use App\Jobs\Catalog\SyncThemeMembership;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Admin\ThemeDesignation;
use App\ValueObjects\Admin\AdminActionDetails;
use App\ValueObjects\Admin\ThemeChanges;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Corriger un thème — règle, négation, libellés, ordre — spec 20 § 9.6, ligne
 * 28 de la matrice (`can:update,theme`, `throttle:admin-curation` ; D43 du
 * 01/10). C'est par ce geste que le porteur corrige `studio.disney` vers
 * `2,6125,171656` (spec 30 § 12.3) : aucun seeder ni aucune migration ne
 * réécrit une ligne de thème livrée.
 *
 * - `theme_kind` et `key` ne changent **jamais** : un thème qui changerait de
 *   nature serait un autre thème, que sa clé nommerait faussement.
 * - Les gardes de désignation sont relues sous le verrou de désignation,
 *   comme à la création ({@see ThemeDesignation}).
 * - Journal `theme.updated` avec l'avant et l'après des **seuls** champs
 *   changés ; rien si rien ne change.
 * - **Après commit**, `SyncThemeMembership` **seulement si la règle ou la
 *   négation change** : un libellé ou un ordre seuls ne relancent rien ;
 *   plus `DeriveMovieDifficulty` pour une saga (spec 30 § 14.2, L30-10).
 */
final class UpdateTheme
{
    /** Au moins un champ a changé. */
    public const string UPDATED = 'updated';

    /** L'état demandé est déjà l'état en base : rien n'est écrit. */
    public const string UNCHANGED = 'unchanged';

    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException collection ou société déjà désignée par un autre thème
     * @throws Throwable
     */
    public function handle(User $curator, Theme $theme, ThemeChanges $changes): string
    {
        return ThemeDesignation::exclusively(fn (): string => DB::transaction(
            function () use ($curator, $theme, $changes): string {
                $locked = Theme::query()->whereKey($theme->id)->lockForUpdate()->firstOrFail();

                Gate::forUser($curator)->authorize('update', $locked);

                $locked->load('labels');

                $before = [];
                $after = [];

                if ($locked->rule_value !== $changes->ruleValue) {
                    ThemeDesignation::assertFree($locked->theme_kind, $changes->ruleValue, $locked->id);

                    $before['rule_value'] = $locked->rule_value;
                    $after['rule_value'] = $changes->ruleValue;
                }

                if ($locked->rule_negated !== $changes->negated) {
                    $before['rule_negated'] = $locked->rule_negated;
                    $after['rule_negated'] = $changes->negated;
                }

                if ($locked->sort_order !== $changes->sortOrder) {
                    $before['sort_order'] = $locked->sort_order;
                    $after['sort_order'] = $changes->sortOrder;
                }

                $currentLabels = self::labelsOf($locked);
                $wantedLabels = [];

                foreach (Locale::cases() as $locale) {
                    $wantedLabels[$locale->value] = $changes->labels[$locale->value];
                }

                if ($currentLabels !== $wantedLabels) {
                    $before['labels'] = $currentLabels;
                    $after['labels'] = $wantedLabels;
                }

                if ($after === []) {
                    return self::UNCHANGED;
                }

                $locked->rule_value = $changes->ruleValue;
                $locked->rule_negated = $changes->negated;
                $locked->sort_order = $changes->sortOrder;
                $locked->save();

                if (isset($after['labels'])) {
                    self::writeLabels($locked, $wantedLabels);
                }

                $this->journal->record(
                    $curator,
                    AdminActionType::ThemeUpdated,
                    $locked->id,
                    details: AdminActionDetails::themeUpdated($before, $after),
                );

                if (array_key_exists('rule_value', $after) || array_key_exists('rule_negated', $after)) {
                    $themeId = $locked->id;

                    // Envoyé APRÈS le commit : le verrou d'unicité du job
                    // (`ShouldBeUniqueUntilProcessing`) se prend à l'envoi.
                    // Pris dans la transaction, il jetterait cet envoi si un
                    // job déjà en attente démarrait avant le commit — et ce
                    // job lirait l'ancienne règle : correction perdue (C3).
                    // Une saga qui change de collection déplace son bonus de
                    // déciles (spec 30 § 14.2, L30-10).
                    $isSaga = $locked->theme_kind === ThemeKind::Saga;

                    DB::afterCommit(static function () use ($themeId, $isSaga): void {
                        SyncThemeMembership::dispatch($themeId);

                        if ($isSaga) {
                            DeriveMovieDifficulty::dispatch();
                        }
                    });
                }

                return self::UPDATED;
            },
        ));
    }

    /**
     * Les libellés en base, par locale activée, `null` pour une locale absente.
     *
     * @return array<string, string|null>
     */
    private static function labelsOf(Theme $theme): array
    {
        $labels = [];

        foreach (Locale::cases() as $locale) {
            $labels[$locale->value] = $theme->labels
                ->first(fn (ThemeLabel $label): bool => $label->locale === $locale)
                ?->label;
        }

        return $labels;
    }

    /**
     * Réécrit chaque libellé changé, crée chaque libellé absent.
     *
     * @param  array<string, string>  $labels
     */
    private static function writeLabels(Theme $theme, array $labels): void
    {
        foreach (Locale::cases() as $locale) {
            $existing = $theme->labels->first(fn (ThemeLabel $label): bool => $label->locale === $locale);

            if ($existing === null) {
                $theme->labels()->create(['locale' => $locale, 'label' => $labels[$locale->value]]);
            } elseif ($existing->label !== $labels[$locale->value]) {
                $existing->label = $labels[$locale->value];
                $existing->save();
            }
        }
    }
}
