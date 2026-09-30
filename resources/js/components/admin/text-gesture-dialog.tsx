import { Form, router } from '@inertiajs/react';
import { SearchCheckIcon, XIcon } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import MovieAliasController from '@/actions/App/Http/Controllers/Admin/MovieAliasController';
import MovieTitleController from '@/actions/App/Http/Controllers/Admin/MovieTitleController';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminSelect } from '@/components/admin/admin-select';
import {
    ambiguityLineText,
    ANSWER_KEY_KIND_KEYS,
} from '@/components/admin/publish-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslations } from '@/hooks/use-translations';
import { localeLabel } from '@/lib/admin-enum-keys';
import type { AdminTextPreview, AdminTextTarget } from '@/types/admin';

/** La prop facultative que le rechargement partiel demande (spec 20 § 9.1, § 9.2). */
export const TEXT_PREVIEW_PROP = 'text_preview';

/** Paramètres du même rechargement — miroir de `CatalogController::PREVIEW_*`. */
const PREVIEW_TEXT_PARAMETER = 'preview_text';
const PREVIEW_TARGET_PARAMETER = 'preview_target';

/**
 * La largeur de `movie_title.title` comme de `alias.alias` (spec 10 § 3.4) :
 * un texte plus long serait refusé à l'envoi.
 */
const TEXT_MAX_LENGTH = 255;

/** Le geste ouvert : corriger (ou saisir) un titre, ou ajouter un alias. */
export type TextGesture =
    | { kind: 'title'; locale: string; current: string | null }
    | { kind: 'alias' };

type CheckStatus = 'idle' | 'loading' | 'ready' | 'failed';

/**
 * L'aperçu d'un texte saisi, demandé par rechargement partiel. Seule la
 * DERNIÈRE demande fait foi, et l'aperçu ne vaut que pour le texte qu'elle
 * portait : `checked` est ce texte, tel que l'écran l'a envoyé.
 */
function useTextCheck(): {
    status: CheckStatus;
    checked: string | null;
    request: (text: string, target: AdminTextTarget) => void;
} {
    const [state, setState] = useState<{
        status: CheckStatus;
        checked: string | null;
    }>({ status: 'idle', checked: null });
    const latest = useRef(0);

    function request(text: string, target: AdminTextTarget): void {
        const ticket = latest.current + 1;
        latest.current = ticket;
        setState({ status: 'loading', checked: text });

        const settle = (status: CheckStatus): void => {
            if (latest.current === ticket) {
                setState({ status, checked: text });
            }
        };

        router.reload({
            only: [TEXT_PREVIEW_PROP],
            data: {
                [PREVIEW_TEXT_PARAMETER]: text,
                [PREVIEW_TARGET_PARAMETER]: target,
            },
            preserveUrl: true,
            onSuccess: () => settle('ready'),
            onHttpException: () => settle('failed'),
            onNetworkError: () => settle('failed'),
        });
    }

    return { ...state, request };
}

