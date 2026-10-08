import { Link, router } from '@inertiajs/react';
import { LinkIcon, UnlinkIcon } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import MovieGroupController from '@/actions/App/Http/Controllers/Admin/MovieGroupController';
import { AvailabilityBadge } from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminFieldList } from '@/components/admin/admin-field-list';
import { AdminInputError } from '@/components/admin/admin-input-error';
import {
    ConfirmGestureDialog,
    useGestureFocus,
} from '@/components/admin/confirm-gesture-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import { formatMoment } from '@/lib/admin-format';
import { show } from '@/routes/admin/catalog';
import type {
    AdminGroupCandidate,
    AdminGroupManualLookup,
    AdminGroupRefusal,
    AdminMovieGroup,
    AdminMovieIdentity,
    AdminProximityCandidate,
    AdminProximityReason,
} from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/**
 * Les largeurs de `movie_group.label` et `movie_group.note` (spec 10 § 3.3) :
 * au-delà, la requête refuse sous le champ.
 */
const LABEL_MAX_LENGTH = 120;
const NOTE_MAX_LENGTH = 500;

/** La prop facultative des candidats par proximité (§ 9.4 [J2]) — miroir de `CatalogController`. */
const PROXIMITY_PROP = 'group_candidates';

/** Chaque raison d'une proposition par proximité. */
const PROXIMITY_REASON_KEYS: Record<AdminProximityReason, TranslationKey> = {
    title_distance: 'admin.movie.group.proximity.reason.title_distance',
    same_collection: 'admin.movie.group.proximity.reason.same_collection',
};

/** La prop facultative de la voie manuelle, et son paramètre — miroir de `CatalogController`. */
const GROUP_LOOKUP_PROP = 'group_manual_candidate';
const GROUP_WITH_PARAMETER = 'group_with';

/** Chaque refus de la voie manuelle, dit sous le champ de l'identifiant. */
const GROUP_REFUSAL_KEYS: Record<AdminGroupRefusal, TranslationKey> = {
    self: 'admin.movie.group.self',
    missing: 'admin.movie.group.other_missing',
    withdrawn: 'admin.movie.group.other_withdrawn',
    both_grouped: 'admin.movie.group.both_grouped',
    same_group: 'admin.movie.group.manual.same_group',
};

type GroupGesture =
    | { kind: 'leave' }
    | { kind: 'pair'; candidate: AdminGroupCandidate };

/**
 * Le regroupement « même œuvre » (spec 20 § 9.4) : manuel, jamais TMDB,
 * jamais montré à un joueur ; sa seule conséquence est que les films du
 * groupe ne tombent jamais dans une même partie.
 *
 * - Le groupe du film, ses films liés à leur fiche, et « Retirer du groupe »
 *   — un groupe réduit à un seul film disparaît.
 * - Les **candidats exacts**, films au titre normalisé identique : le
 *   back-office suggère, le curateur tranche. « Regrouper » ouvre une
 *   confirmation au libellé pré-rempli, modifiable, quand un groupe naît ;
 *   sinon elle dit quel film rejoint quel groupe.
 * - « Regrouper avec un autre film », par son identifiant catalogue, pour
 *   un remake au titre différent : le film cherché, la même confirmation.
 *
 * Tout refus — film introuvable, retiré, déjà groupé ailleurs — revient en
 * erreur traduite ; les gestes n'apparaissent que si `canCurate`. Retirer
 * se demande en toutes lettres (`leave`) : un envoi qui ne nomme rien est
 * refusé, jamais lu comme un retrait.
 */
