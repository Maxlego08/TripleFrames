import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { GameFrame } from '@/components/game/game-frame';
import type { FrameFormat } from '@/components/game/game-frame';
import { LetterboxdLink } from '@/components/game/letterboxd-link';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { ReportLink } from '@/components/game/report-link';
import { StandingsTable } from '@/components/game/standings-table';
import { TmdbAttribution } from '@/components/public/tmdb-attribution';
import { useTranslations } from '@/hooks/use-translations';
import type { FrameView } from '@/lib/game/frame-loader';
import {
    formatDuration,
    ordinalKey,
    recapTitles,
    titleSegments,
} from '@/lib/game/scoring-format';
import type { RevealFrame, RevealMovie, SeatView } from '@/types/game-wire';
import type { Leaderboard, RoundFinder } from '@/types/scoring';

export type RoundRevealProps = {
    roundNumber: number;
    roundsCount: number;
    /** Le film révélé (`round.revealed.movie`, `RoundState.reveal.movie`). */
    movie: RevealMovie;
    /**
     * L'identité publique des images servies, par palier ouvert
     * (`reveal.frames`, D63 du 07/10) : le lien « Signaler cette image ».
     */
    frames: readonly RevealFrame[];
    /** Qui a trouvé, à quel palier, en combien de temps, pour combien (C13). */
    finders: readonly RoundFinder[];
    /** Les sièges de la partie : pseudo et avatar des trouveurs et du classement. */
    seats: readonly SeatView[];
    /** Le classement intermédiaire, gelé à cette manche révélée. */
    leaderboard: Leaderboard;
    /**
     * Les images des paliers **ouverts**, par `tierIndex` croissant (D14 du
     * 23/09), depuis les blobs gardés par `frame-loader` ; nulles : le siège
     * n'en voit aucune (retardataire en attente, siège sans participation).
     */
    images: readonly { tierIndex: number; view: FrameView }[] | null;
    /** `N` : le total de l'attribut `alt` neutre de chaque image. */
    tierCount: number;
    /** Prop partagée `frameFormat` (C9). */
    frameFormat: FrameFormat;
    /** Millisecondes jusqu'à la manche suivante déjà programmée ; nul sinon. */
    nextStartsInMs: number | null;
    /** Le geste « manche suivante » (hôte au salon, joueur en solo) ; nul sinon. */
    action?: ReactNode;
};

/** Une seconde en millisecondes : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/**
 * La révélation d'une manche (spec 60 § 9.5, D14 du 23/09 ; 90 § 10, état
 * « Révélation » ; 00 § Le jeu en une manche) — et rien d'autre :
 *
 * - les **images déjà servies** — les paliers ouverts, dans l'ordre, chacune
 *   dans un `GameFrame` : jamais un cadre pour un palier non ouvert ;
 * - le **titre dans la langue du joueur**, le **titre original s'il diffère**
 *   et l'**année**, par l'assistant unique `revealTitles()` (via
 *   `recapTitles()`, qui réduit la locale active à celles du paquet) : chaque
 *   titre dans un fragment qui porte son `lang` (05), jamais interpolé en
 *   texte brut, puis le **lien Letterboxd** (D58 du 06/10) et le lien
 *   « Signaler » du film, et un lien « Signaler » sous chaque image
 *   (D63 du 07/10). Le paquet porte les titres de toutes les locales activées ;
 *   le client choisit le sien **à l'affichage**, et une révélation déjà
 *   affichée ne se recompose pas au changement de langue (05 § exceptions,
 *   60 § 9.5) : la locale des titres est figée au montage, les libellés
 *   autour suivent la langue ;
 * - **qui a trouvé** (`finders`), à quel palier, en combien de temps et pour
 *   combien de points, puis le **classement intermédiaire** avec avatars ;
 * - l'**attribution TMDB** (principe 12, 00 § Ouverture).
 *
 * Défile dans la `ScrollArea` de la page. **Focus** au titre de révélation
 * (`tabIndex={-1}`) au montage — la page monte une révélation neuve par
 * manche ; sur mobile, le clavier se ferme, ce qui est voulu : la saisie est
 * close (90 § 7.5).
 *
 * Composant de présentation : ni Echo, ni horloge, ni requête (C16 § 2.9) ;
 * tokens seulement.
 */
