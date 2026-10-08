import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useId } from 'react';
import { LetterboxdLink } from '@/components/game/letterboxd-link';
import Heading from '@/components/heading';
import { useTranslations } from '@/hooks/use-translations';
import type { HistoryRound, HistoryRow } from '@/lib/account/play-history';
import {
    OUTCOME_KEYS,
    formatHistoryDate,
    formatInteger,
    formatRank,
    originLine,
    roundsLine,
} from '@/lib/account/play-history';
import { recapTitles } from '@/lib/game/scoring-format';
import { index } from '@/routes/history';
import type { BreadcrumbItem } from '@/types';

type Props = {
    game: HistoryRow;
    scoreless: boolean;
    rounds: HistoryRound[];
    windowMonths: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.history.title',
        href: index(),
    },
];

/**
 * Réglages › Mes parties › une partie (spec 40 § 13.4, n° 30 ; D58 du 06/10,
 * troisième surface Letterboxd) : les films des manches closes, trouvés ou
 * non par le joueur, les points de la manche et le lien Letterboxd (nouvel
 * onglet). **Aucune image et aucun pseudo d'un autre joueur** (décision 19).
 */
export default function HistoryShow({ game, scoreless, rounds }: Props) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const date = formatHistoryDate(game.endedAt, locale);
    const origin = originLine(game);
    const roundsSummary = roundsLine(game, locale);
    const rank = formatRank(game, locale);

    return (
        <>
            <Head title={t('account.history.show.title', { date })} />

            <h1 className="sr-only">
                {t('account.history.show.title', { date })}
            </h1>

            <section
                className="settings-section space-y-4"
                aria-labelledby={headingId}
            >
                <p>
                    <Link href={index()} className="settings-history__link">
                        <ArrowLeft
                            aria-hidden="true"
                            className="mr-1 inline size-4"
                        />
                        {t('account.history.show.back')}
                    </Link>
                </p>

                <Heading
                    id={headingId}
                    variant="small"
                    title={t('account.history.show.title', { date })}
                    description={`${t(origin.key, origin.replacements)} · ${t(roundsSummary.key, roundsSummary.replacements)}`}
                />

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

                {scoreless && (
                    <p className="settings-history__note">
                        {t('account.history.show.scoreless')}
                    </p>
                )}
            </section>

            <section className="settings-section space-y-4">
                <h2 className="text-base font-medium">
                    {t('account.history.show.rounds_heading')}
                </h2>

                {rounds.length === 0 ? (
                    <p className="settings-history__note">
                        {t('account.history.show.empty')}
                    </p>
                ) : (
                    <ol className="settings-history__list">
                        {rounds.map((round) => (
                            <HistoryRoundEntry
                                key={round.number}
                                round={round}
                                scoreless={scoreless}
                            />
                        ))}
                    </ol>
                )}
            </section>
        </>
    );
}

/** Un film de la partie : titre dans la langue du joueur, issue, points, lien. */
function HistoryRoundEntry({
    round,
    scoreless,
}: {
    round: HistoryRound;
    scoreless: boolean;
}) {
    const { t, tChoice, locale } = useTranslations();
    const titles = recapTitles(round.movie, locale);

    return (
        <li className="settings-history__round space-y-2">
            <p className="settings-history__note">
                {t('account.history.show.round', {
                    number: formatInteger(round.number, locale),
                })}
            </p>
            <p className="font-bold">
                <span lang={titles.title.lang}>{titles.title.text}</span>
                {titles.year !== null && <> ({titles.year})</>}
            </p>
            {titles.original !== null && (
                <p className="settings-history__note">
                    {t('account.history.show.original_title')}{' '}
                    <span lang={titles.original.lang}>
                        {titles.original.text}
                    </span>
                </p>
            )}
            <p className="flex flex-wrap items-center gap-3">
                <span
                    className="settings-history__outcome"
                    data-outcome={round.outcome}
                >
                    {t(OUTCOME_KEYS[round.outcome])}
                </span>
                {!scoreless && round.points !== null && (
                    <span>
                        {tChoice('account.history.show.points', round.points, {
                            count: formatInteger(round.points, locale),
                        })}
                    </span>
                )}
            </p>
            <LetterboxdLink
                url={round.movie.letterboxdUrl}
                title={titles.title.text}
            />
        </li>
    );
}

HistoryShow.layout = { breadcrumbs };
