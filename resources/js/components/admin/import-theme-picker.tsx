import { useId } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { THEME_KIND_KEYS } from '@/lib/admin-enum-keys';
import type { AdminImportTheme, ThemeKind } from '@/types/admin';

/** Le nom du champ envoyé à `ImportIdsRequest` (`theme_ids`, spec 20 § 3.3). */
const FIELD_NAME = 'theme_ids[]';

/** L'ordre des groupes : celui des natures dans `THEME_KIND_KEYS`. */
const KIND_ORDER = Object.keys(THEME_KIND_KEYS) as ThemeKind[];

type PickerProps = {
    themes: AdminImportTheme[];
    selected: number[];
    onChange: (selected: number[]) => void;
    /** `catalog.import.paste_max_themes`. */
    max: number;
    disabled: boolean;
    /** Erreur de validation de `theme_ids` ou d'un de ses éléments. */
    error?: string;
};

/**
 * La multi-sélection facultative des thèmes d'un collage — spec 20 § 3.3,
 * D43 du 01/10. Tous les thèmes, publiés ou non, groupés par nature ; au plus
 * `max` cochés : au plafond, les cases restantes se désactivent, et le
 * serveur refuse de toute façon au-delà.
 *
 * Le composant ne poste rien : la sélection est remontée à la carte du
 * collage, qui la réémet en champs cachés par {@see ImportThemeFields} dans
 * les seuls formulaires qui l'affichent aussi par {@see ImportThemeRecap}
 * (critique C8).
 */
export function ImportThemePicker({
    themes,
    selected,
    onChange,
    max,
    disabled,
    error,
}: PickerProps) {
    const { t } = useTranslations();
    const baseId = useId();
    const atLimit = selected.length >= max;

    if (themes.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('admin.import.themes.empty')}
            </p>
        );
    }

    const toggle = (id: number, checked: boolean): void => {
        if (checked) {
            if (!selected.includes(id) && selected.length < max) {
                onChange([...selected, id]);
            }

            return;
        }

        onChange(selected.filter((value) => value !== id));
    };

    return (
        <fieldset className="space-y-3" aria-describedby={`${baseId}-help`}>
            <legend className="text-sm font-medium">
                {t('admin.import.themes.heading')}
            </legend>
            <p
                id={`${baseId}-help`}
                className="max-w-prose text-xs text-muted-foreground"
            >
                {t('admin.import.themes.help')}
            </p>

            {KIND_ORDER.map((kind) => {
                const group = themes.filter((theme) => theme.kind === kind);

                if (group.length === 0) {
                    return null;
                }

                return (
                    <div key={kind} className="space-y-1.5">
                        <p className="text-xs font-medium text-muted-foreground">
                            {t(THEME_KIND_KEYS[kind])}
                        </p>
                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                            {group.map((theme) => {
                                const id = `${baseId}-theme-${theme.id}`;
                                const checked = selected.includes(theme.id);

                                return (
                                    <div
                                        key={theme.id}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={id}
                                            checked={checked}
                                            disabled={
                                                disabled ||
                                                (!checked && atLimit)
                                            }
                                            onCheckedChange={(value) =>
                                                toggle(theme.id, value === true)
                                            }
                                        />
                                        <Label
                                            htmlFor={id}
                                            className="font-normal"
                                        >
                                            {theme.label}
                                        </Label>
                                        {!theme.is_published && (
                                            <Badge variant="outline">
                                                {t(
                                                    'admin.import.themes.unpublished',
                                                )}
                                            </Badge>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            })}

            <div className="flex flex-wrap items-center gap-3">
                <p role="status" className="text-xs text-muted-foreground">
                    {t('admin.import.themes.selected', {
                        count: selected.length,
                        max,
                    })}
                </p>
                {selected.length > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={() => onChange([])}
                    >
                        {t('admin.import.themes.clear')}
                    </Button>
                )}
            </div>

            {atLimit && (
                <p className="text-xs text-muted-foreground">
                    {t('admin.import.themes.too_many', { max })}
                </p>
            )}

            <AdminInputError message={error} />
        </fieldset>
    );
}

type RecapProps = {
    themes: AdminImportTheme[];
    selected: number[];
};

/**
 * Le récapitulatif des thèmes qu'un formulaire va appliquer — affiché par
 * CHAQUE formulaire qui les porte, pour qu'aucun n'applique une sélection
 * que le curateur ne voit pas (critique C8).
 */
export function ImportThemeRecap({ themes, selected }: RecapProps) {
    const { t } = useTranslations();
    const chosen = selectedThemes(themes, selected);

    return (
        <p className="text-sm text-muted-foreground">
            {chosen.length === 0
                ? t('admin.import.themes.none')
                : t('admin.import.themes.recap', {
                      themes: chosen
                          .map((theme) => theme.label)
                          .join(t('admin.import.themes.separator')),
                  })}
        </p>
    );
}

/** La sélection réémise en champs cachés, dans l'ordre de la sélection. */
export function ImportThemeFields({ selected }: { selected: number[] }) {
    return (
        <>
            {selected.map((id) => (
                <input key={id} type="hidden" name={FIELD_NAME} value={id} />
            ))}
        </>
    );
}

/**
 * La première erreur de validation de la sélection : celle du tableau, ou
 * celle d'un de ses éléments (`theme_ids.0`…).
 */
export function importThemeError(
    errors: Record<string, string | undefined>,
): string | undefined {
    if (errors.theme_ids !== undefined) {
        return errors.theme_ids;
    }

    const key = Object.keys(errors).find((name) =>
        name.startsWith('theme_ids.'),
    );

    return key === undefined ? undefined : errors[key];
}

/** Les thèmes cochés, dans l'ordre de la sélection ; un id inconnu est ignoré. */
function selectedThemes(
    themes: AdminImportTheme[],
    selected: number[],
): AdminImportTheme[] {
    const chosen: AdminImportTheme[] = [];

    for (const id of selected) {
        const theme = themes.find((candidate) => candidate.id === id);

        if (theme !== undefined) {
            chosen.push(theme);
        }
    }

    return chosen;
}
