import { Form } from '@inertiajs/react';
import { CheckIcon, InfoIcon, TriangleAlertIcon, XIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import FrameReviewController from '@/actions/App/Http/Controllers/Admin/FrameReviewController';
import { GameConditionsPreview } from '@/components/admin/game-conditions-preview';
import { FRAME_LEVEL_KEYS } from '@/components/admin/level-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import type {
    AdminReviewFrame,
    AdminReviewItem,
    AdminReviewMovie,
} from '@/types/admin';

/**
 * Les props qu'une ÉCRITURE de la passe de revue recharge : la file, et elle
 * seule. Une revue, une dépublication ou une mise à l'écart reviennent sur
 * la file par une redirection ; la visite partielle suit la redirection, et
 * un refus rafraîchit la file en même temps qu'il s'affiche.
 */
export const REVIEW_WRITE_PROPS: string[] = ['queue'];

/** Ce que la revue envoyée a produit, pour la suite de l'écran. */
export type ReviewOutcome = {
    /** Une image EN JEU rejetée : l'écran enchaîne sur sa dépublication (§ 7.5). */
    rejectedInPlay: boolean;
    /** Les points déclarés en défaut, vides pour une revue conforme. */
    failedSlugs: string[];
};

type Props = {
    frame: AdminReviewFrame;
    movie: AdminReviewMovie;
    /** Prend le focus au montage : l'image suivante, après un envoi réussi. */
    autoFocus: boolean;
    onFocused: () => void;
    onReviewed: (outcome: ReviewOutcome) => void;
    /**
     * Un refus serveur, ses messages déjà traduits. Le panneau les affiche
     * sous le formulaire tant qu'il reste monté ; s'il est remplacé par le
     * rechargement de la file — image sortie de la liste, ou rendue sous une
     * autre empreinte —, la page les reprend dans un toast.
     */
    onRefused: (messages: string[]) => void;
};

/**
 * Les messages d'un refus, dédoublonnés et sans chaîne vide.
 */
export function refusalMessages(errors: Record<string, string>): string[] {
    return [...new Set(Object.values(errors))].filter(
        (message) => message !== '',
    );
}

/**
 * Les libellés de points de la grille, dans l'ordre de la grille.
 */
export function failedItemLabels(
    items: AdminReviewItem[],
    slugs: string[],
    t: (key: AdminReviewItem['label_key']) => string,
): string[] {
    return items
        .filter((item) => slugs.includes(item.slug))
        .map((item) => t(item.label_key));
}

/**
 * L'écran de revue d'UNE image (spec 20 § 7.4, § 7.5, lot L20-12).
 *
 * - L'image se juge sur son **rendu final tel que servi**, jamais sur
 *   l'aperçu de recadrage : `GameConditionsPreview`, aux deux largeurs, sous
 *   les tokens sombres du jeu (D8 du 23/09).
 * - À côté : le film, le niveau et son guide, la **source déclarée** en
 *   lecture seule — l'envoi la confirme (§ 7.6) — et les items de la grille
 *   **applicables à ce niveau**, libellé et aide.
 * - « Conforme, publier » répond `true` à TOUS les items affichés : une
 *   déclaration explicite item par item, les items étant sous les yeux
 *   (B4). « Non conforme » ouvre les cases des points en défaut, puis
 *   « Rejeter ». Une image déjà rejetée n'offre que la revue conforme : un
 *   second rejet n'écrirait rien (§ 7.5).
 * - Le client n'envoie **jamais** de décision : le serveur la dérive des
 *   réponses. Chaque refus — octets ou niveau changés, image verrouillée ou
 *   déjà jugée — revient traduit, et la file se recharge avec lui ; il est
 *   aussi remis à la page (`onRefused`), qui le reprend en toast si ce
 *   panneau n'a pas survécu au rechargement.
 *
 * Opérable au clavier sans aucun raccourci : boutons, cases à cocher
 * étiquetées, aides rattachées par `aria-describedby`. `Entrée` en
 * raccourci de débit arrive avec le lot L20-11.
 */
