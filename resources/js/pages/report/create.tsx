import { Form, Head, usePage } from '@inertiajs/react';
import { CircleCheck, Flag, Info, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import { titleSegments } from '@/lib/game/scoring-format';
import { store } from '@/routes/content-report';
import { create as takedownCreate } from '@/routes/takedown';
import type { TranslationKey } from '@/types/translations';

/** `App\Enums\ContentReportScope`. */
type ReportScope = 'frame' | 'movie';

/** `App\Enums\ContentReportReason`. */
type ReportReason =
    | 'wrong_movie'
    | 'title_visible'
    | 'wrong_level'
    | 'poor_quality'
    | 'offensive'
    | 'other';

/** L'issue d'un envoi : flash `contentReport` posé par le serveur. */
type ReportOutcome = 'sent' | 'already_reported';

type ReportCreateProps = {
    /** Le film signalé : titre localisé (et sa langue), année, id TMDB. */
    movie: {
        title: string;
        titleLang: string;
        year: number | null;
        tmdb: number;
    };
    /** L'image désignée par le lien, par son seul `public_id` ; jamais l'image. */
    frame: { publicId: string } | null;
    /** Les portées offertes : `movie` seule sans image. */
    scopes: ReportScope[];
    /** Les motifs, dans l'ordre du serveur ; `frameOnly` : image seulement. */
    reasons: { value: ReportReason; frameOnly: boolean }[];
    /** `ContentReport::COMMENT_MAX_LENGTH`. */
    commentMaxLength: number;
    /** Faux sans compte ni siège : le serveur refuserait l'envoi (403). */
    canReport: boolean;
    /** Ce que CE signaleur a déjà signalé ; `frame` nul sans image. */
    alreadyReported: { movie: boolean; frame: boolean | null };
};

/**
 * Libellés par tables écrites ici, jamais par une clé construite à
 * l'exécution (spec 90 § 6.7).
 */
const SCOPE_KEYS: Record<ReportScope, TranslationKey> = {
    frame: 'game.report.scopes.frame',
    movie: 'game.report.scopes.movie',
};

const REASON_KEYS: Record<ReportReason, TranslationKey> = {
    wrong_movie: 'game.report.reasons.wrong_movie',
    title_visible: 'game.report.reasons.title_visible',
    wrong_level: 'game.report.reasons.wrong_level',
    poor_quality: 'game.report.reasons.poor_quality',
    offensive: 'game.report.reasons.offensive',
    other: 'game.report.reasons.other',
};

function outcomeOf(flash: unknown): ReportOutcome | null {
    if (typeof flash !== 'object' || flash === null) {
        return null;
    }

    const value: unknown = Reflect.get(flash, 'contentReport');

    return value === 'sent' || value === 'already_reported' ? value : null;
}

type NoticeProps = {
    tone: 'info' | 'success' | 'error';
    role: 'note' | 'status' | 'alert';
    children: ReactNode;
};

/** Un avis du billet, aux classes de `room-entry.scss`. */
function Notice({ tone, role, children }: NoticeProps) {
    const Icon =
        tone === 'success'
            ? CircleCheck
            : tone === 'error'
              ? TriangleAlert
              : Info;
    const modifier =
        tone === 'success'
            ? 'report-notice--success'
            : tone === 'error'
              ? 'room-entry-notice--error'
              : 'room-entry-notice--closed';

    return (
        <p role={role} className={`room-entry-notice ${modifier}`}>
            <Icon aria-hidden="true" />
            <span>{children}</span>
        </p>
    );
}

type ChoiceGroupProps<T extends string> = {
    name: string;
    legend: string;
    options: readonly { value: T; label: string }[];
    value: T | '';
    onChange: (value: T) => void;
    error?: string;
    columns?: boolean;
};

/**
 * Un groupe radio à nom natif, que le `<Form>` d'Inertia sérialise sans
 * code (modèle : `solo-preset-field.tsx`). Toute la tuile est l'étiquette
 * de son bouton : cible d'au moins 44 px.
 */
function ChoiceGroup<T extends string>({
    name,
    legend,
    options,
    value,
    onChange,
    error,
    columns = false,
}: ChoiceGroupProps<T>) {
    const id = useId();
    const legendId = `${id}-legend`;
    const errorId = `${id}-error`;

    return (
        <div className="report-field grid gap-3">
            <p id={legendId} className="report-field__legend text-sm">
                {legend}
            </p>

            <RadioGroup
                name={name}
                value={value}
                onValueChange={(next) => {
                    const option = options.find((each) => each.value === next);

                    if (option !== undefined) {
                        onChange(option.value);
                    }
                }}
                aria-labelledby={legendId}
                aria-describedby={error !== undefined ? errorId : undefined}
                aria-invalid={error !== undefined ? true : undefined}
                className={`report-options grid gap-2 ${columns ? 'sm:grid-cols-2' : ''}`.trim()}
            >
                {options.map((option) => {
                    const itemId = `${id}-${option.value}`;

                    return (
                        <label
                            key={option.value}
                            htmlFor={itemId}
                            className="report-option flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-border p-3"
                        >
                            <RadioGroupItem id={itemId} value={option.value} />
                            <span className="report-option__label">
                                {option.label}
                            </span>
                        </label>
                    );
                })}
            </RadioGroup>

            {error !== undefined && (
                <p id={errorId} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * « Signaler un problème » (D63 du 07/10, spec 90 § 4.5 bis) : la page
 * publique ouverte, dans un nouvel onglet, depuis le lien « Signaler » de la
 * révélation ou du podium — `/report?movie=<tmdb>&frame=<public_id>`.
 * `PublicLayout`, au gabarit des pages d'entrée (`room-entry.scss`), style
 * propre dans `report.scss`.
 *
 * - Montre le **titre localisé et l'année** du film, **jamais l'image** :
 *   aucune route ne sert une image par son `public_id` (anti-triche).
 * - Portée « Cette image » / « Le film entier » quand une image est
 *   désignée ; sinon le film, d'office. Les motifs propres à l'image
 *   disparaissent pour le film entier — le serveur les refuse de toute façon.
 * - Précisions facultatives, bornées par la prop `commentMaxLength`.
 * - États : merci (flash `sent`), déjà signalé (flash `already_reported`
 *   ou prop `alreadyReported` de la portée choisie), sans compte ni siège
 *   (« jouez d'abord une partie »), sans formulaire.
 * - Les ayants droit sont renvoyés vers la page de retrait
 *   (`takedown.create`), la voie juridique distincte.
 *
 * `<Form>` d'Inertia, champs natifs, `noValidate` : le serveur valide et
 * rend ses erreurs traduites. Aucun effet automatique : l'équipe examine.
 */
export default function ReportCreate({
    movie,
    frame,
    scopes,
    reasons,
    commentMaxLength,
    canReport,
    alreadyReported,
}: ReportCreateProps) {
    const { t, locale } = useTranslations();
    const outcome = outcomeOf(usePage().flash);
    const id = useId();
    const headingId = `${id}-heading`;
    const commentId = `${id}-comment`;
    const commentHintId = `${id}-comment-hint`;
    const commentErrorId = `${id}-comment-error`;
    const number = new Intl.NumberFormat(locale);

    const [scope, setScope] = useState<ReportScope>(scopes[0] ?? 'movie');
    const [reason, setReason] = useState<ReportReason | ''>('');
    const [comment, setComment] = useState('');

    const reportedFor = (each: ReportScope): boolean =>
        each === 'frame'
            ? alreadyReported.frame === true
            : alreadyReported.movie;
    const everythingReported = scopes.every(reportedFor);
    const frameOnly = new Set(
        reasons.filter((each) => each.frameOnly).map((each) => each.value),
    );

    function handleScopeChange(next: ReportScope): void {
        setScope(next);

        // Un motif propre à l'image ne vaut pas pour le film entier.
        if (next === 'movie' && reason !== '' && frameOnly.has(reason)) {
            setReason('');
        }
    }

    const title = t('game.report.title');
    const movieLine = titleSegments(
        movie.year === null
            ? t('game.report.movie_without_year')
            : t('game.report.movie', { year: String(movie.year) }),
        { text: movie.title, lang: movie.titleLang },
    );

    return (
        <>
            <Head title={title} />

            <section className="auth-stage room-entry-stage">
                <section
                    className="auth-ticket room-entry-ticket"
                    aria-labelledby={headingId}
                >
                    <div className="auth-ticket__form-panel">
                        <header className="auth-heading">
                            <h1 id={headingId}>{title}</h1>
                            <p>{t('game.report.description')}</p>
                        </header>

                        <div className="auth-content report-content">
                            <p className="report-movie">
                                <Flag aria-hidden="true" />
                                <span>
                                    {movieLine.map((segment, index) =>
                                        segment.kind === 'title' ? (
                                            <span
                                                key={index}
                                                lang={segment.lang}
                                            >
                                                {segment.text}
                                            </span>
                                        ) : (
                                            <span key={index}>
                                                {segment.text}
                                            </span>
                                        ),
                                    )}
                                </span>
                            </p>

                            {outcome === 'sent' && (
                                <Notice tone="success" role="status">
                                    {t('game.report.thanks')}
                                </Notice>
                            )}

                            {outcome === 'already_reported' && (
                                <Notice tone="info" role="status">
                                    {t('game.report.already_reported')}
                                </Notice>
                            )}

                            {!canReport ? (
                                <Notice tone="info" role="note">
                                    {t('game.report.need_seat')}
                                </Notice>
                            ) : everythingReported ? (
                                outcome === null && (
                                    <Notice tone="info" role="note">
                                        {t('game.report.already_reported')}
                                    </Notice>
                                )
                            ) : (
                                <Form
                                    {...store.form()}
                                    noValidate
                                    options={{
                                        preserveScroll: true,
                                        preserveState: 'errors',
                                    }}
                                    onSuccess={() => {
                                        setReason('');
                                        setComment('');
                                    }}
                                    className="room-entry-form report-form flex flex-col gap-6"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="movie"
                                                value={movie.tmdb}
                                            />
                                            {frame !== null && (
                                                <input
                                                    type="hidden"
                                                    name="frame"
                                                    value={frame.publicId}
                                                />
                                            )}

                                            {scopes.length > 1 ? (
                                                <ChoiceGroup
                                                    name="scope"
                                                    legend={t(
                                                        'game.report.fields.scope',
                                                    )}
                                                    options={scopes.map(
                                                        (each) => ({
                                                            value: each,
                                                            label: t(
                                                                SCOPE_KEYS[
                                                                    each
                                                                ],
                                                            ),
                                                        }),
                                                    )}
                                                    value={scope}
                                                    onChange={handleScopeChange}
                                                    error={errors.scope}
                                                    columns
                                                />
                                            ) : (
                                                <input
                                                    type="hidden"
                                                    name="scope"
                                                    value={scope}
                                                />
                                            )}

                                            {reportedFor(scope) ? (
                                                <Notice tone="info" role="note">
                                                    {t(
                                                        'game.report.already_reported',
                                                    )}
                                                </Notice>
                                            ) : (
                                                <>
                                                    <ChoiceGroup
                                                        name="reason"
                                                        legend={t(
                                                            'game.report.fields.reason',
                                                        )}
                                                        options={reasons
                                                            .filter(
                                                                (each) =>
                                                                    scope ===
                                                                        'frame' ||
                                                                    !each.frameOnly,
                                                            )
                                                            .map((each) => ({
                                                                value: each.value,
                                                                label: t(
                                                                    REASON_KEYS[
                                                                        each
                                                                            .value
                                                                    ],
                                                                ),
                                                            }))}
                                                        value={reason}
                                                        onChange={setReason}
                                                        error={errors.reason}
                                                    />

                                                    <div className="report-field grid gap-2">
                                                        <Label
                                                            htmlFor={commentId}
                                                        >
                                                            {t(
                                                                'game.report.fields.comment',
                                                            )}
                                                        </Label>
                                                        <Textarea
                                                            id={commentId}
                                                            name="comment"
                                                            value={comment}
                                                            onChange={(event) =>
                                                                setComment(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            maxLength={
                                                                commentMaxLength
                                                            }
                                                            rows={4}
                                                            className="report-comment"
                                                            aria-invalid={
                                                                errors.comment
                                                                    ? true
                                                                    : undefined
                                                            }
                                                            aria-describedby={
                                                                errors.comment
                                                                    ? `${commentHintId} ${commentErrorId}`
                                                                    : commentHintId
                                                            }
                                                        />
                                                        <p
                                                            id={commentHintId}
                                                            className="flex justify-between gap-3 text-sm text-muted-foreground"
                                                        >
                                                            <span>
                                                                {t(
                                                                    'game.report.comment_hint',
                                                                    {
                                                                        max: number.format(
                                                                            commentMaxLength,
                                                                        ),
                                                                    },
                                                                )}
                                                            </span>
                                                            <span
                                                                aria-hidden="true"
                                                                className="tabular-nums"
                                                            >
                                                                {`${number.format(comment.length)} / ${number.format(commentMaxLength)}`}
                                                            </span>
                                                        </p>
                                                        {errors.comment && (
                                                            <p
                                                                id={
                                                                    commentErrorId
                                                                }
                                                                className="text-sm text-destructive"
                                                            >
                                                                {errors.comment}
                                                            </p>
                                                        )}
                                                    </div>

                                                    {(errors.movie ??
                                                        errors.frame) !==
                                                        undefined && (
                                                        <Notice
                                                            tone="error"
                                                            role="alert"
                                                        >
                                                            {errors.movie ??
                                                                errors.frame}
                                                        </Notice>
                                                    )}

                                                    <p className="text-sm text-muted-foreground">
                                                        {t(
                                                            'game.report.no_automatic_effect',
                                                        )}
                                                    </p>

                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                        aria-busy={processing}
                                                        className="min-h-11 w-full sm:w-auto sm:self-start"
                                                    >
                                                        {processing ? (
                                                            <Spinner
                                                                aria-hidden="true"
                                                                role="presentation"
                                                                aria-label={
                                                                    undefined
                                                                }
                                                                className="motion-reduce:animate-none"
                                                            />
                                                        ) : (
                                                            <Flag aria-hidden="true" />
                                                        )}
                                                        {t(
                                                            'game.report.submit',
                                                        )}
                                                    </Button>
                                                </>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}

                            <p className="report-rights text-sm text-muted-foreground">
                                {t('game.report.rights_holder')}{' '}
                                <a
                                    href={takedownCreate().url}
                                    className="underline underline-offset-4 hover:text-foreground"
                                >
                                    {t('game.report.rights_holder_link')}
                                </a>
                            </p>
                        </div>
                    </div>
                </section>
            </section>
        </>
    );
}
