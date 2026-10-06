import { useId } from 'react';
import { LetterboxdLink } from '@/components/game/letterboxd-link';
import { useTranslations } from '@/hooks/use-translations';
import { recapTitles } from '@/lib/game/scoring-format';
import type { RecapEntry } from '@/types/scoring';

type RoundRecapProps = {
    /**
     * `Podium.recap` (§ 11.4) : une entrée par numéro de manche close ou
     * annulée sans remplaçant, dans l'ordre reçu (numéro croissant).
     */
    recap: readonly RecapEntry[];
};

type RecapFilmProps = {
    entry: Extract<RecapEntry, { outcome: 'completed' }>;
};

/**
 * Le film d'une manche close, rendu exactement comme l'écran de révélation
 * rend `RevealMovie` : par l'assistant client unique `revealTitles()` (60
 * § 9.5, par `recapTitles()`), le titre retenu dans la langue du joueur, le
 * titre original s'il diffère, l'année, le lien Letterboxd (D58 du 06/10) — chaque titre dans un élément
 * portant **sa** langue (05 § Attribut `lang`), jamais celle du joueur. Au
 * changement de langue, le titre bascule aussitôt : le paquet porte déjà
 * celui de chaque locale activée.
 */
function RecapFilm({ entry }: RecapFilmProps) {
    const { tChoice, t, locale } = useTranslations();
    const number = new Intl.NumberFormat(locale);
    const { title, original, year } = recapTitles(entry.titles, locale);

    return (
        <>
            <p lang={title.lang} className="font-medium break-words">
                {title.text}
            </p>

            {(original !== null || year !== null) && (
                <p className="flex flex-wrap gap-x-3 text-sm text-muted-foreground">
                    {original !== null && (
                        <span lang={original.lang} className="break-words">
                            {original.text}
                        </span>
                    )}
                    {year !== null && <span>{year}</span>}
                </p>
            )}

            <p className="text-sm text-muted-foreground">
                {entry.foundCount === 0
                    ? t('game.recap.nobody')
                    : tChoice('game.recap.found_by', entry.foundCount, {
                          count: number.format(entry.foundCount),
                      })}
            </p>

            <LetterboxdLink
                url={entry.titles.letterboxdUrl}
                title={title.text}
            />
        </>
    );
}

/**
 * Le récapitulatif des films de la partie (spec 80 § 11.4, lot L80-7) :
 * **texte seul**, aucune vignette ni URL d'image (Q80-1), le seul lien sortant
 * étant la fiche Letterboxd de chaque film (D58 du 06/10) — un film dépublié,
 * suspendu ou retiré depuis sa manche y garde son titre, sans aucun octet
 * d'image servi.
 *
 * Une entrée par numéro, en liste ordonnée : « Manche n », puis le film et
 * le nombre de joueurs qui l'ont trouvé (`game.recap.found_by`, ou
 * `game.recap.nobody`) ; une manche annulée et jamais remplacée dit
 * `game.recap.cancelled`, sans titre — elle n'a jamais été révélée. Le
 * serveur n'y met jamais une manche `pending` (§ 11.4).
 *
 * Composant de présentation (C16 § 2.9) : ni Echo, ni horloge, des props
 * seulement ; tokens seulement.
 */
export function RoundRecap({ recap }: RoundRecapProps) {
    const { t, locale } = useTranslations();
    const headingId = useId();
    const number = new Intl.NumberFormat(locale);

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3">
            <h3 id={headingId} className="text-lg font-semibold">
                {t('game.recap.title')}
            </h3>

            <ol className="flex flex-col gap-2">
                {recap.map((entry) => (
                    <li
                        key={entry.roundNumber}
                        className="flex flex-col gap-1 rounded-md border border-border px-3 py-2"
                    >
                        <p className="text-xs text-muted-foreground">
                            {t('game.recap.round', {
                                number: number.format(entry.roundNumber),
                            })}
                        </p>

                        {entry.outcome === 'completed' ? (
                            <RecapFilm entry={entry} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('game.recap.cancelled')}
                            </p>
                        )}
                    </li>
                ))}
            </ol>
        </section>
    );
}
