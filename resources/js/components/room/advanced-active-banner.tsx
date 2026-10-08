import { SlidersHorizontal } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
import { SETTING_LABEL_KEYS } from '@/lib/room-settings';
import type { RoomSettingsFieldKey } from '@/types/room-settings';

type AdvancedActiveBannerProps = {
    /**
     * Les réglages propres à l'onglet Avancé qui s'écartent de leur défaut
     * dérivé, dans l'ordre des champs : `advancedActive` de l'état diffusé
     * (`RoomSettingsEditor::customizedAdvancedFields()`).
     */
    fields: RoomSettingsFieldKey[];
};

/**
 * Bandeau de l'onglet Simple quand des réglages avancés restent actifs
 * (spec 50 § 3.3, lot L50-10) : `room.settings.advanced_active`, puis le
 * libellé de chacun (§ 20.1). Repasser en Simple ne réinitialise que les
 * paliers : l'hôte, et les autres sièges, voient ce qui reste réglé à la
 * main au lieu de le découvrir en partie.
 *
 * Rien quand la liste est vide. `Alert` au rôle `note` : aucune région
 * vivante ici, l'annonceur de la coquille reste la seule qui parle. Icône
 * et texte, jamais la seule couleur ; tokens du thème seulement.
 */
export function AdvancedActiveBanner({ fields }: AdvancedActiveBannerProps) {
    const { t } = useTranslations();

    if (fields.length === 0) {
        return null;
    }

    return (
        <Alert role="note">
            <SlidersHorizontal aria-hidden="true" />
            <AlertDescription className="text-foreground">
                <p>{t('room.settings.advanced_active')}</p>
                <ul className="list-disc ps-5">
                    {fields.map((field) => (
                        <li key={field}>{t(SETTING_LABEL_KEYS[field])}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
