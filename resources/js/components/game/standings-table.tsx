import { LogOut, UserX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PlayerAvatar } from '@/components/game/player-avatar';
import { usePlayerLabel } from '@/components/game/player-ordinals';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { formatDuration, ordinalKey } from '@/lib/game/scoring-format';
import { cn } from '@/lib/utils';
import type { PlayerIdentity } from '@/types/player';
import type {
    Leaderboard,
    LeaderboardRow,
    PodiumStanding,
} from '@/types/scoring';
import type { TranslationKey } from '@/types/translations';

/**
 * Deux sources, un seul tableau : le classement intermédiaire (révélation,
 * resynchronisation) ou le classement final du podium.
 */
export type StandingsTableProps = (
    | {
          /**
           * Le classement intermédiaire (`Leaderboard`, § 9.2) : ni pseudo
           * ni avatar, lus dans `seats` par `publicId`.
           */
          leaderboard: Leaderboard;
          /** Les sièges de la partie (`state.seats`, identité gelée, C7). */
          seats: readonly PlayerIdentity[];
          standings?: undefined;
      }
    | {
          /** Le classement final du podium, identité gelée comprise (§ 11.3). */
          standings: readonly PodiumStanding[];
          leaderboard?: undefined;
          seats?: undefined;
      }
) & {
    /**
     * La légende reste lue au lecteur d'écran mais n'est pas affichée : un
     * titre visible la dit déjà (le podium, sous son titre).
     */
    captionHidden?: boolean;
    className?: string;
};

type SeatOutcome = PodiumStanding['status'];

/** Une ligne du tableau, quelle que soit sa source. */
type StandingLine = {
    identity: PlayerIdentity;
    rank: number | null;
    rankShared: boolean;
    score: number;
    correctAnswers: number;
    totalAnswerTimeMs: number;
    /** Points de la manche révélée ; nul au podium. */
    roundDelta: number | null;
    /** Manches jouées ; au podium seulement (00 § Déroulé, étape 8). */
    roundsPlayed: number | null;
    status: SeatOutcome;
    firstRoundNumber: number | null;
};

/**
 * Libellé et icône d'une issue de siège autre que « en jeu » : un siège
 * parti ou expulsé reste classé avec ses points (§ 8.5), et son issue se
 * dit par une icône ET un texte, jamais par la seule couleur (principe 8).
 */
const OUTCOMES: Record<
    Exclude<SeatOutcome, 'playing'>,
    { key: TranslationKey; icon: LucideIcon }
> = {
    left: { key: 'game.leaderboard.status.left', icon: LogOut },
    kicked: { key: 'game.leaderboard.status.kicked', icon: UserX },
};

/**
 * Identité de repli d'une ligne dont le siège manque aux sièges reçus — les
 * deux listes viennent des mêmes lignes `game_player`, le cas ne se produit
 * pas ; la ligne reste classée, jamais retirée (§ 8.5, aucun plafond).
 */
function unknownIdentity(publicId: string): PlayerIdentity {
    return {
        publicId,
        nickname: null,
        masked: false,
        avatar: {
            kind: null,
            url: null,
            altKey: 'common.avatar.alt.initials',
            initials: '?',
        },
    };
}

function fromLeaderboard(
    rows: readonly LeaderboardRow[],
    seats: readonly PlayerIdentity[],
    withDelta: boolean,
): StandingLine[] {
    return rows.map((row) => ({
        identity:
            seats.find((seat) => seat.publicId === row.publicId) ??
            unknownIdentity(row.publicId),
        rank: row.rank,
        rankShared: row.rankShared,
        score: row.score,
        correctAnswers: row.correctAnswers,
        totalAnswerTimeMs: row.totalAnswerTimeMs,
        roundDelta: withDelta ? row.roundDelta : null,
        roundsPlayed: null,
        status: row.status,
        firstRoundNumber: row.firstRoundNumber,
    }));
}

