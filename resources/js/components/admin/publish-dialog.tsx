import { Form, router } from '@inertiajs/react';
import { SendIcon, XIcon } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import MoviePublishController from '@/actions/App/Http/Controllers/Admin/MoviePublishController';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
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
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import type {
    AdminAmbiguityLine,
    AdminPublication,
    AdminPublicationBlocker,
    AdminPublicationPreview,
    AnswerKeyKind,
} from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/** La prop facultative que le rechargement partiel demande (spec 20 § 8.2). */
export const PUBLICATION_PREVIEW_PROP = 'publication_preview';

/**
 * Chaque condition de publication manquante, et la clé qui la nomme — la
 * même sous le bouton inactif et dans le refus du serveur.
 */
export const PUBLICATION_BLOCKER_KEYS: Record<
    AdminPublicationBlocker,
    TranslationKey
> = {
    content_not_clear: 'admin.movie.publish.content_not_clear',
    coverage_missing: 'admin.movie.publish.coverage_missing',
    not_guessable: 'admin.movie.publish.not_guessable',
};

/** Chaque nature de clé de réponse, en minuscules, composée dans une phrase. */
const ANSWER_KEY_KIND_KEYS: Record<AnswerKeyKind, TranslationKey> = {
    title_original: 'admin.movie.publish.preview.kind.title_original',
    title_latin: 'admin.movie.publish.preview.kind.title_latin',
    title: 'admin.movie.publish.preview.kind.title',
    alias: 'admin.movie.publish.preview.kind.alias',
    prefix: 'admin.movie.publish.preview.kind.prefix',
    subtitle: 'admin.movie.publish.preview.kind.subtitle',
};

/** L'état de l'aperçu demandé : rien, en route, arrivé, ou en échec. */
export type PublicationPreviewStatus = 'idle' | 'loading' | 'ready' | 'failed';

/**
 * Le rechargement partiel qui sert l'aperçu d'ambiguïté, à l'ouverture de la
 * confirmation et après tout refus de l'envoi. Seule la DERNIÈRE demande
 * fait foi : une réponse plus ancienne, arrivée en retard, ne passe jamais
 * pour l'aperçu courant.
 */
export function usePublicationPreview(): {
    status: PublicationPreviewStatus;
    request: () => void;
} {
    const [status, setStatus] = useState<PublicationPreviewStatus>('idle');
    const latest = useRef(0);

    function request(): void {
        const ticket = latest.current + 1;
        latest.current = ticket;
        setStatus('loading');

        const settle = (next: PublicationPreviewStatus): void => {
            if (latest.current === ticket) {
                setStatus(next);
            }
        };

        router.reload({
            only: [PUBLICATION_PREVIEW_PROP],
            onSuccess: () => settle('ready'),
            onHttpException: () => settle('failed'),
            onNetworkError: () => settle('failed'),
        });
    }

    return { status, request };
}

/**
 * « Publier le film » (spec 20 § 8.1) : actif quand toutes les conditions
 * tiennent, sinon inactif — jamais retiré — avec la condition manquante
 * nommée dessous et liée par `aria-describedby`. Le bouton reste
 * focalisable : un curateur au clavier lit pourquoi il ne peut pas publier.
 */
