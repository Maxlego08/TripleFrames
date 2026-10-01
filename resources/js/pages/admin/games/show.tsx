import { Head, Link } from '@inertiajs/react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import {
    InspectionParticipantAnswers,
    InspectionRoundHeader,
} from '@/components/admin/inspection-answers';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import {
    INSPECTION_DIFFICULTY_KEYS,
    INSPECTION_GAME_MODE_KEYS,
    INSPECTION_GAME_STATUS_KEYS,
    INSPECTION_SEAT_STATUS_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as gamesIndex } from '@/routes/admin/games';
import { show as playersShow } from '@/routes/admin/players';
import type {
    InspectionGameDetail,
    InspectionLeaderboardLine,
    InspectionRound,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    game: InspectionGameDetail;
    leaderboard: InspectionLeaderboardLine[];
    rounds: InspectionRound[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.games', href: gamesIndex() },
];

/**
 * La fiche d'une partie — spec 20 § 12.2 (D46 du 01/10), administrateur
 * seul : réglages figés, classement et, manche par manche, chaque réponse
 * juste ou fausse de chaque participant. Une manche non révélée d'une partie
 * en cours n'arrive qu'avec son numéro et son statut (règle 3).
 */
export default function AdminGamesShow({ game, leaderboard, rounds }: Props) {
    const { t, locale } = useTranslations();

    const summary: Array<[string, string]> = [
        [
            t('admin.inspection.game.summary.mode'),
            t(INSPECTION_GAME_MODE_KEYS[game.mode]),
        ],
        [
            t('admin.inspection.game.summary.room'),
            game.room_code ?? t('admin.common.none'),
        ],
        [
            t('admin.inspection.game.summary.status'),
            t(INSPECTION_GAME_STATUS_KEYS[game.status]),
        ],
        [
            t('admin.inspection.game.summary.difficulty'),
            t(INSPECTION_DIFFICULTY_KEYS[game.input_difficulty]),
        ],
        [
            t('admin.inspection.game.summary.frames_per_round'),
            formatInteger(game.frames_per_round, locale),
        ],
        [
            t('admin.inspection.game.summary.rounds'),
            t('admin.inspection.games.progress', {
                done: game.rounds_completed,
                total: game.rounds_count,
            }),
        ],
        [
            t('admin.inspection.game.summary.started_at'),
            formatMoment(game.started_at, locale) ?? t('admin.common.none'),
        ],
        [
            t('admin.inspection.game.summary.paused_at'),
            formatMoment(game.paused_at, locale) ?? t('admin.common.none'),
        ],
        [
            t('admin.inspection.game.summary.ended_at'),
            formatMoment(game.ended_at, locale) ?? t('admin.common.none'),
        ],
    ];

    const yesNo = (value: boolean): string =>
        t(
            value
                ? 'admin.inspection.game.settings.yes'
                : 'admin.inspection.game.settings.no',
        );
    const secondsOf = (value: number): string =>
        t('admin.inspection.game.settings.seconds', { value });

    const settings: Array<[string, string]> = [
        [
            t('admin.inspection.game.settings.round_duration'),
            secondsOf(game.settings.round_duration),
        ],
        [
            t('admin.inspection.game.settings.tier_durations'),
            game.settings.tier_durations.map(secondsOf).join(' · '),
        ],
        [
            t('admin.inspection.game.settings.tier_points'),
            game.settings.tier_points
                .map((points) => formatInteger(points, locale))
                .join(' · '),
        ],
        [
            t('admin.inspection.game.settings.reveal_duration'),
            secondsOf(game.settings.reveal_duration),
        ],
        [
            t('admin.inspection.game.settings.speed_bonus'),
            yesNo(game.settings.speed_bonus),
        ],
        [
            t('admin.inspection.game.settings.attempts_per_round'),
            formatInteger(game.settings.attempts_per_round, locale),
        ],
        [
            t('admin.inspection.game.settings.max_answer_length'),
            formatInteger(game.settings.max_answer_length, locale),
        ],
        [
            t('admin.inspection.game.settings.capacity'),
            formatInteger(game.settings.capacity, locale),
        ],
        [
            t('admin.inspection.game.settings.allow_late_join'),
            yesNo(game.settings.allow_late_join),
        ],
        [
            t('admin.inspection.game.settings.versions'),
            [
                game.versions.settings,
                game.versions.scoring,
                game.versions.validation,
            ].join(' · '),
        ],
    ];

    return (
        <>
            <Head title={t('admin.inspection.game.title', { id: game.id })} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.inspection.game.title', { id: game.id })}
                    description={t('admin.inspection.read_notice')}
                    actions={
                        <Button variant="outline" className="min-h-11" asChild>
                            <Link href={gamesIndex()}>
                                {t('admin.inspection.game.back')}
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <DefinitionCard
                        title={t('admin.inspection.game.summary.heading')}
                        items={summary}
                    />
                    <DefinitionCard
                        title={t('admin.inspection.game.settings.heading')}
                        items={settings}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.inspection.game.leaderboard.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        {leaderboard.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.inspection.game.leaderboard.empty')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.rank',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.player',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.status',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.score',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.correct',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.inspection.game.leaderboard.played',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leaderboard.map((line) => (
                                        <TableRow key={line.player.public_id}>
                                            <TableCell>
                                                {line.final_rank ??
                                                    t('admin.common.none')}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={playersShow(
                                                            line.player
                                                                .public_id,
                                                        )}
                                                        className="font-medium text-foreground underline-offset-4 hover:underline"
                                                    >
                                                        {line.player.nickname ??
                                                            t(
                                                                'admin.inspection.erased',
                                                            )}
                                                    </Link>
                                                    {line.player.user !==
                                                        null && (
                                                        <Badge variant="outline">
                                                            {
                                                                line.player.user
                                                                    .name
                                                            }
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    INSPECTION_SEAT_STATUS_KEYS[
                                                        line.status
                                                    ],
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {line.final_score === null
                                                    ? t('admin.common.none')
                                                    : formatInteger(
                                                          line.final_score,
                                                          locale,
                                                      )}
                                            </TableCell>
                                            <TableCell>
                                                {line.correct_answers ??
                                                    t('admin.common.none')}
                                            </TableCell>
                                            <TableCell>
                                                {line.rounds_played ??
                                                    t('admin.common.none')}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold text-foreground">
                        {t('admin.inspection.game.rounds.heading')}
                    </h2>

                    {rounds.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.inspection.game.rounds.empty')}
                        </p>
                    )}

                    {rounds.map((round) => (
                        <Card key={round.sequence_index}>
                            <CardContent className="space-y-4 pt-6">
                                <InspectionRoundHeader round={round} />

                                {round.disclosed && (
                                    <div className="space-y-2">
                                        <h4 className="text-sm font-medium text-foreground">
                                            {t(
                                                'admin.inspection.game.rounds.participants',
                                            )}
                                        </h4>
                                        {round.participants.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                {t(
                                                    'admin.inspection.game.rounds.no_participants',
                                                )}
                                            </p>
                                        ) : (
                                            <div className="grid gap-3 md:grid-cols-2">
                                                {round.participants.map(
                                                    (participant, index) => (
                                                        <InspectionParticipantAnswers
                                                            key={
                                                                participant.player_id ??
                                                                index
                                                            }
                                                            participant={
                                                                participant
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </div>
        </>
    );
}

AdminGamesShow.layout = { breadcrumbs };

/** Une carte de couples libellé–valeur, déjà traduits. */
function DefinitionCard({
    title,
    items,
}: {
    title: string;
    items: Array<[string, string]>;
}) {
    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>{title}</AdminCardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    {items.map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="break-words text-foreground">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}
