import { useState } from 'react';
import ThemePublishController from '@/actions/App/Http/Controllers/Admin/ThemePublishController';
import { ConfirmGestureDialog } from '@/components/admin/confirm-gesture-dialog';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import type { AdminTheme, AdminThemePublication } from '@/types/admin';

type Props = {
    /** Le thème à basculer ; `null` ferme la boîte. */
    theme: AdminTheme | null;
    publication: AdminThemePublication;
    onClose: () => void;
    onReturnFocus: () => void;
};

/**
 * Publier ou dépublier un thème — spec 20 § 9.6, route `admin.themes.publish`.
 *
 * La boîte montre le nombre d'œuvres du thème seul et le seuil ; sous le
 * seuil, elle le dit avant l'envoi, mais c'est le serveur qui refuse, mesure
 * relue dans la transaction (`admin.themes.too_small`, sous `is_published`).
 * Le thème des animés japonais rappelle que les films japonais en prise de
 * vue réelle doivent en avoir été retirés d'abord (spec 30 § 12.3, C17) : le
 * seuil d'œuvres ne le garantit pas. Dépublier est toujours permis.
 */
export function ThemePublishDialog({
    theme,
    publication,
    onClose,
    onReturnFocus,
}: Props) {
    const { t, locale } = useTranslations();

    // Le dernier thème montré reste rendu pendant la fermeture : la boîte
    // s'efface sans changer de texte, et Radix rend le focus au déclencheur.
    const [last, setLast] = useState(theme);

    if (theme !== null && theme !== last) {
        setLast(theme);
    }

    const shown = theme ?? last;

    if (shown === null) {
        return null;
    }

    const publish = !shown.is_published;
    const belowThreshold = publish && shown.works < publication.min_works;

    const notices: string[] = [];

    if (publish && shown.publish_notice === 'live_action_japanese') {
        notices.push(t('admin.themes.publish.notice.live_action_japanese'));
    }

    if (belowThreshold) {
        notices.push(
            t('admin.themes.publish.below_threshold', {
                min: formatInteger(publication.min_works, locale),
            }),
        );
    }

    return (
        <ConfirmGestureDialog
            open={theme !== null}
            form={ThemePublishController.store.form(shown.id)}
            title={
                publish
                    ? t('admin.themes.publish.title_publish', {
                          key: shown.key,
                      })
                    : t('admin.themes.publish.title_unpublish', {
                          key: shown.key,
                      })
            }
            description={
                publish
                    ? t('admin.themes.publish.description_publish', {
                          works: formatInteger(shown.works, locale),
                          min: formatInteger(publication.min_works, locale),
                      })
                    : t('admin.themes.publish.description_unpublish')
            }
            notice={notices.length > 0 ? notices.join(' ') : undefined}
            submitLabel={
                publish
                    ? t('admin.themes.publish.submit_publish')
                    : t('admin.themes.publish.submit_unpublish')
            }
            errorFields={['is_published']}
            onClose={onClose}
            onReturnFocus={onReturnFocus}
        >
            <input
                type="hidden"
                name="is_published"
                value={publish ? '1' : '0'}
            />
        </ConfirmGestureDialog>
    );
}
