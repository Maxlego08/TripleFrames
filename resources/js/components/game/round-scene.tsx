import { Clock3 } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { GameFrame } from '@/components/game/game-frame';
import type { FrameFormat } from '@/components/game/game-frame';
import { useTranslations } from '@/hooks/use-translations';
import type { FrameView } from '@/lib/game/frame-loader';

/**
 * Où en est la manche montrée, vu de l'écran :
 * - `countdown` : programmée, `T₁` à venir (décompte de lancement, de
 *   reprise, ou d'un remplaçant) ;
 * - `running` : `T₁` franchi, manche ni close ni annulée ;
 * - `closed` : close par le serveur (`round.closed` à `D` ou à la fin
 *   anticipée, ou paquet en phase `closed`), révélation pas encore reçue ;
 * - `cancelled` : annulée (`round.cancelled`), sans motif ni titre.
 */
export type RoundSceneStage = 'countdown' | 'running' | 'closed' | 'cancelled';

/** L'image que la zone montre : celle d'un palier, pour un siège membre. */
export type RoundSceneFrame = {
    /** Clé de la manche (`roundKeyOf`) : un cadre neuf par manche. */
    key: string;
    /** Ce que `frame-loader` rend du palier (URL d'objet, attente). */
    view: FrameView;
    tierIndex: number;
    tierCount: number;
};

export type RoundSceneProps = {
    stage: RoundSceneStage;
    roundNumber: number;
    roundsCount: number;
    /** `D`, en millisecondes. */
    durationMs: number;
    /** Millisecondes jusqu'à `D`, manche en cours ; nul sinon. */
    remainingMs: number | null;
    /**
     * Valeur du palier affichée (`visibleTierValue()` du magasin, seul
     * décideur du masquage, 60 § 2.5) ; nulle : masquée.
     */
    tierValue: number | null;
    /** L'image du palier montré ; nulle : la zone porte `notice` à la place. */
    frame: RoundSceneFrame | null;
    /** Prop partagée `frameFormat` (C9). */
    frameFormat: FrameFormat;
    /**
     * Textes déjà traduits, à la place de l'image : décompte, annulation,
     * attente d'un siège sans image de cette manche (retardataire, partie
     * suivante).
     */
    notice: readonly string[];
};

/** Une seconde en millisecondes : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/**
 * Teintes de la barre du chrono (maquette `game.html`), par part de `D`
 * restante — des proportions d'affichage, jamais une durée de jeu : vert
 * au-dessus des deux tiers, orange au-dessus du tiers, rouge ensuite.
 */
const COOLDOWN_GREEN_FROM = 2 / 3;
const COOLDOWN_ORANGE_FROM = 1 / 3;

function cooldownTone(fraction: number): 'green' | 'orange' | 'red' {
    if (fraction >= COOLDOWN_GREEN_FROM) {
        return 'green';
    }

    return fraction >= COOLDOWN_ORANGE_FROM ? 'orange' : 'red';
}

/**
 * L'écran d'une manche (spec 60 § 8.4, § 9.3, § 13.7 ; 90 § 7.2, § 7.3 et
 * § 10, états « Manche » et « Joueur verrouillé ») — balisage de la maquette
 * `design-test/html/game.html` (`game-screen`), état de `game/lobby` en
 * partie et de `game/solo`, jamais une page.
 *
 * Dans le cadre 16:9 : la pastille d'état (manche `k`, image `i` sur `N`,
 * valeur du palier, D29 du 23/09) en haut à gauche, le chrono en haut à
 * droite, l'image du palier, et sous elle la barre du chrono, qui se vide
 * avec le temps et porte « Quel est ce film ? ». Le cadre prend la plus
 * grande taille 16:9 qui tienne dans l'écran (`game-screen__stage`, seule
 * requête de conteneur de taille, ratio lu dans `--aspect-frame`).
 *
 * - **Image et valeur basculent au même instant**, celui du palier courant
 *   de l'horloge resynchronisée. Un cadre neuf par manche (`key`) ; entre
 *   deux paliers, l'image précédente reste jusqu'à ce que la suivante soit
 *   peignable (`GameFrame`).
 * - **Chrono** : les secondes restantes en texte, doublées d'une phrase
 *   `sr-only` (`game.round.time_left`) ; la barre et sa teinte ne disent rien
 *   de plus et sortent de l'arbre d'accessibilité — jamais signalé par la
 *   seule couleur. Les annonces aux seuils passent par l'annonceur.
 * - **Clôture** : ni valeur ni secondes, la barre vide dit « la réponse
 *   arrive » ; c'est l'événement serveur qui clôt, jamais ce chrono (règle 8
 *   reformulée).
 *
 * Composant de présentation : ni Echo, ni horloge, ni requête (C16 § 2.9) ;
 * aucune couleur ni taille en dur, la présentation vit dans `game.scss`.
 */