type Props = {
    gesture: TextGesture | null;
    movieId: number;
    /** Les locales ACTIVÉES : seules elles reçoivent un titre ou un alias. */
    enabledLocales: string[];
    /** La prop `text_preview` de la fiche, absente tant qu'elle n'est pas demandée. */
    preview: AdminTextPreview | null | undefined;
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * Corriger un titre ou ajouter un alias, **aperçu avant confirmation** (spec
 * 20 § 9.1, § 9.2) :
 *
 * - « Vérifier » (ou Entrée dans le champ) demande au serveur ce que le
 *   texte deviendra : sa forme normalisée ; pour un alias, la nature exacte
 *   sous laquelle le film l'accepte déjà — l'écran avertit d'un alias
 *   redondant — ou la forme dérivée qu'il rendrait exacte ; et, sur un
 *   film publié, les formes qu'il rendrait ambiguës (`AmbiguityPreview::
 *   forText()`) ;
 * - l'envoi reste inactif — jamais retiré — tant que l'aperçu du texte
 *   COURANT n'est pas sous les yeux du curateur ; modifier le texte le
 *   périme, et la boîte le dit ;
 * - aperçu en calcul : squelette ; en échec : message et « Réessayer » ;
 *   refus du serveur : sous le champ ; déconnexion : saisie conservée
 *   (toast de l'écran) ;
 * - focus piégé (Radix), rendu au déclencheur ; fermeture générée masquée,
 *   la boîte compose la sienne (§ 13.4).
 */
export function TextGestureDialog({
    gesture,
    movieId,
    enabledLocales,
    preview,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={gesture !== null}
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

                {gesture !== null && (
                    <TextGestureForm
                        gesture={gesture}
                        movieId={movieId}
                        enabledLocales={enabledLocales}
                        preview={preview}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Le formulaire, remonté à chaque ouverture — `DialogContent` ne rend ses
 * enfants que boîte ouverte : un texte abandonné ne revient jamais, un
 * aperçu non plus.
 */
function TextGestureForm({
    gesture,
    movieId,
    enabledLocales,
    preview,
    onDone,
}: {
    gesture: TextGesture;
    movieId: number;
    enabledLocales: string[];
    preview: AdminTextPreview | null | undefined;
    onDone: () => void;
}) {
    const { t } = useTranslations();
    const textId = useId();
    const localeId = useId();
    const statusId = useId();
    const textErrorId = useId();
    const localeErrorId = useId();

    const target: AdminTextTarget = gesture.kind;
    const field = gesture.kind === 'title' ? 'title' : 'alias';

    const [text, setText] = useState(
        gesture.kind === 'title' ? (gesture.current ?? '') : '',
    );
    const [aliasLocale, setAliasLocale] = useState('');
    const check = useTextCheck();

    const trimmed = text.trim();
    const current =
        check.status === 'ready' &&
        check.checked === trimmed &&
        preview !== undefined &&
        preview !== null &&
        preview.target === target;
    const localeMissing = gesture.kind === 'alias' && aliasLocale === '';

    function verify(): void {
        if (trimmed !== '') {
            check.request(trimmed, target);
        }
    }

    const form =
        gesture.kind === 'title'
            ? MovieTitleController.update.form({
                  movie: movieId,
                  locale: gesture.locale,
              })
            : MovieAliasController.store.form(movieId);

    const heading =
        gesture.kind === 'alias'
            ? t('admin.movie.aliases.dialog.title')
            : t(
                  gesture.current === null
                      ? 'admin.movie.titles.dialog.title_add'
                      : 'admin.movie.titles.dialog.title_edit',
                  { locale: localeLabel(gesture.locale, t) },
              );

    return (
        <Form
            {...form}
            noValidate
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onDone}
            onError={() => {
                // Après un refus, la fiche revient sans la prop facultative :
                // l'aperçu du texte courant se redemande aussitôt, comme
                // celui de la publication.
                verify();
            }}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => {
                const blocked = !current || localeMissing || processing;

                return (
                    <>
                        <DialogHeader className="pr-12">
                            <DialogTitle>{heading}</DialogTitle>
                            <DialogDescription>
                                {gesture.kind === 'alias'
                                    ? t(
                                          'admin.movie.aliases.dialog.description',
                                      )
                                    : t(
                                          'admin.movie.titles.dialog.description',
                                      )}
                            </DialogDescription>
                        </DialogHeader>

                        {gesture.kind === 'alias' && (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor={localeId}>
                                    {t('admin.movie.aliases.add.locale')}
                                </Label>
                                <AdminSelect
                                    id={localeId}
                                    name="locale"
                                    value={aliasLocale}
                                    onChange={(event) =>
                                        setAliasLocale(event.target.value)
                                    }
                                    aria-required="true"
                                    aria-invalid={
                                        errors.locale ? true : undefined
                                    }
                                    aria-describedby={localeErrorId}
                                    className="min-h-11"
                                    options={[
                                        {
                                            value: '',
                                            label: t(
                                                'admin.movie.aliases.add.locale_placeholder',
                                            ),
                                        },
                                        ...enabledLocales.map((locale) => ({
                                            value: locale,
                                            label: localeLabel(locale, t),
                                        })),
                                    ]}
                                />
                                <AdminInputError
                                    id={localeErrorId}
                                    message={errors.locale}
                                />
                            </div>
                        )}

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={textId}>
                                {gesture.kind === 'alias'
                                    ? t('admin.movie.aliases.add.field')
                                    : t('admin.movie.titles.dialog.field')}
                            </Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id={textId}
                                    name={field}
                                    value={text}
                                    maxLength={TEXT_MAX_LENGTH}
                                    autoComplete="off"
                                    onChange={(event) =>
                                        setText(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        // Entrée vérifie tant que l'aperçu du
                                        // texte courant manque ; une fois
                                        // vérifié, elle envoie.
                                        if (event.key === 'Enter' && !current) {
                                            event.preventDefault();
                                            verify();
                                        }
                                    }}
                                    aria-required="true"
                                    aria-invalid={
                                        errors[field] ? true : undefined
                                    }
                                    aria-describedby={`${statusId} ${textErrorId}`}
                                    className="min-h-11"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-disabled={
                                        trimmed === '' ? true : undefined
                                    }
                                    onClick={verify}
                                    className="min-h-11 shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                >
                                    <SearchCheckIcon aria-hidden />
                                    {t('admin.movie.text_preview.check')}
                                </Button>
                            </div>
                            {gesture.kind === 'alias' && (
                                <p className="text-sm text-muted-foreground">
                                    {t('admin.movie.aliases.add.hint')}
                                </p>
                            )}
                            <AdminInputError
                                id={textErrorId}
                                message={errors[field]}
                            />
                        </div>

                        <PreviewSection
                            id={statusId}
                            status={check.status}
                            stale={
                                check.checked !== null &&
                                check.checked !== trimmed
                            }
                            current={current}
                            preview={preview}
                            onRetry={verify}
                        />

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
                                aria-disabled={blocked ? true : undefined}
                                aria-busy={processing || undefined}
                                aria-describedby={statusId}
                                onClick={(event) => {
                                    if (blocked) {
                                        event.preventDefault();
                                    }
                                }}
                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {gesture.kind === 'alias'
                                    ? t('admin.movie.aliases.dialog.submit')
                                    : t('admin.movie.titles.dialog.submit')}
                            </Button>
                        </DialogFooter>
                    </>
                );
            }}
        </Form>
    );
}

/** L'aperçu et ses états : à demander, périmé, en calcul, en échec, arrivé. */
function PreviewSection({
    id,
    status,
    stale,
    current,
    preview,
    onRetry,
}: {
    id: string;
    status: CheckStatus;
    stale: boolean;
    current: boolean;
    preview: AdminTextPreview | null | undefined;
    onRetry: () => void;
}) {
    const { t } = useTranslations();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="space-y-3">
            <h3
                id={headingId}
                className="text-sm font-semibold text-foreground"
            >
                {t('admin.movie.text_preview.heading')}
            </h3>

            <div id={id} role="status" aria-live="polite">
                {stale ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.movie.text_preview.stale')}
                    </p>
                ) : status === 'idle' ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.movie.text_preview.pending')}
                    </p>
                ) : status === 'failed' ? (
                    <AdminErrorState
                        title={t('admin.movie.text_preview.failed')}
                        retryLabel={t('admin.bank.retry')}
                        onRetry={onRetry}
                    />
                ) : status === 'loading' ? (
                    <div aria-busy="true" className="space-y-2">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-2/3" />
                        <span className="sr-only">
                            {t('admin.movie.text_preview.loading')}
                        </span>
                    </div>
                ) : current && preview !== undefined && preview !== null ? (
                    <PreviewBody preview={preview} />
                ) : (
                    // Arrivé puis perdu — une visite complète, après un refus
                    // de l'envoi, ne rend pas la prop facultative : l'aperçu
                    // se redemande, jamais un squelette sans issue.
                    <p className="text-sm text-muted-foreground">
                        {t('admin.movie.text_preview.pending')}
                    </p>
                )}
            </div>
        </section>
    );
}

/** Ce que le texte deviendra : sa forme, sa redondance, son ambiguïté. */
function PreviewBody({ preview }: { preview: AdminTextPreview }) {
    const { t } = useTranslations();

    return (
        <div className="space-y-3 text-sm">
            {preview.form === '' ? (
                <Alert variant="destructive">
                    <AlertDescription>
                        {t('admin.movie.text_preview.form_empty')}
                    </AlertDescription>
                </Alert>
            ) : (
                <p className="font-medium text-foreground">
                    {t('admin.movie.text_preview.form', { form: preview.form })}
                </p>
            )}

            {preview.accepted_as !== null && (
                <Alert>
                    <AlertDescription>
                        {t('admin.movie.text_preview.already_accepted', {
                            kind: t(ANSWER_KEY_KIND_KEYS[preview.accepted_as]),
                        })}
                    </AlertDescription>
                </Alert>
            )}

            {preview.promoted_from !== null && (
                <Alert>
                    <AlertDescription>
                        {t(
                            preview.promoted_from.is_ambiguous
                                ? 'admin.movie.text_preview.promotes_ambiguous'
                                : 'admin.movie.text_preview.promotes_derived',
                            {
                                kind: t(
                                    ANSWER_KEY_KIND_KEYS[
                                        preview.promoted_from.kind
                                    ],
                                ),
                            },
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {preview.ambiguity === null ? (
                <p className="text-muted-foreground">
                    {t('admin.movie.text_preview.not_published')}
                </p>
            ) : (
                <div className="space-y-2">
                    <p className="font-medium text-foreground">
                        {t('admin.movie.text_preview.ambiguity_heading')}
                    </p>
                    {preview.ambiguity.length === 0 ? (
                        <p className="text-muted-foreground">
                            {t('admin.movie.publish.preview.none')}
                        </p>
                    ) : (
                        <ul className="list-disc space-y-2 pl-5 text-foreground">
                            {preview.ambiguity.map((line) => (
                                <li key={line.form}>
                                    {ambiguityLineText(line, t)}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