function fromPodium(standings: readonly PodiumStanding[]): StandingLine[] {
    return standings.map((standing) => ({
        identity: {
            publicId: standing.publicId,
            nickname: standing.nickname,
            masked: standing.masked,
            avatar: standing.avatar,
        },
        rank: standing.rank,
        rankShared: standing.rankShared,
        score: standing.finalScore,
        correctAnswers: standing.correctAnswers,
        totalAnswerTimeMs: standing.totalAnswerTimeMs,
        roundDelta: null,
        roundsPlayed: standing.roundsPlayed,
        status: standing.status,
        firstRoundNumber: standing.firstRoundNumber,
    }));
}

/*
 * Mise en page, portrait d'abord (règle 10, principe 5 ; § 20, L80-7).
 *
 * Le tableau est un conteneur (`@container`) : la mise en page suit la
 * largeur qui lui est donnée, pas celle de l'écran. Sous `@xl` (36 rem),
 * chaque ligne est une boîte flexible à deux rangées : la première porte le
 * rang, l'avatar et le pseudo, le score — la cellule du joueur prend
 * exactement la place que laissent le rang et le score, si bien que la
 * suite passe à la ligne —, la seconde le delta, les bonnes réponses et le
 * temps cumulé, en texte complet ; leurs en-têtes restent présents en
 * `sr-only`. La ligne est retraitée de la largeur du rang, que le rang
 * reprend par une marge négative : la seconde rangée, et ce qu'elle
 * renvoie à la ligne sur un écran étroit, s'aligne sous l'avatar. Aucune
 * colonne ajoutée, aucun défilement horizontal, quelle que soit la
 * longueur du classement (§ 8.5, aucun plafond). À partir de
 * `@xl`, le tableau retrouve ses colonnes, et ces cellules n'y portent plus
 * que la valeur, sous leur en-tête visible.
 *
 * Changer l'affichage d'une ligne de tableau peut, selon le navigateur,
 * retirer au tableau sa sémantique : chaque partie porte donc son rôle
 * explicite (`table`, `rowgroup`, `row`, `columnheader`, `rowheader`,
 * `cell`), qui la rétablit.
 */
const ROW = '@max-xl:flex @max-xl:flex-wrap @max-xl:items-start @max-xl:ps-18';
/**
 * Rang et score, centrés sur la rangée de l'avatar (`size-8` et le `p-2` de
 * la cellule : `min-h-12`), jamais sur la cellule du joueur entière.
 */
const NAME_LINE = '@max-xl:flex @max-xl:min-h-12 @max-xl:items-center';
const RANK = '@max-xl:-ms-18 @max-xl:w-18 @max-xl:shrink-0';
const PLAYER =
    'whitespace-normal @max-xl:min-w-0 @max-xl:grow @max-xl:basis-[calc(100%-7rem)] @xl:w-full';
const SCORE =
    'text-end @max-xl:w-28 @max-xl:shrink-0 @max-xl:whitespace-normal';
const DETAIL_HEAD = 'text-end whitespace-normal @max-xl:sr-only';
const DETAIL_CELL =
    'text-muted-foreground @max-xl:py-0.5 @max-xl:text-xs @max-xl:whitespace-normal @xl:text-end';

type StandingRowProps = {
    line: StandingLine;
    withDelta: boolean;
};