export function RoundScene({
    stage,
    roundNumber,
    roundsCount,
    durationMs,
    remainingMs,
    tierValue,
    frame,
    frameFormat,
    notice,
}: RoundSceneProps) {
    const { t, tChoice, locale } = useTranslations();
    const number = new Intl.NumberFormat(locale);
    const remainingSeconds =
        remainingMs === null ? null : Math.ceil(remainingMs / MS_PER_SECOND);
    const fraction =
        stage === 'countdown'
            ? 1
            : remainingMs === null || durationMs <= 0
              ? 0
              : Math.min(1, remainingMs / durationMs);
    const cooldownStyle = {
        '--cooldown-progress': `${fraction * 100}%`,
    } as CSSProperties;
    const cooldownText =
        stage === 'closed'
            ? t('game.round.time_up')
            : stage === 'cancelled'
              ? t('game.round.cancelled')
              : t('game.round.question');

    let picture: ReactNode;

    if (frame !== null) {
        picture = (
            <GameFrame
                key={frame.key}
                src={frame.view.src}
                pending={frame.view.pending}
                alt={t('game.frame.alt', {
                    index: number.format(frame.tierIndex),
                    total: number.format(frame.tierCount),
                })}
                loadingLabel={t('game.frame.loading')}
                unavailableLabel={t('game.frame.unavailable')}
                format={frameFormat}
            />
        );
    } else {
        picture = (
            <div className="game-screen__notice">
                {notice.map((line) => (
                    <p key={line}>{line}</p>
                ))}
            </div>
        );
    }

    return (
        <section className="game-screen" aria-label={t('game.round.screen')}>
            <div className="game-screen__stage">
                <div className="game-screen__frame">
                    <p className="game-screen__status">
                        <span>
                            <span aria-hidden="true">
                                {t('game.round.status_number', {
                                    number: number.format(roundNumber),
                                })}
                            </span>
                            <span className="sr-only">
                                {t('game.round.number', {
                                    number: number.format(roundNumber),
                                    total: number.format(roundsCount),
                                })}
                            </span>
                        </span>

                        {frame !== null && (
                            <span>
                                {t('game.frame.status', {
                                    index: number.format(frame.tierIndex),
                                    total: number.format(frame.tierCount),
                                })}
                            </span>
                        )}

                        {tierValue !== null && (
                            <span>
                                {tChoice('game.round.tier_value', tierValue, {
                                    points: number.format(tierValue),
                                })}
                            </span>
                        )}
                    </p>

                    <div className="game-screen__content">
                        <div className="game-screen__picture">{picture}</div>

                        <p
                            className={`game-screen__cooldown game-screen__cooldown--${cooldownTone(fraction)}`}
                            style={cooldownStyle}
                        >
                            <span>{cooldownText}</span>
                        </p>
                    </div>

                    {remainingSeconds !== null && (
                        <p className="game-screen__timer">
                            <Clock3 aria-hidden="true" />
                            <strong aria-hidden="true">
                                {number.format(remainingSeconds)}
                            </strong>
                            <span className="sr-only">
                                {t('game.round.time_left', {
                                    time: new Intl.NumberFormat(locale, {
                                        style: 'unit',
                                        unit: 'second',
                                        unitDisplay: 'long',
                                    }).format(remainingSeconds),
                                })}
                            </span>
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}