export function MovieGroupPanel({
    movieId,
    group,
    candidates,
    canCurate,
}: {
    movieId: number;
    group: AdminMovieGroup | null;
    candidates: AdminGroupCandidate[];
    canCurate: boolean;
}) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const focus = useGestureFocus();
    const [gesture, setGesture] = useState<GroupGesture | null>(null);

    function open(next: GroupGesture): void {
        focus.remember();
        setGesture(next);
    }

    const close = (): void => setGesture(null);

    return (
        <div className="space-y-6">
            <Card>
                <section
                    ref={focus.zoneRef}
                    tabIndex={-1}
                    aria-labelledby={headingId}
                    className="flex flex-col gap-6 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <CardHeader>
                        <AdminCardTitle id={headingId}>
                            {t('admin.movie.group.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.movie.group.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {group === null ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.movie.group.none')}
                            </p>
                        ) : (
                            <>
                                <AdminFieldList
                                    fields={[
                                        {
                                            label: t('admin.movie.group.label'),
                                            value: group.label,
                                        },
                                        {
                                            label: t('admin.movie.group.note'),
                                            value:
                                                group.note ??
                                                t('admin.common.none'),
                                        },
                                        {
                                            label: t(
                                                'admin.movie.group.created_by',
                                            ),
                                            value:
                                                group.created_by ??
                                                t(
                                                    'admin.common.deleted_account',
                                                ),
                                        },
                                        {
                                            label: t(
                                                'admin.movie.group.created_at',
                                            ),
                                            value:
                                                formatMoment(
                                                    group.created_at,
                                                    locale,
                                                ) ?? t('admin.common.unknown'),
                                        },
                                    ]}
                                />

                                <div className="space-y-2">
                                    <h3 className="text-sm font-semibold text-foreground">
                                        {t('admin.movie.group.members')}
                                    </h3>
                                    <ul className="space-y-2">
                                        {group.movies.map((member) => (
                                            <li
                                                key={member.id}
                                                className="flex flex-wrap items-center gap-2 text-sm"
                                            >
                                                <MovieLink
                                                    movie={member}
                                                    current={
                                                        member.id === movieId
                                                    }
                                                />
                                                <AvailabilityBadge
                                                    value={member.availability}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                {canCurate && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => open({ kind: 'leave' })}
                                        className="min-h-11"
                                    >
                                        <UnlinkIcon aria-hidden />
                                        {t('admin.movie.group.leave.action')}
                                    </Button>
                                )}
                            </>
                        )}
                    </CardContent>
                </section>
            </Card>

            <Card>
                <CardHeader>
                    <AdminCardTitle>
                        {t('admin.movie.group.candidates.heading')}
                    </AdminCardTitle>
                    <CardDescription>
                        {t('admin.movie.group.candidates.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {candidates.length === 0 ? (
                        <AdminEmptyState
                            title={t('admin.movie.group.candidates.empty')}
                        />
                    ) : (
                        <ul className="divide-y divide-border">
                            {candidates.map((candidate) => (
                                <li
                                    key={candidate.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <MovieLink
                                            movie={candidate}
                                            current={false}
                                        />
                                        <AvailabilityBadge
                                            value={candidate.availability}
                                        />
                                        {candidate.same_group ? (
                                            <Badge variant="secondary">
                                                {t(
                                                    'admin.movie.group.candidates.same_group',
                                                )}
                                            </Badge>
                                        ) : (
                                            candidate.group_label !== null && (
                                                <Badge variant="outline">
                                                    {t(
                                                        'admin.movie.group.candidates.in_group',
                                                        {
                                                            label: candidate.group_label,
                                                        },
                                                    )}
                                                </Badge>
                                            )
                                        )}
                                    </div>

                                    {canCurate && !candidate.same_group && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            aria-label={t(
                                                'admin.movie.group.candidates.action_label',
                                                {
                                                    movie: movieName(
                                                        candidate,
                                                        t,
                                                    ),
                                                },
                                            )}
                                            onClick={() =>
                                                open({
                                                    kind: 'pair',
                                                    candidate,
                                                })
                                            }
                                            className="min-h-11"
                                        >
                                            <LinkIcon aria-hidden />
                                            {t(
                                                'admin.movie.group.candidates.action',
                                            )}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>

            <ProximityCandidatesCard
                canCurate={canCurate}
                onPair={(candidate) => open({ kind: 'pair', candidate })}
            />

            {canCurate && (
                <ManualGroupCard movieId={movieId} grouped={group !== null} />
            )}

            {gesture?.kind === 'leave' && (
                <ConfirmGestureDialog
                    open
                    form={MovieGroupController.update.form(movieId)}
                    title={t('admin.movie.group.leave.title')}
                    description={t('admin.movie.group.leave.description')}
                    submitLabel={t('admin.movie.group.leave.submit')}
                    errorFields={['leave', 'with_movie_id', 'group_id']}
                    onClose={close}
                    onReturnFocus={focus.restore}
                >
                    <input type="hidden" name="leave" value="1" />
                </ConfirmGestureDialog>
            )}

            {gesture?.kind === 'pair' && (
                <PairDialog
                    movieId={movieId}
                    grouped={group !== null}
                    candidate={gesture.candidate}
                    onClose={close}
                    onReturnFocus={focus.restore}
                />
            )}
        </div>
    );
}

/**
 * La confirmation d'un regroupement avec un candidat — exact, ou trouvé par
 * la voie manuelle : le libellé pré-rempli, modifiable, et une note quand un
 * groupe naît ; sinon, quel film rejoint quel groupe.
 */
function PairDialog({
    movieId,
    grouped,
    candidate,
    onClose,
    onReturnFocus,
}: {
    movieId: number;
    grouped: boolean;
    candidate: AdminGroupCandidate;
    onClose: () => void;
    onReturnFocus: () => void;
}) {
    const { t } = useTranslations();
    const labelId = useId();
    const labelHintId = useId();
    const noteId = useId();
    const noteHintId = useId();
    const name = movieName(candidate, t);
    const creates = !grouped && candidate.group_label === null;

    const notice = creates
        ? undefined
        : candidate.group_label !== null && !grouped
          ? t('admin.movie.group.dialog.joins_theirs', {
                label: candidate.group_label,
            })
          : grouped && candidate.group_label === null
            ? t('admin.movie.group.dialog.joins_ours', { movie: name })
            : undefined;

    return (
        <ConfirmGestureDialog
            open
            form={MovieGroupController.update.form(movieId)}
            title={t('admin.movie.group.dialog.title')}
            description={t('admin.movie.group.dialog.description', {
                movie: name,
            })}
            notice={notice}
            submitLabel={t('admin.movie.group.dialog.submit')}
            errorFields={['with_movie_id', 'label', 'note']}
            onClose={onClose}
            onReturnFocus={onReturnFocus}
        >
            <input type="hidden" name="with_movie_id" value={candidate.id} />

            {creates && (
                <>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={labelId}>
                            {t('admin.movie.group.label')}
                        </Label>
                        <Input
                            id={labelId}
                            name="label"
                            defaultValue={candidate.default_label}
                            maxLength={LABEL_MAX_LENGTH}
                            autoComplete="off"
                            aria-describedby={labelHintId}
                            className="min-h-11"
                        />
                        <p
                            id={labelHintId}
                            className="text-sm text-muted-foreground"
                        >
                            {t('admin.movie.group.dialog.label_hint')}
                        </p>
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={noteId}>
                            {t('admin.movie.group.note')}
                        </Label>
                        <Textarea
                            id={noteId}
                            name="note"
                            maxLength={NOTE_MAX_LENGTH}
                            aria-describedby={noteHintId}
                        />
                        <p
                            id={noteHintId}
                            className="text-sm text-muted-foreground"
                        >
                            {t('admin.movie.group.dialog.note_hint')}
                        </p>
                    </div>
                </>
            )}
        </ConfirmGestureDialog>
    );
}

type LookupStatus = 'idle' | 'loading' | 'ready' | 'failed';

/**
 * Les candidats par proximité (spec 20 § 9.4 [J2], L20-27) : titre proche à
 * chiffres identiques, ou même saga. Le calcul parcourt tout le catalogue :
 * il ne part qu'au clic, par rechargement partiel de la prop facultative
 * `group_candidates`, dont la réponse est gardée ici. Le back-office
 * suggère ; « Regrouper » ouvre la même confirmation qu'un candidat exact.
 */
function ProximityCandidatesCard({
    canCurate,
    onPair,
}: {
    canCurate: boolean;
    onPair: (candidate: AdminProximityCandidate) => void;
}) {
    const { t } = useTranslations();
    const statusId = useId();
    const [status, setStatus] = useState<LookupStatus>('idle');
    const [candidates, setCandidates] = useState<AdminProximityCandidate[]>([]);
    const loading = status === 'loading';

    function load(): void {
        if (loading) {
            return;
        }

        setStatus('loading');

        router.reload({
            only: [PROXIMITY_PROP],
            preserveUrl: true,
            onSuccess: (page) => {
                const result = page.props[PROXIMITY_PROP] as
                    | AdminProximityCandidate[]
                    | undefined;

                if (result === undefined) {
                    setStatus('failed');

                    return;
                }

                setCandidates(result);
                setStatus('ready');
            },
            onHttpException: () => setStatus('failed'),
            onNetworkError: () => setStatus('failed'),
        });
    }

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.group.proximity.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.group.proximity.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Button
                    type="button"
                    variant="outline"
                    aria-disabled={loading || undefined}
                    aria-busy={loading || undefined}
                    aria-describedby={statusId}
                    onClick={load}
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    {status === 'idle'
                        ? t('admin.movie.group.proximity.load')
                        : t('admin.movie.group.proximity.reload')}
                </Button>

                <p
                    id={statusId}
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {status === 'loading'
                        ? t('admin.movie.group.proximity.loading')
                        : status === 'ready' && candidates.length === 0
                          ? t('admin.movie.group.proximity.empty')
                          : status === 'failed'
                            ? t('admin.movie.group.proximity.failed')
                            : ''}
                </p>

                {status === 'ready' && candidates.length > 0 && (
                    <ul className="divide-y divide-border">
                        {candidates.map((candidate) => (
                            <li
                                key={candidate.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <MovieLink
                                        movie={candidate}
                                        current={false}
                                    />
                                    <AvailabilityBadge
                                        value={candidate.availability}
                                    />
                                    {candidate.reasons.map((reason) => (
                                        <Badge key={reason} variant="outline">
                                            {t(PROXIMITY_REASON_KEYS[reason])}
                                        </Badge>
                                    ))}
                                    {candidate.same_group ? (
                                        <Badge variant="secondary">
                                            {t(
                                                'admin.movie.group.candidates.same_group',
                                            )}
                                        </Badge>
                                    ) : (
                                        candidate.group_label !== null && (
                                            <Badge variant="outline">
                                                {t(
                                                    'admin.movie.group.candidates.in_group',
                                                    {
                                                        label: candidate.group_label,
                                                    },
                                                )}
                                            </Badge>
                                        )
                                    )}
                                </div>

                                {canCurate && !candidate.same_group && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        aria-label={t(
                                            'admin.movie.group.candidates.action_label',
                                            {
                                                movie: movieName(candidate, t),
                                            },
                                        )}
                                        onClick={() => onPair(candidate)}
                                        className="min-h-11"
                                    >
                                        <LinkIcon aria-hidden />
                                        {t(
                                            'admin.movie.group.candidates.action',
                                        )}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * La recherche de la voie manuelle, par rechargement partiel de la prop
 * `group_manual_candidate`. Seule la DERNIÈRE demande fait foi ; sa réponse
 * est gardée ici, pas lue dans les props de la page : un refus de l'envoi
 * revient par une visite complète, sans la prop facultative, et la
 * confirmation ouverte ne doit pas disparaître avec elle.
 */
function useManualLookup(): {
    status: LookupStatus;
    requested: string | null;
    result: AdminGroupManualLookup | null;
    request: (requested: string) => void;
    reset: () => void;
} {
    const [state, setState] = useState<{
        status: LookupStatus;
        requested: string | null;
        result: AdminGroupManualLookup | null;
    }>({ status: 'idle', requested: null, result: null });
    const latest = useRef(0);

    function request(requested: string): void {
        const ticket = latest.current + 1;
        latest.current = ticket;
        setState({ status: 'loading', requested, result: null });

        const settle = (
            status: LookupStatus,
            result: AdminGroupManualLookup | null,
        ): void => {
            if (latest.current === ticket) {
                setState({ status, requested, result });
            }
        };

        router.reload({
            only: [GROUP_LOOKUP_PROP],
            data: { [GROUP_WITH_PARAMETER]: requested },
            preserveUrl: true,
            onSuccess: (page) => {
                const result = page.props[GROUP_LOOKUP_PROP] as
                    | AdminGroupManualLookup
                    | null
                    | undefined;

                // Une réponse qui ne porte pas le texte demandé ne se lit
                // jamais comme la sienne.
                settle(
                    result?.requested === requested ? 'ready' : 'failed',
                    result?.requested === requested ? result : null,
                );
            },
            onHttpException: () => settle('failed', null),
            onNetworkError: () => settle('failed', null),
        });
    }

    function reset(): void {
        latest.current += 1;
        setState({ status: 'idle', requested: null, result: null });
    }

    return { ...state, request, reset };
}

/**
 * « Regrouper avec un autre film » par son identifiant catalogue : pour un
 * remake au titre différent, qu'aucun candidat exact ne propose.
 *
 * « Regrouper » (ou Entrée dans le champ) cherche d'abord le film ; trouvé,
 * il ouvre **la même confirmation qu'un candidat** — libellé pré-rempli
 * « Titre A (année) / Titre B (année) », modifiable avant validation, note
 * facultative (§ 9.4). Un identifiant vide ne part jamais : il est signalé
 * sous le champ. Un refus — film introuvable, retiré, lui-même, déjà
 * ensemble, deux groupes — s'affiche sous le champ, sans confirmation vouée
 * à l'échec ; une recherche qui n'aboutit pas se réessaie.
 */
function ManualGroupCard({
    movieId,
    grouped,
}: {
    movieId: number;
    grouped: boolean;
}) {
    const { t } = useTranslations();
    const movieFieldId = useId();
    const movieHintId = useId();
    const movieErrorId = useId();
    const statusId = useId();
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [value, setValue] = useState('');
    const [missing, setMissing] = useState(false);
    const search = useManualLookup();

    const trimmed = value.trim();
    const loading = search.status === 'loading';
    // La réponse ne vaut que pour l'identifiant encore saisi.
    const answered =
        search.status === 'ready' &&
        search.requested === trimmed &&
        search.result !== null;
    const refusal = answered ? (search.result?.refusal ?? null) : null;
    const found =
        answered && refusal === null
            ? (search.result?.candidate ?? null)
            : null;
    const failed = search.status === 'failed' && search.requested === trimmed;

    function lookUp(): void {
        if (loading) {
            return;
        }

        if (trimmed === '') {
            setMissing(true);

            return;
        }

        setMissing(false);
        search.request(trimmed);
    }

    const error = missing
        ? t('admin.movie.group.movie_required')
        : refusal !== null
          ? t(GROUP_REFUSAL_KEYS[refusal])
          : undefined;

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.group.manual.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.group.manual.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex max-w-xl flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={movieFieldId}>
                        {t('admin.movie.group.manual.movie')}
                    </Label>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id={movieFieldId}
                            value={value}
                            inputMode="numeric"
                            autoComplete="off"
                            onChange={(event) => {
                                setValue(event.target.value);
                                setMissing(false);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    lookUp();
                                }
                            }}
                            aria-required="true"
                            aria-invalid={
                                error !== undefined ? true : undefined
                            }
                            aria-describedby={`${movieHintId} ${movieErrorId} ${statusId}`}
                            className="min-h-11"
                        />
                        <Button
                            ref={triggerRef}
                            type="button"
                            aria-disabled={loading || undefined}
                            aria-busy={loading || undefined}
                            onClick={lookUp}
                            className="min-h-11 shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                        >
                            <LinkIcon aria-hidden />
                            {t('admin.movie.group.manual.submit')}
                        </Button>
                    </div>
                    <p
                        id={movieHintId}
                        className="text-sm text-muted-foreground"
                    >
                        {t('admin.movie.group.manual.movie_hint')}
                    </p>
                    <AdminInputError id={movieErrorId} message={error} />
                    <p id={statusId} role="status" className="sr-only">
                        {loading ? t('admin.movie.group.manual.searching') : ''}
                    </p>
                </div>

                {failed && (
                    <AdminErrorState
                        title={t('admin.movie.group.manual.failed')}
                        retryLabel={t('admin.bank.retry')}
                        onRetry={lookUp}
                    />
                )}
            </CardContent>

            {found !== null && (
                <PairDialog
                    movieId={movieId}
                    grouped={grouped}
                    candidate={found}
                    onClose={search.reset}
                    onReturnFocus={() => triggerRef.current?.focus()}
                />
            )}
        </Card>
    );
}

/** Un film nommé par son titre original et son année, lié à sa fiche. */
function MovieLink({
    movie,
    current,
}: {
    movie: AdminMovieIdentity;
    current: boolean;
}) {
    const { t } = useTranslations();
    const name = movieName(movie, t);

    if (current) {
        return (
            <span className="font-medium text-foreground">
                {t('admin.common.label_value', {
                    label: t('admin.movie.group.this_movie'),
                    value: name,
                })}
            </span>
        );
    }

    return (
        <Link
            href={show(movie.id)}
            className="font-medium text-foreground underline underline-offset-4"
            aria-label={t('admin.a11y.open_movie', { title: name })}
        >
            {name}
        </Link>
    );
}

/** « Titre (année) », ou le titre seul quand l'année est inconnue. */
function movieName(movie: AdminMovieIdentity, t: Translator['t']): string {
    return movie.release_year === null
        ? t('admin.movie.group.movie_without_year', {
              title: movie.title_original,
          })
        : t('admin.movie.group.movie', {
              title: movie.title_original,
              year: movie.release_year,
          });
}