/** Une ligne du classement. */
function StandingRow({ line, withDelta }: StandingRowProps) {
    const { t, tChoice, locale } = useTranslations();
    const label = usePlayerLabel();
    const number = new Intl.NumberFormat(locale);
    const signed = new Intl.NumberFormat(locale, { signDisplay: 'always' });
    // « Joueur n » pour un pseudo masqué : le rang du siège dans le salon,
    // jamais sa place au classement (spec 40 § 13.3).
    const nickname = label(line.identity);
    const outcome = line.status === 'playing' ? null : OUTCOMES[line.status];
    const Outcome = outcome?.icon ?? null;
    const lateJoiner =
        line.firstRoundNumber !== null && line.firstRoundNumber > 1
            ? line.firstRoundNumber
            : null;
    const duration = formatDuration(line.totalAnswerTimeMs, locale);

    return (
        <TableRow role="row" className={ROW}>
            <TableCell role="cell" className={cn(RANK, NAME_LINE)}>
                {line.rank === null ? (
                    <>
                        {/* Glyphe décoratif, neutre en langue (§ 8.2). */}
                        <span aria-hidden="true">—</span>
                        <span className="sr-only">
                            {t('game.leaderboard.unranked')}
                        </span>
                    </>
                ) : (
                    <span className="flex flex-col">
                        <span className="font-semibold">
                            {t(ordinalKey(line.rank, locale), {
                                rank: number.format(line.rank),
                            })}
                        </span>
                        {line.rankShared && (
                            <span className="text-xs text-muted-foreground">
                                {t('game.leaderboard.rank_shared')}
                            </span>
                        )}
                    </span>
                )}
            </TableCell>

            <TableHead
                role="rowheader"
                scope="row"
                className={cn(PLAYER, 'h-auto py-2 font-normal')}
            >
                <span className="flex min-w-0 items-center gap-2">
                    <PlayerAvatar
                        avatar={line.identity.avatar}
                        alt=""
                        className="size-8 shrink-0 text-xs"
                    />
                    {/*
                     * `contain-inline-size` retire au tableau la largeur
                     * intrinsèque du pseudo (sans lui, un pseudo légal de
                     * vingt glyphes larges élargit le tableau au-delà de
                     * 360 px) ; `grow` rend au pseudo la place restante,
                     * où `truncate` l'abrège.
                     */}
                    <span className="min-w-0 grow truncate font-medium contain-inline-size">
                        {nickname}
                    </span>
                </span>

                {(outcome !== null ||
                    lateJoiner !== null ||
                    line.roundsPlayed !== null) && (
                    <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        {outcome !== null && Outcome !== null && (
                            <Badge variant="outline">
                                <Outcome aria-hidden="true" />
                                {t(outcome.key)}
                            </Badge>
                        )}
                        {lateJoiner !== null && (
                            <span>
                                {t('game.leaderboard.late_joiner', {
                                    round: number.format(lateJoiner),
                                })}
                            </span>
                        )}
                        {line.roundsPlayed !== null && (
                            <span>
                                {tChoice(
                                    'game.podium.rounds_played',
                                    line.roundsPlayed,
                                    { count: number.format(line.roundsPlayed) },
                                )}
                            </span>
                        )}
                    </span>
                )}
            </TableHead>

            <TableCell
                role="cell"
                className={cn(
                    SCORE,
                    NAME_LINE,
                    'font-semibold @max-xl:justify-end',
                )}
            >
                <span className="@xl:hidden">
                    {tChoice('game.score.points', line.score, {
                        count: number.format(line.score),
                    })}
                </span>
                <span className="@max-xl:hidden">
                    {number.format(line.score)}
                </span>
            </TableCell>

            {withDelta && line.roundDelta !== null && (
                <TableCell role="cell" className={DETAIL_CELL}>
                    <span className="@xl:hidden">
                        {t('game.leaderboard.round_delta', {
                            points: number.format(line.roundDelta),
                        })}
                    </span>
                    <span className="@max-xl:hidden">
                        {signed.format(line.roundDelta)}
                    </span>
                </TableCell>
            )}

            <TableCell role="cell" className={DETAIL_CELL}>
                <span className="@xl:hidden">
                    {tChoice(
                        'game.leaderboard.correct_answers',
                        line.correctAnswers,
                        { count: number.format(line.correctAnswers) },
                    )}
                </span>
                <span className="@max-xl:hidden">
                    {number.format(line.correctAnswers)}
                </span>
            </TableCell>

            <TableCell role="cell" className={DETAIL_CELL}>
                <span className="@xl:hidden">
                    {t('game.leaderboard.answer_time', { duration })}
                </span>
                <span className="@max-xl:hidden">{duration}</span>
            </TableCell>
        </TableRow>
    );
}

