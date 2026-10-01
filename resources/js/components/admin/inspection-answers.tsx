import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import {
    INSPECTION_INCIDENT_KEYS,
    INSPECTION_INPUT_STATE_KEYS,
    INSPECTION_MATCH_KIND_KEYS,
    INSPECTION_ROUND_STATUS_KEYS,
    INSPECTION_SOURCE_KEYS,
    localeLabel,
} from '@/lib/admin-enum-keys';
import { formatMoment } from '@/lib/admin-format';
import { show as playersShow } from '@/routes/admin/players';
import type {
    InspectionParticipant,
    InspectionPlayerRound,
    InspectionRound,
} from '@/types/admin';

/** Des millisecondes de manche en secondes, au dixième, dans la locale. */
function seconds(ms: number, locale: string): string {
    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(ms / 1000);
}

/**
 * L'en-tête d'une manche inspectée — numéro, statut, film et paliers ; une
 * manche non divulgable (règle 3) n'a que son numéro et son statut, et le dit.
 */
export function InspectionRoundHeader({
    round,
}: {
    round: InspectionRound | InspectionPlayerRound;
}) {
    const { t, locale } = useTranslations();

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-foreground">
                    {round.round_number !== null
                        ? t('admin.inspection.game.rounds.round', {
                              number: round.round_number,
                          })
                        : t('admin.inspection.game.rounds.reserve', {
                              index: round.sequence_index,
                          })}
                </h3>
                <Badge
                    variant={
                        round.status === 'cancelled' ? 'destructive' : 'outline'
                    }
                >
                    {t(INSPECTION_ROUND_STATUS_KEYS[round.status])}
                </Badge>
                {round.found_count !== null && (
                    <Badge variant="secondary">
                        {t('admin.inspection.game.rounds.found', {
                            count: round.found_count,
                        })}
                    </Badge>
                )}
                <span className="text-xs text-muted-foreground">
                    {formatMoment(round.started_at, locale)}
                </span>
            </div>

            {round.cancel_reason !== null && (
                <p className="text-sm text-muted-foreground">
                    {t('admin.inspection.game.rounds.cancelled', {
                        reason: t(
                            INSPECTION_INCIDENT_KEYS[round.cancel_reason],
                        ),
                    })}
                </p>
            )}

            {!round.disclosed && (
                <p className="text-sm text-muted-foreground">
                    {t('admin.inspection.hidden_round')}
                </p>
            )}

            {round.movie !== null && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium text-foreground">
                        {round.movie.title_original}
                    </span>
                    {Object.entries(round.movie.titles).map(([code, title]) => (
                        <Badge key={code} variant="outline">
                            {localeLabel(code, t)}
                            {' · '}
                            {title}
                        </Badge>
                    ))}
                </div>
            )}

            {round.tiers.length > 0 && (
                <ul className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                    {round.tiers.map((tier) => (
                        <li
                            key={tier.tier_index}
                            className="rounded-md border px-2 py-1"
                        >
                            <span className="font-medium text-foreground">
                                {t('admin.inspection.game.rounds.tier', {
                                    index: tier.tier_index,
                                })}
                            </span>{' '}
                            {t('admin.inspection.game.rounds.tier_detail', {
                                level: tier.frame_level,
                                points: tier.points,
                                duration: tier.duration_ms / 1000,
                            })}
                            {tier.substitution_reason !== null && (
                                <>
                                    {' · '}
                                    {t(
                                        'admin.inspection.game.rounds.substituted',
                                        {
                                            reason: t(
                                                INSPECTION_INCIDENT_KEYS[
                                                    tier.substitution_reason
                                                ],
                                            ),
                                        },
                                    )}
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Les réponses d'un participant à une manche : état de saisie, bonne réponse
 * détaillée et chacune de ses réponses fausses, dans l'ordre de réception.
 */
export function InspectionParticipantAnswers({
    participant,
    showPlayer = true,
}: {
    participant: InspectionParticipant;
    showPlayer?: boolean;
}) {
    const { t, locale } = useTranslations();
    const guess = participant.guess;

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                {showPlayer &&
                    (participant.player_id !== null ? (
                        <Link
                            href={playersShow(participant.player_id)}
                            className="font-medium text-foreground underline-offset-4 hover:underline"
                        >
                            {participant.nickname ??
                                t('admin.inspection.erased')}
                        </Link>
                    ) : (
                        <span className="font-medium text-foreground">
                            {participant.nickname ??
                                t('admin.inspection.erased')}
                        </span>
                    ))}
                <Badge
                    variant={
                        participant.input_state === 'locked'
                            ? 'default'
                            : 'outline'
                    }
                >
                    {t(INSPECTION_INPUT_STATE_KEYS[participant.input_state])}
                </Badge>
                <span className="text-xs text-muted-foreground">
                    {t('admin.inspection.answers.wrong_attempts', {
                        count: participant.wrong_attempts,
                    })}
                </span>
            </div>

            {guess !== null && (
                <div className="space-y-0.5 text-sm">
                    <p className="font-medium text-foreground">
                        {t('admin.inspection.answers.correct')}
                        {' · '}
                        {t(INSPECTION_SOURCE_KEYS[guess.source])}
                    </p>
                    <p className="text-muted-foreground">
                        {t('admin.inspection.answers.correct_detail', {
                            tier: guess.tier_index,
                            rank: guess.lock_rank,
                            total: guess.points_total,
                            tier_points: guess.points_tier,
                            bonus: guess.points_bonus,
                            seconds: seconds(guess.answered_at_ms, locale),
                        })}
                    </p>
                    <p className="break-words text-muted-foreground">
                        {t('admin.inspection.answers.match', {
                            kind: t(
                                INSPECTION_MATCH_KIND_KEYS[guess.match_kind],
                            ),
                            key: guess.answer_key_normalized,
                            submitted: guess.submitted_normalized,
                            distance: guess.edit_distance,
                        })}
                    </p>
                    {guess.prefix_was_ambiguous && (
                        <Badge variant="secondary">
                            {t('admin.inspection.answers.ambiguous')}
                        </Badge>
                    )}
                </div>
            )}

            <div className="space-y-1 text-sm">
                <p className="font-medium text-foreground">
                    {t('admin.inspection.answers.wrong')}
                </p>
                {participant.wrong_answers.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('admin.inspection.answers.no_wrong')}
                    </p>
                ) : (
                    <ol className="space-y-1">
                        {participant.wrong_answers.map((answer, index) => (
                            <li key={index} className="break-words">
                                <span className="font-mono text-foreground">
                                    {answer.submitted_text}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {t('admin.inspection.answers.wrong_line', {
                                        source: t(
                                            INSPECTION_SOURCE_KEYS[
                                                answer.source
                                            ],
                                        ),
                                        tier: answer.tier_index ?? '—',
                                        seconds: seconds(
                                            answer.answered_at_ms,
                                            locale,
                                        ),
                                    })}
                                    {answer.attempt_number !== null && (
                                        <>
                                            {' · '}
                                            {t(
                                                'admin.inspection.answers.attempt',
                                                {
                                                    number: answer.attempt_number,
                                                },
                                            )}
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </div>
    );
}
