import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { cn } from '@/lib/utils';

type Props = {
    /** Texte DÉJÀ traduit. */
    label: string;
    value: number;
    /** Unité ou précision, déjà traduite : « films », « variantes »… */
    hint?: string;
    /** Un badge, une pastille de niveaux… */
    trailing?: ReactNode;
    className?: string;
};

/**
 * Une tuile de mesure : un libellé, un nombre, éventuellement une précision.
 *
 * Le nombre est mis en forme **ici, côté client** : `Number::format` lève une
 * `RuntimeException` dans cet environnement (ni `intl`, ni `gd`, ni `exif`), et
 * la règle 4 interdit de toute façon qu'une chaîne pré-formatée parte du
 * serveur. Les contrôleurs expédient des entiers, et c'est très bien ainsi.
 *
 * Zéro s'affiche comme zéro. Aujourd'hui toute la couverture d'images vaut
 * zéro, et **c'est la vérité à afficher**, pas un chiffre inventé ni une tuile
 * escamotée — une tuile absente serait un trou, une tuile à zéro est une
 * information.
 */
export function AdminStatTile({
    label,
    value,
    hint,
    trailing,
    className,
}: Props) {
    const { locale } = useTranslations();

    return (
        <div
            className={cn(
                'flex flex-col gap-1 rounded-lg border border-border bg-card p-4',
                className,
            )}
        >
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <span className="text-2xl font-semibold text-card-foreground tabular-nums">
                {formatInteger(value, locale)}
            </span>
            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
            {trailing}
        </div>
    );
}

/**
 * Les cinq niveaux d'un masque, rendus en pastilles.
 *
 * La couverture se lit `levels_mask & 21 = 21` côté serveur ; ici on ne
 * fait que **montrer** quels niveaux sont pourvus, un par un — c'est ce qu'un
 * curateur cherche quand il se demande pourquoi un film n'est pas publiable.
 */
export function AdminLevelDots({ levels }: { levels: boolean[] }) {
    const { t } = useTranslations();

    return (
        <ul className="flex flex-wrap items-center gap-1">
            {levels.map((covered, index) => (
                <li
                    key={index}
                    className={cn(
                        'rounded-sm border px-1.5 py-0.5 text-xs font-medium tabular-nums',
                        covered
                            ? 'border-transparent bg-primary text-primary-foreground'
                            : 'border-border bg-muted text-muted-foreground',
                    )}
                >
                    <span className="sr-only">
                        {t('admin.common.label_value', {
                            label: t('admin.dashboard.coverage.level', {
                                level: index + 1,
                            }),
                            value: covered
                                ? t('admin.common.yes')
                                : t('admin.common.no'),
                        })}
                    </span>
                    <span aria-hidden>{index + 1}</span>
                </li>
            ))}
        </ul>
    );
}