/**
 * Le classement (spec 80 § 8, § 9 et § 11.3, lot L80-7) : intermédiaire à
 * la révélation, final au podium. **Toutes** les lignes reçues, sans
 * plafond, dans l'ordre du serveur (§ 8.2 : rang croissant, sièges sans rang
 * en dernier) — le client ne classe rien.
 *
 * Chaque ligne : le rang ordinal (clé choisie par `Intl.PluralRules`,
 * `ORDINAL_KEYS`), « ex æquo » sur une place partagée, ou, pour un rang nul
 * — solo, siège sans manche jouée —, le glyphe « — » décoratif doublé de
 * `game.leaderboard.unranked` en `sr-only`, seule forme d'un rang nul
 * (§ 8.2, § 12) ; l'avatar, décoratif à côté du pseudo (C5 I5.9), par
 * `player-avatar.tsx`, jamais par le pipeline des images de jeu ; le
 * pseudo, en en-tête de ligne ; le score ; le delta de la manche révélée
 * (classement intermédiaire seulement, quand une manche est révélée) ; les
 * bonnes réponses ; le temps cumulé, en secondes dans la locale du joueur ;
 * l'issue d'un siège parti ou expulsé ; l'entrée tardive d'un retardataire
 * (`firstRoundNumber` > 1, § 8.5) ; au podium, les manches jouées.
 *
 * Accessibilité (principe 8) : un tableau de données, jamais une grille de
 * `div` — un `<caption>` (`game.leaderboard.title`, ou `game.podium.title`
 * au podium) et un en-tête dont chaque colonne porte sa clé
 * `game.leaderboard.column.*` ; le pseudo est l'en-tête de sa ligne. Rien de
 * focalisable : le lecteur d'écran le parcourt par ses commandes de
 * tableau, le clavier le fait défiler avec la page.
 *
 * Le changement de langue re-rend tout, sans rien recalculer (§ 9.5).
 * Composant de présentation (C16 § 2.9) : ni Echo, ni horloge, des props
 * seulement ; tokens seulement.
 */
export function StandingsTable(props: StandingsTableProps) {
    const { t } = useTranslations();
    const withDelta =
        props.leaderboard !== undefined &&
        props.leaderboard.roundNumber !== null;
    const lines =
        props.standings !== undefined
            ? fromPodium(props.standings)
            : fromLeaderboard(props.leaderboard.rows, props.seats, withDelta);

    return (
        <div className={cn('@container', props.className)}>
            <Table role="table" className="caption-top">
                <TableCaption
                    className={cn(
                        'mt-0 mb-2 text-start text-base font-semibold text-foreground',
                        props.captionHidden === true && 'sr-only',
                    )}
                >
                    {props.standings !== undefined
                        ? t('game.podium.title')
                        : t('game.leaderboard.title')}
                </TableCaption>

                <TableHeader role="rowgroup">
                    <TableRow role="row" className={ROW}>
                        <TableHead
                            role="columnheader"
                            scope="col"
                            className={RANK}
                        >
                            {t('game.leaderboard.column.rank')}
                        </TableHead>
                        <TableHead
                            role="columnheader"
                            scope="col"
                            className={PLAYER}
                        >
                            {t('game.leaderboard.column.player')}
                        </TableHead>
                        <TableHead
                            role="columnheader"
                            scope="col"
                            className={SCORE}
                        >
                            {t('game.leaderboard.column.score')}
                        </TableHead>
                        {withDelta && (
                            <TableHead
                                role="columnheader"
                                scope="col"
                                className={DETAIL_HEAD}
                            >
                                {t('game.leaderboard.column.round_delta')}
                            </TableHead>
                        )}
                        <TableHead
                            role="columnheader"
                            scope="col"
                            className={DETAIL_HEAD}
                        >
                            {t('game.leaderboard.column.correct_answers')}
                        </TableHead>
                        <TableHead
                            role="columnheader"
                            scope="col"
                            className={DETAIL_HEAD}
                        >
                            {t('game.leaderboard.column.answer_time')}
                        </TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody role="rowgroup">
                    {lines.map((line) => (
                        <StandingRow
                            key={line.identity.publicId}
                            line={line}
                            withDelta={withDelta}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
