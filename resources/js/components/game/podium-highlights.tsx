import { CircleSlash, SearchX, Timer, Trophy } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { usePlayerLabel } from '@/components/game/player-ordinals';
import { useTranslations } from '@/hooks/use-translations';
import {
    formatDuration,
    recapTitles,
    titleSegments,
} from '@/lib/game/scoring-format';
import type { TitleSegment } from '@/lib/game/scoring-format';
import type { RevealTitle } from '@/types/game-wire';
import type {
    PodiumHighlights as PodiumHighlightsData,
    PodiumStanding,
    RecapEntry,
} from '@/types/scoring';

type PodiumHighlightsProps = {
    /** `Podium.highlights` (§ 11.5, D25 du 23/09). */
    highlights: PodiumHighlightsData;
    /** `Podium.standings` : le pseudo d'un fait marquant, par `publicId`. */
    standings: readonly PodiumStanding[];
    /** `Podium.recap` : le titre d'un fait marquant, par `roundNumber`. */
    recap: readonly RecapEntry[];
};

/**
 * Une ligne traduite qui porte un titre : le texte dans la langue du joueur,
 * le titre dans un élément portant sa propre langue (§ 15.1).
 */
function TitledLine({ segments }: { segments: readonly TitleSegment[] }) {
    return (
        <>
            {segments.map((segment, index) =>
                segment.kind === 'title' ? (
                    <span key={index} lang={segment.lang}>
                        {segment.text}
                    </span>
                ) : (
                    segment.text
                ),
            )}
        </>
    );
}

type HighlightProps = {
    icon: LucideIcon;
    children: ReactNode;
};

/** Un fait marquant : une icône décorative et sa phrase. */
function Highlight({ icon: Icon, children }: HighlightProps) {
    return (
        <li className="flex items-start gap-2">
            <Icon
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <span className="min-w-0 break-words">{children}</span>
        </li>
    );
}

/**
 * Les faits marquants du podium (spec 80 § 11.5, D25 du 23/09, lot L80-7) :
 *
 * - la **meilleure réponse de la partie** (`game.podium.highlights.best_answer`
 *   : pseudo, titre, points) — en mode sans score, mécaniquement la plus
 *   rapide ;
 * - le **film le plus rapidement trouvé** (`…fastest_find` : titre, pseudo,
 *   durée depuis le début de la manche) ;
 * - sans aucune bonne réponse, `…none` à la place des deux ;
 * - les **films que personne n'a eus** (`…unfound`, leur nombre ; le
 *   récapitulatif dit lesquels).
 *
 * Les deux premiers peuvent désigner la même réponse : les deux restent
 * montrés. Le titre d'un fait se lit dans l'entrée du récapitulatif de même
 * `roundNumber`, toujours close (§ 11.5), et s'insère dans la phrase en
 * fragment portant sa langue, **jamais interpolé en texte brut**
 * (`titleSegments()`). Le pseudo se lit dans le classement final. Un fait
 * dont l'entrée ou le siège manquerait — ce que le serveur exclut — n'est
 * pas rendu, plutôt que rendu faux.
 *
 * Composant de présentation (C16 § 2.9) : ni Echo, ni horloge, des props
 * seulement ; tokens seulement.
 */
export function PodiumHighlights({
    highlights,
    standings,
    recap,
}: PodiumHighlightsProps) {
    const { t, tChoice, locale } = useTranslations();
    const label = usePlayerLabel();
    const number = new Intl.NumberFormat(locale);

    const nicknameOf = (publicId: string): string | null => {
        const standing = standings.find(
            (candidate) => candidate.publicId === publicId,
        );

        return standing === undefined ? null : label(standing);
    };

    const titleOf = (roundNumber: number): RevealTitle | null => {
        const entry = recap.find(
            (candidate) => candidate.roundNumber === roundNumber,
        );

        return entry?.outcome === 'completed'
            ? recapTitles(entry.titles, locale).title
            : null;
    };

    const best = highlights.bestAnswer;
    const bestNickname = best === null ? null : nicknameOf(best.publicId);
    const bestTitle = best === null ? null : titleOf(best.roundNumber);

    const fastest = highlights.fastestFind;
    const fastestNickname =
        fastest === null ? null : nicknameOf(fastest.publicId);
    const fastestTitle = fastest === null ? null : titleOf(fastest.roundNumber);

    const unfound = highlights.unfoundRoundNumbers.length;

    return (
        <ul className="flex flex-col gap-2">
            {best !== null && bestNickname !== null && bestTitle !== null && (
                <Highlight icon={Trophy}>
                    <TitledLine
                        segments={titleSegments(
                            t('game.podium.highlights.best_answer', {
                                nickname: bestNickname,
                                points: number.format(best.pointsTotal),
                            }),
                            bestTitle,
                        )}
                    />
                </Highlight>
            )}

            {fastest !== null &&
                fastestNickname !== null &&
                fastestTitle !== null && (
                    <Highlight icon={Timer}>
                        <TitledLine
                            segments={titleSegments(
                                t('game.podium.highlights.fastest_find', {
                                    nickname: fastestNickname,
                                    duration: formatDuration(
                                        fastest.answeredAtMs,
                                        locale,
                                    ),
                                }),
                                fastestTitle,
                            )}
                        />
                    </Highlight>
                )}

            {best === null && fastest === null && (
                <Highlight icon={CircleSlash}>
                    {t('game.podium.highlights.none')}
                </Highlight>
            )}

            {unfound > 0 && (
                <Highlight icon={SearchX}>
                    {tChoice('game.podium.highlights.unfound', unfound, {
                        count: number.format(unfound),
                    })}
                </Highlight>
            )}
        </ul>
    );
}
