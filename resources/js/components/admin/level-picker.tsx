import { InfoIcon } from 'lucide-react';
import { useId } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { FrameLevel } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/**
 * L'échelle fermée `frame_level` (enum PHP `FrameLevel`), de la plus
 * cryptique à la plus évidente. Ce n'est pas une valeur de jeu : c'est le
 * domaine d'une colonne, que changer imposerait de reclasser la banque.
 */
export const FRAME_LEVELS = [
    1, 2, 3, 4, 5,
] as const satisfies readonly FrameLevel[];

/**
 * Libellé et guide de chaque niveau (spec 20 § 6.5). Une table de clés
 * littérales, jamais une clé composée à l'exécution : `t()` est typé, et un
 * niveau ajouté sans sa ligne casse `tsc`.
 */
export const FRAME_LEVEL_KEYS: Record<
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
 * Choix compact du niveau d'une image, avec le guide normatif dans
 * l'infobulle de chaque option (spec 20 § 6.4 et § 6.5, lot L20-9a).
 *
 * Groupe de boutons radio standard (`radio-group` de shadcn, Radix) : un seul
 * arrêt de tabulation, les flèches changent le niveau, `Espace` coche. Le
 * niveau se choisit **avant** l'envoi et rien n'est coché d'avance — un
 * défaut serait un classement non décidé ; `required` le dit au navigateur et
 * aux technologies d'assistance.
 *
 * Les cinq options restent sur une ligne : elles partagent la largeur
 * disponible sur grand écran et la ligne défile horizontalement si elle ne
 * tient pas. Chaque guide reste rattaché à son option par
 * `aria-describedby` même lorsqu'il est visuellement rangé dans une
 * infobulle : le lecteur d'écran lit « Niveau 1 — Très cryptique », puis ce
 * que l'image doit montrer. Radix rend, dans un formulaire, un champ natif
 * caché nommé `name` : le `<Form>` d'Inertia soumet donc `frame_level` sans
 * code.
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
        <div className={cn('flex flex-col gap-2', className)}>
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
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
                className="flex w-full flex-nowrap gap-2 overflow-x-auto pb-1"
            >
                {FRAME_LEVELS.map((level) => {
                    const itemId = `${baseId}-level-${level}`;
                    const guideId = `${itemId}-guide`;
                    const keys = FRAME_LEVEL_KEYS[level];

                    return (
                        <div
                            key={level}
                            className="flex min-h-11 min-w-40 flex-1 items-center gap-2 rounded-md border border-border bg-background px-2.5 transition-[border-color,background-color,box-shadow] has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent has-[[data-state=checked]]:shadow-xs"
                        >
                            <RadioGroupItem
                                id={itemId}
                                value={String(level)}
                                aria-describedby={guideId}
                                aria-label={t('admin.level.option', {
                                    level,
                                    label: t(keys.label),
                                })}
                            />
                            <Label
                                htmlFor={itemId}
                                className="flex min-h-11 min-w-0 flex-1 cursor-pointer items-center gap-1.5 text-sm"
                            >
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {level}
                                </span>
                                <span className="truncate">
                                    {t(keys.label)}
                                </span>
                            </Label>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label={t('admin.level.option', {
                                            level,
                                            label: t(keys.label),
                                        })}
                                        aria-describedby={guideId}
                                        className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
                                        disabled={disabled}
                                    >
                                        <InfoIcon
                                            aria-hidden
                                            className="size-4"
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="top"
                                    className="max-w-xs leading-relaxed text-pretty"
                                >
                                    {t(keys.guide)}
                                </TooltipContent>
                            </Tooltip>
                            <span id={guideId} className="sr-only">
                                {t(keys.guide)}
                            </span>
                        </div>
                    );
                })}
            </RadioGroup>

            <AdminInputError id={errorId} message={error} />
        </div>
    );
}
