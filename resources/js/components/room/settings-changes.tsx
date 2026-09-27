import { Info } from 'lucide-react';
import { useId } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
import { settingsChangeLines } from '@/lib/room-settings';
import type { SettingsChangeReport } from '@/lib/room-settings';

type SettingsChangesProps = {
    /**
     * Le rapport de la dernière écriture de CE siège (flash
     * `settingsChanges`, ciblé vers l'auteur et jamais diffusé au salon,
     * § 2.6).
     */
    changes: SettingsChangeReport;
};

/**
 * « Réglages ajustés » (spec 50 § 2.6, § 3.2, § 5.3) : ce que le serveur a
 * changé de lui-même en appliquant l'écriture de l'hôte — des paliers
 * réégalisés, un barème personnalisé remis au défaut, une capacité ramenée
 * sous le plafond de plateforme. **Jamais de comportement silencieux** : un
 * réglage que l'écran ne montre pas et que le serveur change est dit ici,
 * champ par champ, dans la langue du joueur (`room.settings.change.<code>`,
 * `:attribute` = libellé traduit du champ).
 *
 * Rien n'est rendu pour un rapport vide. Le rapport vit jusqu'à la prochaine
 * écriture de l'auteur ; la page l'annonce à sa réception. `Alert` au rôle
 * `note`, jamais une région vivante ; tokens du thème seulement.
 */
export function SettingsChanges({ changes }: SettingsChangesProps) {
    const { t } = useTranslations();
    const titleId = useId();
    const lines = settingsChangeLines(changes, t);

    if (lines.length === 0) {
        return null;
    }

    return (
        <Alert role="note" aria-labelledby={titleId}>
            <Info aria-hidden="true" />
            <AlertTitle id={titleId} className="line-clamp-none">
                {t('room.lobby.changes_title')}
            </AlertTitle>
            <AlertDescription className="text-foreground">
                <ul className="flex list-disc flex-col gap-1 ps-5">
                    {lines.map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
