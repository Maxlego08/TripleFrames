import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
import type {
    RoomSettingsBoundsPayload,
    RoomSettingsWarningCode,
} from '@/types/room-settings';
import type { TranslationKey } from '@/types/translations';

type SettingsWarningsProps = {
    /**
     * Les codes à rendre, dans l'ordre du serveur : ceux de l'état diffusé
     * (`settings.warnings`), ou, pendant un geste de l'hôte, ceux que le
     * client dérive du réglage en cours (`lib/room-settings.ts`).
     */
    warnings: RoomSettingsWarningCode[];
    /** `bounds.warningThresholds` : les seuils, placeholders `:seconds`. */
    thresholds: RoomSettingsBoundsPayload['warningThresholds'];
};

/** Une clé par cas de `RoomSettingsWarningCode` (§ 20.2). */
const WARNING_KEYS: Record<RoomSettingsWarningCode, TranslationKey> = {
    short_reveal: 'room.warnings.short_reveal',
    long_round: 'room.warnings.long_round',
    non_decreasing_points: 'room.warnings.non_decreasing_points',
    all_tiers_zero: 'room.warnings.all_tiers_zero',
};

/**
 * Avertissements des réglages — bornes croisées 4 et 5 (spec 50 § 4.1 et
 * § 4.5) : révélation courte, manche longue, barème non strictement
 * décroissant, barème entièrement à zéro. **Jamais bloquants** : 3 s de
 * révélation restent légales, les avertir suffit.
 *
 * Diffusés en DONNÉES au salon (codes), rendus par chaque client dans sa
 * propre langue (§ 4.2) : l'hôte les voit comme les autres sièges. Les
 * seuils ne sont jamais écrits ici : ils arrivent dans les bornes
 * (`warningThresholds`), et `non_decreasing_points` comme `all_tiers_zero`
 * n'en ont pas.
 *
 * `Alert` au rôle `note` : aucune région vivante ici, l'annonceur de la
 * coquille reste la seule qui parle (C16 § 4). Icône et texte, jamais la
 * seule couleur ; tokens du thème seulement.
 */
export function SettingsWarnings({
    warnings,
    thresholds,
}: SettingsWarningsProps) {
    const { t, locale } = useTranslations();

    if (warnings.length === 0) {
        return null;
    }

    const number = new Intl.NumberFormat(locale);
    const message = (code: RoomSettingsWarningCode): string => {
        switch (code) {
            case 'short_reveal':
                return t(WARNING_KEYS[code], {
                    seconds: number.format(
                        thresholds.recommendedMinRevealDuration,
                    ),
                });
            case 'long_round':
                return t(WARNING_KEYS[code], {
                    seconds: number.format(thresholds.longRoundWarningDuration),
                });
            case 'non_decreasing_points':
            case 'all_tiers_zero':
                return t(WARNING_KEYS[code]);
        }
    };

    return (
        <Alert role="note">
            <TriangleAlert aria-hidden="true" />
            <AlertDescription className="text-foreground">
                <ul className="flex flex-col gap-1">
                    {warnings.map((code) => (
                        <li key={code}>{message(code)}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
