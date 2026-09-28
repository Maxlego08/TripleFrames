import { Form, usePage } from '@inertiajs/react';
import { CropIcon, LayersIcon, RotateCwIcon, EyeOffIcon } from 'lucide-react';
import { useId } from 'react';
import FrameRetryController from '@/actions/App/Http/Controllers/Admin/FrameRetryController';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import {
    FRAME_LEVEL_KEYS,
    FRAME_LEVELS,
} from '@/components/admin/level-picker';
import { GameFrame } from '@/components/game/game-frame';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { BANK_WRITE_PROPS } from '@/lib/admin/bank-visits';
import type {
    AdminMovieFrame,
    FrameCurationState,
    FrameLevel,
    FrameSourceKind,
} from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/** Les gestes d'une image qui passent par une confirmation. */
export type FrameGestureKind = 'recrop' | 'level' | 'unpublish';

/** Une image désignée à l'écran : l'image, et son rang dans son niveau. */
export type FrameGestureTarget = {
    frame: AdminMovieFrame;
    index: number;
};

/** Chaque état affiché, et la clé qui le nomme. */
export const FRAME_CURATION_STATE_KEYS: Record<
    FrameCurationState,
    TranslationKey
> = {
    processing: 'admin.bank.state.processing',
    failed: 'admin.bank.state.failed',
    awaiting_review: 'admin.bank.state.awaiting_review',
    rejected: 'admin.bank.state.rejected',
    in_play: 'admin.bank.state.in_play',
    set_aside: 'admin.bank.state.set_aside',
    locked: 'admin.bank.state.locked',
};

/**
 * La gravité d'un état, portée par la variante du badge — jamais par la
 * seule couleur : le badge dit l'état en toutes lettres.
 */
const STATE_VARIANTS: Record<
    FrameCurationState,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    processing: 'secondary',
    failed: 'destructive',
    awaiting_review: 'secondary',
    rejected: 'destructive',
    in_play: 'default',
    set_aside: 'outline',
    locked: 'outline',
};

const SOURCE_KEYS: Record<FrameSourceKind, TranslationKey> = {
    tmdb: 'admin.bank.list.source.tmdb',
    capture: 'admin.bank.list.source.capture',
};

/**
 * Re-recadrer demande un rendu (la source de recadrage n'existe qu'après un
 * premier traitement réussi), une image ni en traitement, ni verrouillée, ni
 * écartée — une image écartée ne revient jamais en revue (§ 8.4).
 */
export function canRecrop(frame: AdminMovieFrame): boolean {
    return (
        frame.master_url !== null &&
        !['processing', 'locked', 'set_aside'].includes(frame.curation_state)
    );
}

/** Changer le niveau : toute image ni verrouillée ni écartée (§ 5.7). */
export function canChangeLevel(frame: AdminMovieFrame): boolean {
    return !['locked', 'set_aside'].includes(frame.curation_state);
}

/**
 * Dépublier une image publiée, ou écarter une image jamais publiée : les
 * deux états sources de `FramePolicy::unpublish` (§ 8.4).
 */
export function unpublishKind(
    frame: AdminMovieFrame,
): 'unpublish' | 'set_aside' | null {
    if (frame.availability === 'published') {
        return 'unpublish';
    }

    return frame.availability === 'draft' ? 'set_aside' : null;
}

type Props = {
    frames: AdminMovieFrame[];
    movieId: number;
    /** La voie capture est-elle offerte sur ce site ? L'état vide ne propose que ce qui l'est. */
    captureEnabled: boolean;
    onGesture: (kind: FrameGestureKind, target: FrameGestureTarget) => void;
};

/**
 * La banque du film, groupée par niveau (spec 20 § 6.1) : chaque image avec
 * son rendu, son état — en traitement, en attente de revue, rejetée, en jeu,
 * écartée, en échec —, sa source déclarée et ses gestes : re-recadrer,
 * relancer, changer de niveau, écarter ou dépublier.
 *
 * Un geste n'est offert que là où le serveur l'accepte, mais le serveur
 * revalide tout : les refus d'état restent des erreurs traduites (§ 2.9).
 * « Relancer » part tel quel, sans confirmation (§ 5.6) — il ne change ni la
 * disponibilité ni le cadre. Les trois autres gestes ouvrent une
 * confirmation, que l'éditeur tient (`onGesture`).
 *
 * Le rendu d'une image est l'aperçu admin (C9-bis), dans le conteneur de jeu
 * `GameFrame` : aucune URL n'est reconstruite ici.
 */
