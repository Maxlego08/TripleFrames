import { useId } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { FrameLevel } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/**
 * L'échelle fermée `frame_level` (enum PHP `FrameLevel`), de la plus
 * cryptique à la plus évidente. Ce n'est pas une valeur de jeu : c'est le
 * domaine d'une colonne, que changer imposerait de reclasser la banque.
 */
const FRAME_LEVELS = [1, 2, 3, 4, 5] as const satisfies readonly FrameLevel[];

/**
 * Libellé et guide de chaque niveau (spec 20 § 6.5). Une table de clés
 * littérales, jamais une clé composée à l'exécution : `t()` est typé, et un
 * niveau ajouté sans sa ligne casse `tsc`.
 */
const FRAME_LEVEL_KEYS: Record<
    FrameLevel,
    { label: TranslationKey; guide: TranslationKey }
> = {
    1: { label: 'admin.level.1.label', guide: 'admin.level.1.guide' },
    2: { label: 'admin.level.2.label', guide: 'admin.level.2.guide' },
    3: { label: 'admin.level.3.label', guide: 'admin.level.3.guide' },
    4: { label: 'admin.level.4.label', guide: 'admin.level.4.guide' },
    5: { label: 'admin.level.5.label', guide: 'admin.level.5.guide' },
};

function parseLevel(value: string): FrameLevel | null {
    return FRAME_LEVELS.find((level) => String(level) === value) ?? null;
}

type Props = {
    /** `null` tant que le curateur n'a pas choisi : aucun défaut pré-coché. */
    value: FrameLevel | null;
    onValueChange: (level: FrameLevel) => void;
    /** Nom du champ soumis par le `<Form>` englobant. */
    name?: string;
    /** Message DÉJÀ composé par Laravel, rattaché par `aria-describedby`. */
    error?: string;
    disabled?: boolean;
    className?: string;
};

/**
 * Choix du niveau d'une image, avec le guide normatif à côté de chaque
 * option (spec 20 § 6.4 et § 6.5, lot L20-9a).
 *
 * Groupe de boutons radio standard (`radio-group` de shadcn, Radix) : un seul
 * arrêt de tabulation, les flèches changent le niveau, `Espace` coche. Le
 * niveau se choisit **avant** l'envoi et rien n'est coché d'avance — un
 * défaut serait un classement non décidé ; `required` le dit au navigateur et
 * aux technologies d'assistance.
 *
 * Chaque guide est rattaché à son option par `aria-describedby` : le lecteur
 * d'écran lit « Niveau 1 — Très cryptique », puis ce que l'image doit
 * montrer. Radix rend, dans un formulaire, un champ natif caché nommé
 * `name` : le `<Form>` d'Inertia soumet donc `frame_level` sans code.
 *
 * Aucune couleur ni taille en dur : tokens seulement (règle 5).
 */
export function LevelPicker({
    value,
    onValueChange,
    name = 'frame_level',
    error,
    disabled = false,
    className,
}: Props) {
    const { t } = useTranslations();
    const baseId = useId();
    const legendId = `${baseId}-legend`;
    const hintId = `${baseId}-hint`;
    const errorId = `${baseId}-error`;
    const hasError = error !== undefined && error !== '';

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-col gap-1">
                <p id={legendId} className="text-sm font-medium">
                    {t('admin.level.legend')}
                </p>
                <p id={hintId} className="text-xs text-muted-foreground">
                    {t('admin.level.hint')}
                </p>
            </div>

            <RadioGroup
                name={name}
                value={value === null ? null : String(value)}
                onValueChange={(next) => {
                    const level = parseLevel(next);

                    if (level !== null) {
                        onValueChange(level);
                    }
                }}
                required
                disabled={disabled}
                aria-labelledby={legendId}
                aria-describedby={hasError ? `${hintId} ${errorId}` : hintId}
                aria-invalid={hasError ? true : undefined}
                className="gap-2"
            >
                {FRAME_LEVELS.map((level) => {
                    const itemId = `${baseId}-level-${level}`;
                    const guideId = `${itemId}-guide`;
                    const keys = FRAME_LEVEL_KEYS[level];

                    return (
                        <div
                            key={level}
                            className="flex items-start gap-3 rounded-md border border-border px-3 py-2 has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent"
                        >
                            <RadioGroupItem
                                id={itemId}
                                value={String(level)}
                                aria-describedby={guideId}
                                className="mt-3.5"
                            />
                            <div className="flex flex-1 flex-col gap-1">
                                <Label
                                    htmlFor={itemId}
                                    className="flex min-h-11 cursor-pointer items-center"
                                >
                                    {t('admin.level.option', {
                                        level,
                                        label: t(keys.label),
                                    })}
                                </Label>
                                <p
                                    id={guideId}
                                    className="pb-1 text-xs text-muted-foreground"
                                >
                                    {t(keys.guide)}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </RadioGroup>

            <AdminInputError id={errorId} message={error} />
        </div>
    );
}
