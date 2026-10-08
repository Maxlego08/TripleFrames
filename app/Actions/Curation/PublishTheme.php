<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Models\Theme;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Draw\PoolReporter;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publier ou dépublier un thème — spec 20 § 9.6, ligne 28 de la matrice
 * (`can:publish,theme`, `throttle:admin-curation` ; D43 du 01/10).
 *
 * Publier est un **basculement de drapeau**, sans recalcul (spec 30 § 13.1),
 * refusé en erreur traduite — jamais un 403 — seulement sans libellé dans
 * chaque locale activée (`admin.themes.labels_missing`).
 *
 * **Aucun seuil d'œuvres** (D65 du 07/10, révise D43 du 01/10) : un thème
 * sous `RoomSettingsBounds::DEFAULT_ROUNDS_COUNT` œuvres se publie ; l'écran
 * l'avertit seulement. Un salon qui le choisirait seul reste protégé par le
 * blocage du lancement (`PoolReport::blocked()`, spec 30 § 4.2). Le nombre
 * d'œuvres ({@see PoolReporter::themeWorks()}) est relu dans la transaction
 * pour le journal.
 *
 * **Dépublier reste toujours permis**, quel que soit le compte. Journal
 * `theme.published` / `theme.unpublished` avec le nombre d'œuvres ; une
 * bascule vers l'état déjà en place n'écrit rien.
 */
final class PublishTheme
{
    public const string PUBLISHED = 'published';

    public const string UNPUBLISHED = 'unpublished';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        private readonly AdminJournal $journal,
        private readonly PoolReporter $pool,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException libellé manquant
     * @throws Throwable
     */
    public function handle(User $curator, Theme $theme, bool $publish): string
    {
        return DB::transaction(function () use ($curator, $theme, $publish): string {
            $locked = Theme::query()->whereKey($theme->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('publish', $locked);

            if ($locked->is_published === $publish) {
                return self::UNCHANGED;
            }

            $works = $this->pool->themeWorks($locked->id);

            if ($publish) {
                if (! $locked->load('labels')->hasEveryLocaleLabel()) {
                    throw ValidationException::withMessages([
                        'is_published' => __('admin.themes.labels_missing'),
                    ]);
                }

            }

            $locked->is_published = $publish;
            $locked->save();

            $this->journal->record(
                $curator,
                $publish ? AdminActionType::ThemePublished : AdminActionType::ThemeUnpublished,
                $locked->id,
                details: AdminActionDetails::themePublication($works),
            );

            return $publish ? self::PUBLISHED : self::UNPUBLISHED;
        });
    }
}
