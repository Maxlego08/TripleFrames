import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import ThemeController from '@/actions/App/Http/Controllers/Admin/ThemeController';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminSelect } from '@/components/admin/admin-select';
import { ThemeRulePicker } from '@/components/admin/theme-rule-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { THEME_KIND_KEYS } from '@/lib/admin-enum-keys';
import type {
    AdminTheme,
    AdminThemePrefill,
    CreatableThemeKind,
    ThemeKind,
} from '@/types/admin';

/** La largeur de `theme_label.label` (spec 10 § 3.7). */
const LABEL_MAX_LENGTH = 80;

/** La largeur de `theme.key` (`ThemeKeyGenerator::MAX_LENGTH`). */
const KEY_MAX_LENGTH = 64;

/** Les deux locales d'interface activées, dans l'ordre du formulaire. */
const LABEL_LOCALES = ['fr', 'en'] as const;

/** La borne de `theme.sort_order`, un `unsignedSmallInteger`. */
const SORT_ORDER_MAX = 65_535;

export type ThemeFormTarget =
    | { mode: 'create'; prefill: AdminThemePrefill | null }
    | { mode: 'edit'; theme: AdminTheme };

type Props = {
    /** La création ou le thème corrigé ; `null` ferme la boîte. */
    target: ThemeFormTarget | null;
    /** Les natures créables, relues du serveur. */
    kinds: CreatableThemeKind[];
    onClose: () => void;
    onReturnFocus: () => void;
};

/**
 * Créer ou corriger un thème — spec 20 § 9.6 (D43 du 01/10).
 *
 * - **Création** : nature parmi les cinq créables, ou « sans règle » (thème
 *   manuel) ; la valeur de la règle se choisit parmi ce qui est présent au
 *   catalogue ; la clé est dérivée du libellé anglais, montrée en aperçu, et
 *   ne changera plus. Le thème naît non publié.
 * - **Correction** : la nature et la clé sont affichées, jamais envoyées ;
 *   règle, négation, libellés et ordre sont envoyés en état complet.
 *
 * Refus du serveur sous chaque champ ; la boîte reste ouverte. Fermeture
 * générée masquée et remplacée (spec 20 § 13.4).
 */
