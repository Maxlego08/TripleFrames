import { Head, Link } from '@inertiajs/react';
import { useId } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type {
    HistoryPagination,
    HistoryRow,
    HistorySummary,
} from '@/lib/account/play-history';
import {
    formatHistoryDate,
    formatInteger,
    formatRank,
    formatSuccessRate,
    originLine,
    roundsLine,
} from '@/lib/account/play-history';
import { index, show } from '@/routes/history';
import type { BreadcrumbItem } from '@/types';

type Props = {
    summary: HistorySummary;
    games: HistoryRow[];
    pagination: HistoryPagination;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.history.title',
        href: index(),
    },
];

/**
 * Réglages › Mes parties (spec 40 § 13.4, L40-13, D66 du 07/10) : les quatre
 * compteurs des parties multijoueur sur la fenêtre glissante, puis la liste
 * paginée des parties, solo compris, chacune menant à son détail.
 *
 * Chaque libellé de compteur porte la fenêtre (`:months`). Le bouton
 * « Exporter mes données » (§ 13.5) est livré par le lot de l'export, qui
 * l'ajoute sous la date de la plus ancienne partie : il reste masqué tant
 * que la route n'existe pas.
 */
export default function History({ summary, games, pagination }: Props) {
    const { t, locale } = useTranslations();
    const countersId = useId();
    const listId = useId();
    const months = summary.windowMonths;
    const { counters } = summary;
    const empty = t('account.history.empty_value');
    const rate = formatSuccessRate(counters.successRate, locale);

    return (
        <>
            <Head title={t('account.history.title')} />

            <h1 className="sr-only">{t('account.history.title')}</h1>

            <section
                className="settings-section space-y-4"
                aria-labelledby={countersId}
            >
                <Heading
                    id={countersId}
                    variant="small"
                    title={t('account.history.counters.heading')}
                    description={t('account.history.counters.scope')}
                />

                <dl className="settings-history__counters">
                    <div className="settings-history__counter">
                        <dt>
                            {t('account.history.counters.games_played', {
                                months,
                            })}
                        </dt>
                        <dd className="settings-history__value">
                            {formatInteger(counters.gamesPlayed, locale)}
                        </dd>
                    </div>

                    <div className="settings-history__counter">
                        <dt>
                            {t('account.history.counters.correct_answers', {
                                months,
                            })}
                        </dt>
                        <dd className="settings-history__value">
                            {formatInteger(counters.correctAnswers, locale)}
                        </dd>
                    </div>

                    <div className="settings-history__counter">
                        <dt>
                            {t('account.history.counters.best_score', {
                                months,
                            })}
                        </dt>
                        <dd className="settings-history__value">
                            {counters.bestScore === null
                                ? empty
                                : formatInteger(
                                      counters.bestScore.score,
                                      locale,
                                  )}
                        </dd>
                        <dd className="settings-history__note">
                            {counters.bestScore === null ? (
                                t('account.history.counters.best_score_none')
                            ) : (
                                <>
                                    {t(
                                        'account.history.counters.best_score_detail',
                                        {
                                            date: formatHistoryDate(
                                                counters.bestScore.endedAt,
                                                locale,
                                            ),
                                            rounds: formatInteger(
                                                counters.bestScore.roundsCount,
                                                locale,
                                            ),
                                            frames: formatInteger(
                                                counters.bestScore
                                                    .framesPerRound,
                                                locale,
                                            ),
                                        },
                                    )}{' '}
                                    <Link
                                        href={show(counters.bestScore.publicId)}
                                        className="settings-history__link"
                                    >
                                        {t(
                                            'account.history.counters.best_score_link',
                                        )}
                                    </Link>
                                </>
                            )}
                        </dd>
                    </div>

                    <div className="settings-history__counter">
                        <dt>
                            {t('account.history.counters.success_rate', {
                                months,
                            })}
                        </dt>
                        <dd className="settings-history__value">
                            {rate ?? empty}
                        </dd>
                        <dd className="settings-history__note">
                            {rate === null
                                ? t(
                                      'account.history.counters.success_rate_below',
                                      {
                                          min: formatInteger(
                                              summary.successRateMinRounds,
                                              locale,
                                          ),
                                          played: formatInteger(
                                              counters.roundsPlayed,
                                              locale,
                                          ),
                                      },
                                  )
                                : t(
                                      'account.history.counters.success_rate_detail',
                                      {
                                          correct: formatInteger(
                                              counters.correctAnswers,
                                              locale,
                                          ),
                                          played: formatInteger(
                                              counters.roundsPlayed,
                                              locale,
                                          ),
                                      },
                                  )}
                        </dd>
                    </div>
                </dl>
            </section>

            <section
                className="settings-section space-y-4"
                aria-labelledby={listId}
            >
                <Heading
                    id={listId}
                    variant="small"
                    title={t('account.history.list.heading')}
                    description={t('account.history.description', { months })}
                />

                {summary.oldestKeptAt !== null && (
                    <p className="settings-history__note">
                        {t('account.history.oldest', {
                            date: formatHistoryDate(
                                summary.oldestKeptAt,
                                locale,
                            ),
                        })}
                    </p>
                )}

                {games.length === 0 ? (
                    <p className="settings-history__note">
                        {t('account.history.list.empty', { months })}
                    </p>
                ) : (
                    <ol className="settings-history__list">
                        {games.map((game) => (
                            <HistoryEntry key={game.publicId} game={game} />
                        ))}
                    </ol>
                )}

                {pagination.lastPage > 1 && (
                    <nav
                        className="settings-history__pagination"
                        aria-label={t('account.history.pagination.label')}
                    >
                        {pagination.page > 1 ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={index({
                                        query: { page: pagination.page - 1 },
                                    })}
                                    rel="prev"
                                >
                                    {t('account.history.pagination.previous')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        <p className="settings-history__note">
                            {t('account.history.pagination.status', {
                                page: formatInteger(pagination.page, locale),
                                last: formatInteger(
                                    pagination.lastPage,
                                    locale,
                                ),
                            })}
                        </p>
                        {pagination.page < pagination.lastPage ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={index({
                                        query: { page: pagination.page + 1 },
                                    })}
                                    rel="next"
                                >
                                    {t('account.history.pagination.next')}
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </section>
        </>
    );
}

/** Une partie de la liste, ancre `game-{publicId}`. */
function HistoryEntry({ game }: { game: HistoryRow }) {
    const { t, locale } = useTranslations();
    const date = formatHistoryDate(game.endedAt, locale);
    const origin = originLine(game);
    const rounds = roundsLine(game, locale);
    const rank = formatRank(game, locale);

    return (
        <li id={`game-${game.publicId}`} className="settings-history__row">
            <p className="font-bold">
                <time dateTime={game.endedAt}>{date}</time>
                {' · '}
                {t(origin.key, origin.replacements)}
            </p>
            <p className="settings-history__note">
                {t(rounds.key, rounds.replacements)}
                {' · '}
                {t('account.history.list.frames', {
                    count: formatInteger(game.framesPerRound, locale),
                })}
            </p>
            <dl className="settings-history__facts">
                <div>
                    <dt>{t('account.history.list.score')}</dt>
                    <dd>{formatInteger(game.finalScore, locale)}</dd>
                </div>
                <div>
                    <dt>{t('account.history.list.correct')}</dt>
                    <dd>{formatInteger(game.correctAnswers, locale)}</dd>
                </div>
                <div>
                    <dt>{t('account.history.list.rank')}</dt>
                    <dd>{rank ?? t('account.history.empty_value')}</dd>
                </div>
            </dl>
            <p className="mt-2">
                <Link
                    href={show(game.publicId)}
                    className="settings-history__link"
                    aria-label={t('account.history.list.details_label', {
                        date,
                    })}
                >
                    {t('account.history.list.details')}
                </Link>
            </p>
        </li>
    );
}

History.layout = { breadcrumbs };