export function ReviewPanel({
    frame,
    movie,
    autoFocus,
    onFocused,
    onReviewed,
    onRefused,
}: Props) {
    const { t } = useTranslations();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const headingId = useId();
    const itemsHeadingId = useId();
    const blockedHintId = useId();

    const [failing, setFailing] = useState(false);
    const [failed, setFailed] = useState<string[]>([]);

    useEffect(() => {
        if (autoFocus) {
            headingRef.current?.focus();
            onFocused();
        }
    }, [autoFocus, onFocused]);

    const levelKeys = FRAME_LEVEL_KEYS[frame.frame_level];
    const inPlay = frame.availability === 'published';
    const alreadyRejected = frame.failed_items.length > 0;
    const rejectBlocked = failed.length === 0;
    const separator = t('admin.common.list_separator');

    function toggle(slug: string, checked: boolean): void {
        setFailed((current) =>
            checked
                ? [...current.filter((item) => item !== slug), slug]
                : current.filter((item) => item !== slug),
        );
    }

    function cancelFailing(): void {
        setFailing(false);
        setFailed([]);
    }

    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-6 rounded-lg border border-border bg-card p-4 text-card-foreground"
        >
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="rounded-sm text-lg font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {t('admin.review.panel.heading', {
                    level: frame.frame_level,
                    title: movie.title_original,
                })}
            </h2>

            <GameConditionsPreview
                gameUrl={frame.game_url}
                level={frame.frame_level}
            />

            <div className="grid gap-6 xl:grid-cols-2">
                <div className="flex flex-col gap-4">
                    <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                        <dt className="font-medium text-muted-foreground">
                            {t('admin.review.panel.movie')}
                        </dt>
                        <dd className="sm:col-span-2">
                            {movie.title_original}
                        </dd>

                        {movie.title_original_latin !== null && (
                            <>
                                <dt className="font-medium text-muted-foreground">
                                    {t('admin.review.panel.title_latin')}
                                </dt>
                                <dd className="sm:col-span-2">
                                    {movie.title_original_latin}
                                </dd>
                            </>
                        )}

                        {movie.release_year !== null && (
                            <>
                                <dt className="font-medium text-muted-foreground">
                                    {t('admin.review.panel.release_year')}
                                </dt>
                                <dd className="sm:col-span-2">
                                    {movie.release_year}
                                </dd>
                            </>
                        )}

                        <dt className="font-medium text-muted-foreground">
                            {t('admin.review.panel.level')}
                        </dt>
                        <dd className="flex flex-col gap-1 sm:col-span-2">
                            <span>
                                {t('admin.level.option', {
                                    level: frame.frame_level,
                                    label: t(levelKeys.label),
                                })}
                            </span>
                            <span className="text-muted-foreground">
                                {t(levelKeys.guide)}
                            </span>
                        </dd>
                    </dl>

                    <section
                        aria-labelledby={`${headingId}-source`}
                        className="flex flex-col gap-1.5 rounded-md border border-border p-3 text-sm"
                    >
                        <h3
                            id={`${headingId}-source`}
                            className="font-semibold"
                        >
                            {t('admin.review.panel.source.heading')}
                        </h3>
                        <p>
                            {frame.declared_source.kind === 'tmdb'
                                ? t('admin.review.panel.source.tmdb')
                                : t('admin.review.panel.source.capture')}
                        </p>
                        <p>
                            <code className="rounded-sm bg-muted px-1 py-0.5 break-all">
                                {frame.declared_source.reference}
                            </code>
                        </p>
                        <p className="text-muted-foreground">
                            {t('admin.review.panel.source.notice')}
                        </p>
                    </section>

                    {inPlay && (
                        <Alert>
                            <InfoIcon aria-hidden />
                            <AlertDescription>
                                {t('admin.review.panel.in_play')}
                            </AlertDescription>
                        </Alert>
                    )}

                    {alreadyRejected && (
                        <Alert variant="destructive">
                            <TriangleAlertIcon aria-hidden />
                            <AlertTitle>
                                {t('admin.review.panel.previously_rejected', {
                                    items: failedItemLabels(
                                        frame.items,
                                        frame.failed_items,
                                        t,
                                    ).join(separator),
                                })}
                            </AlertTitle>
                        </Alert>
                    )}
                </div>

                <Form
                    {...FrameReviewController.store.form({
                        movie: frame.movie_id,
                        frame: frame.id,
                    })}
                    noValidate
                    options={{
                        preserveScroll: true,
                        preserveState: true,
                        only: REVIEW_WRITE_PROPS,
                    }}
                    onSuccess={() =>
                        onReviewed({
                            rejectedInPlay: failing && inPlay,
                            failedSlugs: failing ? failed : [],
                        })
                    }
                    onError={(errors) => onRefused(refusalMessages(errors))}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => {
                        const messages = refusalMessages(errors);

                        return (
                            <>
                                <input
                                    type="hidden"
                                    name="grid_version"
                                    value={frame.grid_version}
                                />
                                <input
                                    type="hidden"
                                    name="reviewed_hash"
                                    value={frame.published_hash}
                                />
                                <input
                                    type="hidden"
                                    name="declared_source_reference"
                                    value={frame.declared_source.reference}
                                />
                                {frame.items.map((item) => (
                                    <input
                                        key={item.slug}
                                        type="hidden"
                                        name={`answers[${item.slug}]`}
                                        value={
                                            failing &&
                                            failed.includes(item.slug)
                                                ? '0'
                                                : '1'
                                        }
                                    />
                                ))}

                                <section
                                    aria-labelledby={itemsHeadingId}
                                    className="flex flex-col gap-3"
                                >
                                    <div className="flex flex-col gap-1">
                                        <h3
                                            id={itemsHeadingId}
                                            className="text-sm font-semibold"
                                        >
                                            {t(
                                                'admin.review.panel.items.heading',
                                                {
                                                    version: frame.grid_version,
                                                },
                                            )}
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            {failing
                                                ? t(
                                                      'admin.review.panel.fail_hint',
                                                  )
                                                : t(
                                                      'admin.review.panel.items.description',
                                                      {
                                                          level: frame.frame_level,
                                                      },
                                                  )}
                                        </p>
                                    </div>

                                    <ul className="flex flex-col gap-3">
                                        {frame.items.map((item) => (
                                            <ReviewItem
                                                key={item.slug}
                                                item={item}
                                                failing={failing}
                                                checked={failed.includes(
                                                    item.slug,
                                                )}
                                                disabled={processing}
                                                onCheckedChange={(checked) =>
                                                    toggle(item.slug, checked)
                                                }
                                            />
                                        ))}
                                    </ul>
                                </section>

                                {messages.length > 0 && (
                                    <Alert variant="destructive">
                                        <TriangleAlertIcon aria-hidden />
                                        <AlertDescription>
                                            {messages.map((message) => (
                                                <p key={message}>{message}</p>
                                            ))}
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {failing ? (
                                    <div className="flex flex-col gap-2">
                                        {rejectBlocked && (
                                            <p
                                                id={blockedHintId}
                                                className="text-sm text-muted-foreground"
                                            >
                                                {t(
                                                    'admin.review.panel.reject_blocked',
                                                )}
                                            </p>
                                        )}
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                aria-disabled={
                                                    rejectBlocked || processing
                                                        ? true
                                                        : undefined
                                                }
                                                aria-describedby={
                                                    rejectBlocked
                                                        ? blockedHintId
                                                        : undefined
                                                }
                                                aria-busy={
                                                    processing || undefined
                                                }
                                                onClick={(event) => {
                                                    if (
                                                        rejectBlocked ||
                                                        processing
                                                    ) {
                                                        event.preventDefault();
                                                    }
                                                }}
                                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                            >
                                                <XIcon aria-hidden />
                                                {processing
                                                    ? t(
                                                          'admin.review.panel.sending',
                                                      )
                                                    : t(
                                                          'admin.review.panel.reject',
                                                      )}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={cancelFailing}
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                {t('admin.review.panel.cancel')}
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            aria-disabled={
                                                processing ? true : undefined
                                            }
                                            aria-busy={processing || undefined}
                                            onClick={(event) => {
                                                if (processing) {
                                                    event.preventDefault();
                                                }
                                            }}
                                            className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                        >
                                            <CheckIcon aria-hidden />
                                            {processing
                                                ? t(
                                                      'admin.review.panel.sending',
                                                  )
                                                : t('admin.review.panel.pass')}
                                        </Button>
                                        {!alreadyRejected && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => setFailing(true)}
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                <XIcon aria-hidden />
                                                {t('admin.review.panel.fail')}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </>
                        );
                    }}
                </Form>
            </div>
        </section>
    );
}

/**
 * Un item de la grille : libellé et aide, et — en « Non conforme » — la
 * case qui le déclare en défaut, étiquetée par son libellé et décrite par
 * son aide.
 */
function ReviewItem({
    item,
    failing,
    checked,
    disabled,
    onCheckedChange,
}: {
    item: AdminReviewItem;
    failing: boolean;
    checked: boolean;
    disabled: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    const { t } = useTranslations();
    const checkboxId = useId();
    const helpId = useId();

    return (
        <li className="flex gap-3 rounded-md border border-border p-3">
            {failing && (
                <Checkbox
                    id={checkboxId}
                    checked={checked}
                    disabled={disabled}
                    aria-describedby={helpId}
                    onCheckedChange={(state) => onCheckedChange(state === true)}
                    className="mt-0.5"
                />
            )}
            <div className="flex min-w-0 flex-col gap-1 text-sm">
                {failing ? (
                    <Label htmlFor={checkboxId} className="font-medium">
                        {t('admin.review.panel.items.failed', {
                            label: t(item.label_key),
                        })}
                    </Label>
                ) : (
                    <span className="font-medium">{t(item.label_key)}</span>
                )}
                <p id={helpId} className="text-muted-foreground">
                    {t(item.help_key)}
                </p>
            </div>
        </li>
    );
}