export function ThemeFormDialog({
    target,
    kinds,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
                className="max-h-[90dvh] overflow-y-auto sm:max-w-xl [&>button:last-child]:hidden"
            >
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('admin.a11y.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden />
                    </Button>
                </DialogClose>

                {target !== null && (
                    <ThemeForm target={target} kinds={kinds} onDone={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * L'aperçu de la clé : `<nature>.` puis le libellé anglais en minuscules
 * ASCII séparées par des tirets, article initial retiré — l'approximation
 * de `ThemeKeyGenerator` (Str::slug), qui reste seul juge.
 */
function keyPreview(kind: ThemeKind, englishLabel: string): string | null {
    const slug = englishLabel
        .trim()
        .replace(/^(?:the|an?)\s+/i, '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    if (slug === '') {
        return null;
    }

    return `${kind}.${slug}`.slice(0, KEY_MAX_LENGTH).replace(/-+$/, '');
}

/** Le formulaire, remonté à chaque ouverture : une saisie abandonnée ne revient jamais. */
function ThemeForm({
    target,
    kinds,
    onDone,
}: {
    target: ThemeFormTarget;
    kinds: CreatableThemeKind[];
    onDone: () => void;
}) {
    const { t } = useTranslations();
    const kindId = useId();
    const kindErrorId = useId();
    const manualId = useId();
    const manualHintId = useId();
    const negatedId = useId();
    const negatedHintId = useId();
    const negatedErrorId = useId();
    const sortOrderId = useId();
    const sortOrderHintId = useId();
    const sortOrderErrorId = useId();
    const labelIds = { fr: useId(), en: useId() };

    const theme = target.mode === 'edit' ? target.theme : null;
    const prefill = target.mode === 'create' ? target.prefill : null;

    const [kind, setKind] = useState<ThemeKind>(
        theme?.kind ?? prefill?.kind ?? kinds[0] ?? 'genre',
    );
    const [manual, setManual] = useState(theme?.manual ?? false);
    const [negated, setNegated] = useState(theme?.negated ?? false);
    const [englishLabel, setEnglishLabel] = useState(theme?.labels.en ?? '');

    // La négation n'existe ni pour une saga ni pour un thème sans règle.
    const negatable = kind !== 'saga' && !manual;
    const preview = keyPreview(kind, englishLabel);

    const form =
        theme === null
            ? ThemeController.store.form()
            : ThemeController.update.form(theme.id);

    const initialValue =
        theme !== null
            ? kind === 'saga'
                ? theme.collection_id === null
                    ? null
                    : String(theme.collection_id)
                : theme.rule_value
            : prefill !== null
              ? String(prefill.collection_id)
              : null;

    const initialName =
        theme?.rule_items[0]?.name ?? prefill?.collection_name ?? null;

    return (
        <Form
            {...form}
            noValidate
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => {
                const fieldErrors = errors as Partial<Record<string, string>>;

                return (
                    <>
                        <DialogHeader className="pr-12">
                            <DialogTitle>
                                {theme === null
                                    ? t('admin.themes.form.create_title')
                                    : t('admin.themes.form.edit_title', {
                                          key: theme.key,
                                      })}
                            </DialogTitle>
                            <DialogDescription>
                                {theme === null
                                    ? t('admin.themes.form.create_description')
                                    : t('admin.themes.form.edit_description')}
                            </DialogDescription>
                        </DialogHeader>

                        {prefill !== null && (
                            <Alert>
                                <AlertDescription>
                                    {t('admin.themes.form.prefill', {
                                        name: prefill.collection_name,
                                    })}
                                </AlertDescription>
                            </Alert>
                        )}

                        {theme === null ? (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor={kindId}>
                                    {t('admin.themes.form.kind')}
                                </Label>
                                <AdminSelect
                                    id={kindId}
                                    name="theme_kind"
                                    value={kind}
                                    onChange={(event) => {
                                        setKind(
                                            event.target.value as ThemeKind,
                                        );
                                        setNegated(false);
                                    }}
                                    options={kinds.map((value) => ({
                                        value,
                                        label: t(THEME_KIND_KEYS[value]),
                                    }))}
                                    aria-invalid={
                                        fieldErrors.theme_kind
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={kindErrorId}
                                    className="min-h-11"
                                />
                                <AdminInputError
                                    id={kindErrorId}
                                    message={fieldErrors.theme_kind}
                                />
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.themes.form.kind_fixed', {
                                    kind: t(THEME_KIND_KEYS[theme.kind]),
                                })}
                            </p>
                        )}

                        <div className="flex flex-col gap-1.5">
                            <input
                                type="hidden"
                                name="manual"
                                value={manual ? '1' : '0'}
                            />
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={manualId}
                                    checked={manual}
                                    aria-describedby={manualHintId}
                                    onCheckedChange={(checked) => {
                                        setManual(checked === true);
                                        setNegated(false);
                                    }}
                                />
                                <Label htmlFor={manualId}>
                                    {t('admin.themes.form.manual')}
                                </Label>
                            </div>
                            <p
                                id={manualHintId}
                                className="text-sm text-muted-foreground"
                            >
                                {t('admin.themes.form.manual_help')}
                            </p>
                        </div>

                        {!manual && (
                            <ThemeRulePicker
                                key={kind}
                                kind={kind}
                                initialValue={
                                    kind === (theme?.kind ?? prefill?.kind)
                                        ? initialValue
                                        : null
                                }
                                initialCompanyIds={theme?.company_ids ?? []}
                                initialName={initialName}
                                errors={fieldErrors}
                            />
                        )}

                        {negatable && (
                            <div className="flex flex-col gap-1.5">
                                <input
                                    type="hidden"
                                    name="rule_negated"
                                    value={negated ? '1' : '0'}
                                />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={negatedId}
                                        checked={negated}
                                        aria-describedby={`${negatedHintId} ${negatedErrorId}`}
                                        onCheckedChange={(checked) =>
                                            setNegated(checked === true)
                                        }
                                    />
                                    <Label htmlFor={negatedId}>
                                        {t('admin.themes.form.negated')}
                                    </Label>
                                </div>
                                <p
                                    id={negatedHintId}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.themes.form.negated_help')}
                                </p>
                                <AdminInputError
                                    id={negatedErrorId}
                                    message={fieldErrors.rule_negated}
                                />
                            </div>
                        )}

                        {LABEL_LOCALES.map((locale) => (
                            <div key={locale} className="flex flex-col gap-1.5">
                                <Label htmlFor={labelIds[locale]}>
                                    {locale === 'fr'
                                        ? t('admin.themes.form.label_fr')
                                        : t('admin.themes.form.label_en')}
                                </Label>
                                <Input
                                    id={labelIds[locale]}
                                    name={`labels[${locale}]`}
                                    defaultValue={theme?.labels[locale] ?? ''}
                                    maxLength={LABEL_MAX_LENGTH}
                                    autoComplete="off"
                                    aria-required="true"
                                    aria-invalid={
                                        fieldErrors[`labels.${locale}`]
                                            ? true
                                            : undefined
                                    }
                                    onChange={
                                        locale === 'en'
                                            ? (event) =>
                                                  setEnglishLabel(
                                                      event.target.value,
                                                  )
                                            : undefined
                                    }
                                    className="min-h-11"
                                />
                                <AdminInputError
                                    message={fieldErrors[`labels.${locale}`]}
                                />
                            </div>
                        ))}

                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {theme !== null
                                ? t('admin.themes.form.key_fixed', {
                                      key: theme.key,
                                  })
                                : preview === null
                                  ? t('admin.themes.form.key_pending')
                                  : t('admin.themes.form.key_preview', {
                                        key: preview,
                                    })}
                        </p>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={sortOrderId}>
                                {t('admin.themes.form.sort_order')}
                            </Label>
                            <Input
                                id={sortOrderId}
                                name="sort_order"
                                type="number"
                                min={0}
                                max={SORT_ORDER_MAX}
                                defaultValue={theme?.sort_order ?? ''}
                                required={theme !== null}
                                aria-invalid={
                                    fieldErrors.sort_order ? true : undefined
                                }
                                aria-describedby={`${sortOrderHintId} ${sortOrderErrorId}`}
                                className="min-h-11"
                            />
                            {theme === null && (
                                <p
                                    id={sortOrderHintId}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.themes.form.sort_order_help')}
                                </p>
                            )}
                            <AdminInputError
                                id={sortOrderErrorId}
                                message={fieldErrors.sort_order}
                            />
                        </div>

                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    {t('admin.common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                aria-disabled={processing || undefined}
                                aria-busy={processing || undefined}
                                onClick={(event) => {
                                    if (processing) {
                                        event.preventDefault();
                                    }
                                }}
                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {theme === null
                                    ? t('admin.themes.form.submit_create')
                                    : t('admin.themes.form.submit_update')}
                            </Button>
                        </DialogFooter>
                    </>
                );
            }}
        </Form>
    );
}