export function RoundReveal({
    roundNumber,
    roundsCount,
    movie,
    frames,
    finders,
    seats,
    leaderboard,
    images,
    tierCount,
    frameFormat,
    nextStartsInMs,
    action,
}: RoundRevealProps) {
    const { t, tChoice, locale } = useTranslations();
    const headingId = useId();
    const findersId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const number = new Intl.NumberFormat(locale);
    // Figée au montage : une révélation déjà affichée ne se recompose pas.
    const [titleLocale] = useState(locale);
    const { title, original, year } = recapTitles(movie, titleLocale);
    const seatOf = new Map(seats.map((seat) => [seat.publicId, seat]));
    const framePublicIdOf = new Map(
        frames.map((frame) => [frame.tierIndex, frame.framePublicId]),
    );
    const nextSeconds =
        nextStartsInMs === null
            ? null
            : Math.max(0, Math.ceil(nextStartsInMs / MS_PER_SECOND));

    // Une révélation neuve par manche : le focus va à son titre au montage.
    useEffect(() => headingRef.current?.focus(), []);

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">
                    {t('game.round.number', {
                        number: number.format(roundNumber),
                        total: number.format(roundsCount),
                    })}
                </p>

                <h2
                    id={headingId}
                    ref={headingRef}
                    tabIndex={-1}
                    className="flex flex-col gap-1 outline-none"
                >
                    <span className="text-sm font-normal text-muted-foreground">
                        {t('game.reveal.heading')}
                    </span>
                    <span
                        lang={title.lang}
                        className="text-2xl font-semibold text-balance"
                    >
                        {title.text}
                    </span>
                </h2>

                {original !== null && (
                    <p className="text-sm text-muted-foreground">
                        {titleSegments(
                            t('game.reveal.original_title'),
                            original,
                        ).map((segment, index) =>
                            segment.kind === 'title' ? (
                                <span key={index} lang={segment.lang}>
                                    {segment.text}
                                </span>
                            ) : (
                                <span key={index}>{segment.text}</span>
                            ),
                        )}
                    </p>
                )}

                {year !== null && (
                    <p className="text-sm text-muted-foreground">
                        {t('game.reveal.year', { year: String(year) })}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    <LetterboxdLink
                        url={movie.letterboxdUrl}
                        title={title.text}
                    />
                    <ReportLink
                        kind="movie"
                        tmdb={movie.tmdb}
                        title={title.text}
                    />
                </div>
            </div>

            {images !== null && images.length > 0 && (
                <ul
                    aria-label={t('game.reveal.images')}
                    className="grid grid-cols-2 gap-2 sm:grid-cols-3"
                >
                    {images.map((image) => {
                        const framePublicId = framePublicIdOf.get(
                            image.tierIndex,
                        );

                        return (
                            <li
                                key={image.tierIndex}
                                className="flex flex-col gap-1"
                            >
                                <GameFrame
                                    src={image.view.src}
                                    pending={image.view.pending}
                                    alt={t('game.frame.alt', {
                                        index: number.format(image.tierIndex),
                                        total: number.format(tierCount),
                                    })}
                                    loadingLabel={t('game.frame.loading')}
                                    unavailableLabel={t(
                                        'game.frame.unavailable',
                                    )}
                                    format={frameFormat}
                                    className="rounded-md"
                                />
                                {framePublicId !== undefined && (
                                    <ReportLink
                                        kind="frame"
                                        tmdb={movie.tmdb}
                                        framePublicId={framePublicId}
                                        index={number.format(image.tierIndex)}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            <section
                aria-labelledby={findersId}
                className="flex flex-col gap-2"
            >
                <h3 id={findersId} className="font-semibold">
                    {t('game.reveal.finders')}
                </h3>

                {finders.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('game.recap.nobody')}
                    </p>
                ) : (
                    <ol className="flex flex-col gap-2">
                        {finders.map((finder) => {
                            const seat = seatOf.get(finder.publicId);

                            return (
                                <li
                                    key={finder.publicId}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border px-3 py-2 text-sm"
                                >
                                    <span className="font-semibold tabular-nums">
                                        {t(
                                            ordinalKey(finder.lockRank, locale),
                                            {
                                                rank: number.format(
                                                    finder.lockRank,
                                                ),
                                            },
                                        )}
                                    </span>

                                    {seat !== undefined && (
                                        <PlayerAvatar
                                            avatar={seat.avatar}
                                            alt=""
                                            className="size-8 text-xs"
                                        />
                                    )}

                                    {/* Pseudo abrégé sans élargir la ligne
                                        (`contain-inline-size`, E118-7). */}
                                    <span className="min-w-0 flex-1 truncate font-medium contain-inline-size">
                                        {seat?.nickname ??
                                            seat?.avatar.initials ??
                                            ''}
                                    </span>

                                    <span className="tabular-nums">
                                        {tChoice(
                                            'game.score.points',
                                            finder.pointsTotal,
                                            {
                                                count: number.format(
                                                    finder.pointsTotal,
                                                ),
                                            },
                                        )}
                                    </span>

                                    <span className="text-muted-foreground">
                                        {t('game.reveal.finder_tier', {
                                            index: number.format(
                                                finder.tierIndex,
                                            ),
                                        })}
                                    </span>

                                    <span className="text-muted-foreground tabular-nums">
                                        {formatDuration(
                                            finder.answeredAtMs,
                                            locale,
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ol>
                )}
            </section>

            <StandingsTable leaderboard={leaderboard} seats={seats} />

            {nextSeconds !== null && (
                <p className="text-muted-foreground">
                    {tChoice('game.round.starts_in', nextSeconds, {
                        seconds: number.format(nextSeconds),
                    })}
                </p>
            )}

            {action}

            <TmdbAttribution />
        </section>
    );
}