export function FrameBankList({
    frames,
    movieId,
    captureEnabled,
    onGesture,
}: Props) {
    const { t } = useTranslations();

    if (frames.length === 0) {
        return (
            <AdminEmptyState
                title={t(
                    captureEnabled
                        ? 'admin.bank.list.empty'
                        : 'admin.bank.list.empty_tmdb_only',
                )}
            />
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {FRAME_LEVELS.map((level) => (
                <LevelGroup
                    key={level}
                    level={level}
                    frames={frames.filter(
                        (frame) => frame.frame_level === level,
                    )}
                    movieId={movieId}
                    onGesture={onGesture}
                />
            ))}
        </div>
    );
}

function LevelGroup({
    level,
    frames,
    movieId,
    onGesture,
}: {
    level: FrameLevel;
    frames: AdminMovieFrame[];
    movieId: number;
    onGesture: Props['onGesture'];
}) {
    const { t } = useTranslations();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3">
            <h3
                id={headingId}
                className="text-sm font-semibold text-foreground"
            >
                {t('admin.level.option', {
                    level,
                    label: t(FRAME_LEVEL_KEYS[level].label),
                })}
            </h3>

            {frames.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('admin.bank.list.level_empty')}
                </p>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {frames.map((frame, position) => (
                        <FrameCard
                            key={frame.id}
                            frame={frame}
                            index={position + 1}
                            movieId={movieId}
                            onGesture={onGesture}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function FrameCard({
    frame,
    index,
    movieId,
    onGesture,
}: {
    frame: AdminMovieFrame;
    index: number;
    movieId: number;
    onGesture: Props['onGesture'];
}) {
    const { t } = useTranslations();
    const format = usePage().props.frameFormat;
    const titleId = useId();
    const target: FrameGestureTarget = { frame, index };
    const unpublish = unpublishKind(frame);
    const retryable = frame.is_retryable && frame.curation_state === 'failed';

    return (
        <li className="min-w-0">
            <article
                aria-labelledby={titleId}
                className="flex h-full flex-col gap-3 rounded-md border border-border bg-card p-3"
            >
                <GameFrame
                    src={frame.game_url}
                    alt={t('admin.frame.preview.alt', {
                        level: frame.frame_level,
                    })}
                    loadingLabel={t('admin.frame.preview.loading')}
                    unavailableLabel={t('admin.frame.preview.unavailable')}
                    format={format}
                    className="rounded-sm"
                />

                <div className="flex flex-col gap-1.5">
                    <h4
                        id={titleId}
                        className="text-sm font-medium text-card-foreground"
                    >
                        {t('admin.bank.list.image', {
                            index,
                            level: frame.frame_level,
                        })}
                    </h4>
                    <div className="flex flex-wrap items-center gap-1">
                        <Badge variant={STATE_VARIANTS[frame.curation_state]}>
                            {t(FRAME_CURATION_STATE_KEYS[frame.curation_state])}
                        </Badge>
                        {frame.review_outdated && (
                            <Badge variant="outline">
                                {t('admin.bank.list.review_outdated')}
                            </Badge>
                        )}
                        {frame.review_rejected &&
                            frame.curation_state === 'in_play' && (
                                <Badge variant="destructive">
                                    {t('admin.bank.list.review_rejected')}
                                </Badge>
                            )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t(SOURCE_KEYS[frame.source_kind])}
                    </p>
                    {frame.processing_error !== null && (
                        <p className="text-xs text-destructive">
                            {t(frame.processing_error)}
                        </p>
                    )}
                </div>

                <div
                    role="group"
                    aria-label={t('admin.bank.list.actions', {
                        index,
                        level: frame.frame_level,
                    })}
                    className="mt-auto flex flex-wrap gap-2"
                >
                    {canRecrop(frame) && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11"
                            onClick={() => onGesture('recrop', target)}
                        >
                            <CropIcon aria-hidden />
                            {t('admin.bank.list.recrop')}
                        </Button>
                    )}

                    {retryable && (
                        <Form
                            {...FrameRetryController.store.form({
                                movie: movieId,
                                frame: frame.id,
                            })}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                                only: BANK_WRITE_PROPS,
                            }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        className="min-h-11"
                                        disabled={processing}
                                        aria-busy={processing || undefined}
                                    >
                                        <RotateCwIcon aria-hidden />
                                        {t('admin.bank.list.retry')}
                                    </Button>
                                    {errors.frame && (
                                        <p
                                            role="alert"
                                            className="text-xs text-destructive"
                                        >
                                            {errors.frame}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    )}

                    {canChangeLevel(frame) && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11"
                            onClick={() => onGesture('level', target)}
                        >
                            <LayersIcon aria-hidden />
                            {t('admin.bank.list.change_level')}
                        </Button>
                    )}

                    {unpublish !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11"
                            onClick={() => onGesture('unpublish', target)}
                        >
                            <EyeOffIcon aria-hidden />
                            {unpublish === 'unpublish'
                                ? t('admin.bank.list.unpublish')
                                : t('admin.bank.list.set_aside')}
                        </Button>
                    )}
                </div>
            </article>
        </li>
    );
}