export function PublishButton({
    publication,
    onOpen,
}: {
    publication: AdminPublication;
    onOpen: () => void;
}) {
    const { t } = useTranslations();
    const blockersId = useId();
    const blocked = publication.blockers.length > 0;

    return (
        <div className="flex flex-col gap-2">
            <Button
                type="button"
                aria-disabled={blocked ? true : undefined}
                aria-describedby={blocked ? blockersId : undefined}
                onClick={(event) => {
                    if (blocked) {
                        event.preventDefault();

                        return;
                    }

                    onOpen();
                }}
                className="min-h-11 self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
            >
                <SendIcon aria-hidden />
                {publication.first
                    ? t('admin.movie.publish.action')
                    : t('admin.movie.publish.action_republish')}
            </Button>

            {blocked && (
                <div id={blockersId} className="space-y-1 text-sm">
                    <p className="font-medium text-foreground">
                        {t('admin.movie.publish.blocked_heading')}
                    </p>
                    <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                        {publication.blockers.map((blocker) => (
                            <li key={blocker}>
                                {blockerText(blocker, publication, t)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

type Props = {
    open: boolean;
    movieId: number;
    /** Première publication (`movie.published`) ou republication. */
    first: boolean;
    /** La prop `publication_preview` de l'écran, absente tant qu'elle n'est pas demandée. */
    preview: AdminPublicationPreview | undefined;
    status: PublicationPreviewStatus;
    onRetryPreview: () => void;
    /** Les props que la redirection de l'envoi recharge ; toutes si absent. */
    only?: string[];
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone des gestes s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * La confirmation de publication, avec l'**avertissement nominatif
 * d'ambiguïté** AVANT tout envoi (spec 20 § 8.2, décision 13).
 *
 * - L'aperçu arrive par un rechargement partiel (`publication_preview`) :
 *   pendant son calcul, un squelette ; en échec, un message et
 *   « Réessayer » ; arrivé, la liste des formes que la publication rendra
 *   ambiguës et leurs films, ou `preview.none` en toutes lettres. L'envoi
 *   reste inactif tant que l'aperçu n'est pas sous les yeux du curateur.
 * - L'envoi poste l'empreinte de CET aperçu. Si le catalogue a changé
 *   entre-temps, le serveur refuse (`preview_stale`) sans rien écrire :
 *   le refus s'affiche et l'aperçu se recharge, jamais périmé.
 * - Les conditions manquantes relues sous verrou reviennent en erreur
 *   traduite sous le formulaire, jamais en 403 ; l'aperçu se redemande
 *   après tout refus, jamais laissé en squelette.
 * - Focus piégé (Radix), rendu au déclencheur ; la fermeture générée en
 *   anglais est masquée, la boîte compose la sienne (§ 13.4).
 */
export function PublishDialog({
    open,
    movieId,
    first,
    preview,
    status,
    onRetryPreview,
    only,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={open}
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

                <Form
                    {...MoviePublishController.store.form(movieId)}
                    noValidate
                    options={{
                        preserveScroll: true,
                        preserveState: true,
                        ...(only === undefined ? {} : { only }),
                    }}
                    onSuccess={onClose}
                    onError={() => {
                        // Après tout refus — avertissement changé ou condition
                        // relue sous verrou —, l'aperçu se redemande : la fiche
                        // se recharge sans la prop facultative, qui sans cela
                        // laisserait un squelette sans issue. Le refus reste
                        // affiché au-dessus jusqu'au prochain envoi.
                        onRetryPreview();
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => {
                        const ready =
                            status === 'ready' && preview !== undefined;
                        const blocked = !ready || processing;

                        return (
                            <>
                                <DialogHeader className="pr-12">
                                    <DialogTitle>
                                        {first
                                            ? t('admin.movie.publish.title')
                                            : t(
                                                  'admin.movie.publish.title_republish',
                                              )}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('admin.movie.publish.description')}
                                    </DialogDescription>
                                </DialogHeader>

                                <AdminInputError
                                    message={errors.ambiguity_digest}
                                />

                                <AmbiguitySection
                                    status={status}
                                    preview={preview}
                                    onRetry={onRetryPreview}
                                />

                                {ready && (
                                    <input
                                        type="hidden"
                                        name="ambiguity_digest"
                                        value={preview.digest}
                                    />
                                )}

                                <AdminInputError message={errors.movie} />

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
                                        aria-disabled={
                                            blocked ? true : undefined
                                        }
                                        aria-busy={processing || undefined}
                                        onClick={(event) => {
                                            if (blocked) {
                                                event.preventDefault();
                                            }
                                        }}
                                        className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                    >
                                        {first
                                            ? t('admin.movie.publish.submit')
                                            : t(
                                                  'admin.movie.publish.submit_republish',
                                              )}
                                    </Button>
                                </DialogFooter>
                            </>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** L'aperçu d'ambiguïté et ses états : calcul, échec, liste ou liste vide. */
function AmbiguitySection({
    status,
    preview,
    onRetry,
}: {
    status: PublicationPreviewStatus;
    preview: AdminPublicationPreview | undefined;
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
                {t('admin.movie.publish.preview.heading')}
            </h3>
            <p className="text-sm text-muted-foreground">
                {t('admin.movie.publish.preview.description')}
            </p>

            <div role="status" aria-live="polite">
                {status === 'failed' ? (
                    <AdminErrorState
                        title={t('admin.movie.publish.preview.failed')}
                        retryLabel={t('admin.bank.retry')}
                        onRetry={onRetry}
                    />
                ) : status !== 'ready' || preview === undefined ? (
                    <div aria-busy="true" className="space-y-2">
                        <Skeleton className="h-4 w-full" />
                        <Skeleton className="h-4 w-2/3" />
                        <span className="sr-only">
                            {t('admin.movie.publish.preview.loading')}
                        </span>
                    </div>
                ) : preview.lines.length === 0 ? (
                    <p className="text-sm font-medium text-foreground">
                        {t('admin.movie.publish.preview.none')}
                    </p>
                ) : (
                    <ul className="list-disc space-y-2 pl-5 text-sm text-foreground">
                        {preview.lines.map((line) => (
                            <li key={line.form}>{lineText(line, t)}</li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

/** Une condition manquante, ses niveaux nommés pour la couverture. */
function blockerText(
    blocker: AdminPublicationBlocker,
    publication: AdminPublication,
    t: Translator['t'],
): string {
    return t(
        PUBLICATION_BLOCKER_KEYS[blocker],
        blocker === 'coverage_missing'
            ? {
                  levels: publication.missing_levels.join(
                      t('admin.common.list_separator'),
                  ),
              }
            : undefined,
    );
}

/**
 * Une ligne de l'avertissement : « « forme » — préfixe de ce film, porté
 * aussi par : Titre (année), titre ». Les films sont joints par le
 * séparateur de liste du dictionnaire, jamais une ponctuation en dur.
 */
function lineText(line: AdminAmbiguityLine, t: Translator['t']): string {
    const separator = t('admin.common.list_separator');

    return t('admin.movie.publish.preview.line', {
        form: line.form,
        kinds: line.kinds
            .map((kind) => t(ANSWER_KEY_KIND_KEYS[kind]))
            .join(separator),
        movies: line.movies
            .map((movie) =>
                movie.release_year === null
                    ? t('admin.movie.publish.preview.movie_without_year', {
                          title: movie.title_original,
                          kind: t(ANSWER_KEY_KIND_KEYS[movie.kind]),
                      })
                    : t('admin.movie.publish.preview.movie', {
                          title: movie.title_original,
                          year: movie.release_year,
                          kind: t(ANSWER_KEY_KIND_KEYS[movie.kind]),
                      }),
            )
            .join(separator),
    });
}
