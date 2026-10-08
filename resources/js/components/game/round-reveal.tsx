import { CircleCheck, Clock3 } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { GameFrame } from '@/components/game/game-frame';
import type { FrameFormat } from '@/components/game/game-frame';
import { LetterboxdLink } from '@/components/game/letterboxd-link';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { usePlayerLabel } from '@/components/game/player-ordinals';
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
    /** `state.self.publicId` : sa ligne de trouveur est mise en avant. */
    selfPublicId: string;
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
 * **Présentation** (design du 08/10, aucune maquette dédiée : composée avec
 * les pièces de `design-test/html/game.html` et `game-results.html`) : carte
 * « papier » du titre et de ses liens, vignettes des images servies sous la
 * carte, panneaux sombres « Ont trouvé » (lignes `ranking` de
 * `game-results.html`) et classement à côté (dessous en portrait), puis le
 * décompte de la manche suivante et son geste. Styles : `game.scss`.
 *
 * Défile dans `main` (écran `game--reveal`). **Focus** au titre de révélation
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
    selfPublicId,
    images,
    tierCount,
    frameFormat,
    nextStartsInMs,
    action,
}: RoundRevealProps) {
    const { t, tChoice, locale } = useTranslations();
    const label = usePlayerLabel();
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
        <section aria-labelledby={headingId} className="reveal">
            <div className="reveal__main">
                <div className="reveal-card">
                    <p className="reveal-card__round">
                        {t('game.round.number', {
                            number: number.format(roundNumber),
                            total: number.format(roundsCount),
                        })}
                    </p>

                    <h2
                        id={headingId}
                        ref={headingRef}
                        tabIndex={-1}
                        className="reveal-card__heading"
                    >
                        <span className="reveal-card__label">
                            {t('game.reveal.heading')}
                        </span>
                        <span lang={title.lang} className="reveal-card__title">
                            {title.text}
                        </span>
                    </h2>

                    {(original !== null || year !== null) && (
                        <p className="reveal-card__meta">
                            {original !== null && (
                                <span>
                                    {titleSegments(
                                        t('game.reveal.original_title'),
                                        original,
                                    ).map((segment, index) =>
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
                            )}
                            {year !== null && (
                                <span>
                                    {t('game.reveal.year', {
                                        year: String(year),
                                    })}
                                </span>
                            )}
                        </p>
                    )}

                    <div className="reveal-card__links">
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
                        className="reveal-frames"
                    >
                        {images.map((image) => {
                            const framePublicId = framePublicIdOf.get(
                                image.tierIndex,
                            );

                            return (
                                <li
                                    key={image.tierIndex}
                                    className="reveal-frames__item"
                                >
                                    <div className="reveal-frames__frame">
                                        <span
                                            className="reveal-frames__index"
                                            aria-hidden="true"
                                        >
                                            {t('game.frame.status', {
                                                index: number.format(
                                                    image.tierIndex,
                                                ),
                                                total: number.format(tierCount),
                                            })}
                                        </span>
                                        <GameFrame
                                            src={image.view.src}
                                            pending={image.view.pending}
                                            alt={t('game.frame.alt', {
                                                index: number.format(
                                                    image.tierIndex,
                                                ),
                                                total: number.format(tierCount),
                                            })}
                                            loadingLabel={t(
                                                'game.frame.loading',
                                            )}
                                            unavailableLabel={t(
                                                'game.frame.unavailable',
                                            )}
                                            format={frameFormat}
                                        />
                                    </div>
                                    {framePublicId !== undefined && (
                                        <ReportLink
                                            kind="frame"
                                            tmdb={movie.tmdb}
                                            framePublicId={framePublicId}
                                            index={number.format(
                                                image.tierIndex,
                                            )}
                                        />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <div className="reveal__side">
                <section aria-labelledby={findersId} className="reveal-panel">
                    <div className="reveal-panel__heading">
                        <CircleCheck aria-hidden="true" />
                        <h3 id={findersId}>{t('game.reveal.finders')}</h3>
                    </div>

                    {finders.length === 0 ? (
                        <p className="reveal-panel__empty">
                            {t('game.recap.nobody')}
                        </p>
                    ) : (
                        <ol className="ranking">
                            {finders.map((finder) => {
                                const seat = seatOf.get(finder.publicId);

                                return (
                                    <li
                                        key={finder.publicId}
                                        className={
                                            finder.publicId === selfPublicId
                                                ? 'ranking__item ranking__item--current'
                                                : 'ranking__item'
                                        }
                                    >
                                        <span className="ranking__position">
                                            {t(
                                                ordinalKey(
                                                    finder.lockRank,
                                                    locale,
                                                ),
                                                {
                                                    rank: number.format(
                                                        finder.lockRank,
                                                    ),
                                                },
                                            )}
                                        </span>

                                        {seat !== undefined ? (
                                            <PlayerAvatar
                                                avatar={seat.avatar}
                                                alt=""
                                                className="player-avatar"
                                            />
                                        ) : (
                                            <span aria-hidden="true" />
                                        )}

                                        {/* Pseudo abrégé sans élargir la
                                            ligne (E118-7). */}
                                        <span className="ranking__identity">
                                            <span className="ranking__name">
                                                {seat === undefined
                                                    ? ''
                                                    : label(seat)}
                                            </span>
                                            <span className="ranking__detail">
                                                {t('game.reveal.finder_tier', {
                                                    index: number.format(
                                                        finder.tierIndex,
                                                    ),
                                                })}
                                                {' · '}
                                                {formatDuration(
                                                    finder.answeredAtMs,
                                                    locale,
                                                )}
                                            </span>
                                        </span>

                                        <span className="ranking__score">
                                            {tChoice(
                                                'game.score.points_short',
                                                finder.pointsTotal,
                                                {
                                                    count: number.format(
                                                        finder.pointsTotal,
                                                    ),
                                                },
                                            )}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </section>

                <section className="reveal-panel reveal-standings">
                    <StandingsTable leaderboard={leaderboard} seats={seats} />
                </section>
            </div>

            <div className="reveal__footer">
                {nextSeconds !== null && (
                    <p className="reveal__next">
                        <Clock3 aria-hidden="true" />
                        {tChoice('game.round.starts_in', nextSeconds, {
                            seconds: number.format(nextSeconds),
                        })}
                    </p>
                )}

                {action}

                <TmdbAttribution />
            </div>
        </section>
    );
}
