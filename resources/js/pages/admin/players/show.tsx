import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import {
    InspectionParticipantAnswers,
    InspectionRoundHeader,
} from '@/components/admin/inspection-answers';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import {
    INSPECTION_CONNECTION_KEYS,
    INSPECTION_GAME_MODE_KEYS,
    INSPECTION_GAME_STATUS_KEYS,
    INSPECTION_SEAT_STATUS_KEYS,
    localeLabel,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as gamesShow } from '@/routes/admin/games';
import {
    index as playersIndex,
    show as playersShow,
} from '@/routes/admin/players';
import { show as usersShow } from '@/routes/admin/users';
import type {
    InspectionConnectionState,
    InspectionDevice,
    InspectionPlayer,
    InspectionPlayerGame,
    InspectionVisitor,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    player: InspectionPlayer & {
        locale: string;
        connection_state: InspectionConnectionState;
        left_at: string | null;
        kicked_at: string | null;
        nickname_masked_at: string | null;
        device: InspectionDevice | null;
    };
    visitor: InspectionVisitor | null;
    games: InspectionPlayerGame[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.players', href: playersIndex() },
];

/**
 * La fiche d'un siège — spec 20 § 12.2 (D46 du 01/10), administrateur seul :
 * identité, et chacune de ses parties, manche par manche, avec sa bonne
 * réponse et ses réponses fausses (règle 3 tenue par le serveur).
 */
/** « mobile · chrome · android » : les trois familles, jointes, sans traduction (ce sont des noms de famille techniques). */
function deviceLabel(device: InspectionDevice | null, none: string): string {
    if (device === null) {
        return none;
    }

    return [device.class, device.browser, device.os]
        .filter((part): part is string => part !== null)
        .join(' · ');
}

export default function AdminPlayersShow({ player, visitor, games }: Props) {
    const { t, locale } = useTranslations();
    const name = player.nickname ?? t('admin.inspection.erased');
    const none = t('admin.common.none');

    const identity: Array<[string, ReactNode]> = [
        [t('admin.inspection.player.identity.public_id'), player.public_id],
        [
            t('admin.inspection.player.identity.origin'),
            player.solo
                ? t('admin.inspection.solo')
                : (player.room_code ?? none),
        ],
        [
            t('admin.inspection.player.identity.account'),
            player.user === null ? (
                none
            ) : (
                <Link
                    href={usersShow(player.user.id)}
                    className="underline-offset-4 hover:underline"
                >
                    {player.user.name}
                </Link>
            ),
        ],
        [
            t('admin.inspection.player.identity.locale'),
            localeLabel(player.locale, t),
        ],
        [
            t('admin.inspection.player.identity.connection'),
            t(INSPECTION_CONNECTION_KEYS[player.connection_state]),
        ],
        [
            t('admin.inspection.player.identity.joined_at'),
            formatMoment(player.joined_at, locale) ?? none,
        ],
        [
            t('admin.inspection.player.identity.last_seen_at'),
            formatMoment(player.last_seen_at, locale) ?? none,
        ],
        [
            t('admin.inspection.player.identity.left_at'),
            formatMoment(player.left_at, locale) ?? none,
        ],
        [
            t('admin.inspection.player.identity.kicked_at'),
            formatMoment(player.kicked_at, locale) ?? none,
        ],
        [
            t('admin.inspection.player.identity.device'),
            deviceLabel(player.device, none),
        ],
    ];

    return (
        <>
            <Head title={t('admin.inspection.player.title', { name })} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.inspection.player.title', { name })}
                    description={t('admin.inspection.read_notice')}
                    actions={
                        <Button variant="outline" className="min-h-11" asChild>
                            <Link href={playersIndex()}>
                                {t('admin.inspection.player.back')}
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <AdminCardTitle>
                                {t('admin.inspection.player.identity.heading')}
                            </AdminCardTitle>
                            {player.masked && (
                                <Badge variant="secondary">
                                    {t('admin.inspection.masked')}
                                </Badge>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            {identity.map(([label, value]) => (
                                <div key={label} className="min-w-0">
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="break-words text-foreground">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.inspection.player.visitor.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {visitor === null ? (
                            <p className="text-muted-foreground">
                                {t('admin.inspection.player.visitor.none')}
                            </p>
                        ) : (
                            <>
                                <p className="text-muted-foreground">
                                    {t(
                                        'admin.inspection.player.visitor.since',
                                        {
                                            consented:
                                                formatMoment(
                                                    visitor.consented_at,
                                                    locale,
                                                ) ?? none,
                                            first:
                                                formatMoment(
                                                    visitor.first_seen_at,
                                                    locale,
                                                ) ?? none,
                                        },
                                    )}
                                </p>
                                {visitor.seats.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        {t(
                                            'admin.inspection.player.visitor.no_other',
                                        )}
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {visitor.seats.map((seat) => (
                                            <li
                                                key={seat.public_id}
                                                className="flex flex-wrap items-center gap-x-3 gap-y-1"
                                            >
                                                <Link
                                                    href={playersShow(
                                                        seat.public_id,
                                                    )}
                                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                                >
                                                    {seat.nickname ??
                                                        t(
                                                            'admin.inspection.erased',
                                                        )}
                                                </Link>
                                                <span className="text-muted-foreground">
                                                    {formatMoment(
                                                        seat.joined_at,
                                                        locale,
                                                    ) ?? none}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {deviceLabel(
                                                        seat.device,
                                                        none,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold text-foreground">
                        {t('admin.inspection.player.games.heading')}
                    </h2>

                    {games.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.inspection.player.games.empty')}
                        </p>
                    )}

                    {games.map((entry) => (
                        <Card key={entry.game.id}>
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-2">
                                    <AdminCardTitle>
                                        {t('admin.inspection.games.number', {
                                            id: entry.game.id,
                                        })}
                                    </AdminCardTitle>
                                    <Badge variant="outline">
                                        {t(
                                            INSPECTION_GAME_MODE_KEYS[
                                                entry.game.mode
                                            ],
                                        )}
                                    </Badge>
                                    <Badge variant="outline">
                                        {t(
                                            INSPECTION_GAME_STATUS_KEYS[
                                                entry.game.status
                                            ],
                                        )}
                                    </Badge>
                                    <Badge variant="secondary">
                                        {t(
                                            INSPECTION_SEAT_STATUS_KEYS[
                                                entry.status
                                            ],
                                        )}
                                    </Badge>
                                    <span className="text-sm text-muted-foreground">
                                        {t(
                                            'admin.inspection.player.games.score',
                                            {
                                                score:
                                                    entry.score === null
                                                        ? none
                                                        : formatInteger(
                                                              entry.score,
                                                              locale,
                                                          ),
                                                rank: entry.rank ?? none,
                                            },
                                        )}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="ml-auto min-h-11"
                                        asChild
                                    >
                                        <Link href={gamesShow(entry.game.id)}>
                                            {t(
                                                'admin.inspection.player.games.open_game',
                                            )}
                                        </Link>
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {entry.rounds.map((round) => (
                                    <div
                                        key={round.sequence_index}
                                        className="space-y-2 border-t pt-4 first:border-t-0 first:pt-0"
                                    >
                                        <InspectionRoundHeader round={round} />
                                        {round.participation !== null && (
                                            <InspectionParticipantAnswers
                                                participant={
                                                    round.participation
                                                }
                                                showPlayer={false}
                                                movieId={
                                                    round.movie?.id ?? null
                                                }
                                            />
                                        )}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </section>
            </div>
        </>
    );
}

AdminPlayersShow.layout = { breadcrumbs };
