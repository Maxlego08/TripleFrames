import type { ReactNode } from 'react';
import { GameFrame } from '@/components/game/game-frame';
import type { FrameFormat } from '@/components/game/game-frame';
import { Progress } from '@/components/ui/progress';
import { useTranslations } from '@/hooks/use-translations';
import type { FrameView } from '@/lib/game/frame-loader';
import { cn } from '@/lib/utils';

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
    /** La zone de saisie, composée par l'appelant ; nulle : rien. */
    input: ReactNode;
    /** La bande des joueurs (`RoundPlayers`). */
    players: ReactNode;
    className?: string;
};

/**
 * Largeur du cadre dans la zone image (90 § 7.2) : la plus grande taille
 * 16:9 qui tienne dans le conteneur, en largeur comme en hauteur, sans aucun
 * `px` ni ratio écrit ailleurs que dans le jeton `--aspect-frame`.
 */
const FRAME_SIZE = 'w-[min(100cqw,calc(100cqh*var(--aspect-frame)))]';

/** Une seconde en millisecondes : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/**
 * La scène d'une manche (spec 60 § 8.4, § 9.3, § 13.7 ; 90 § 7.2, § 7.3 et
 * § 10, états « Manche » et « Joueur verrouillé ») — état de `game/lobby` en
 * partie, et de `game/solo`, jamais une page.
 *
 * **Portrait d'abord, aucun défilement** (principe 5), de haut en bas : la
 * ligne d'état — manche `k` sur `M`, valeur du palier (D29 du 23/09), chrono
 * en texte et barre `Progress` décorative —, la **zone image**, la saisie,
 * la bande des joueurs. La zone image prend la hauteur restante
 * (`min-h-0 flex-1`) ; c'est la seule requête de conteneur de l'écran, de
 * **taille** (`@container-size`) et non de largeur seule — sans quoi `cqh`
 * retomberait sur la hauteur du viewport — et le cadre y prend la plus
 * grande largeur 16:9 qui tienne. Desktop (`lg`) : la bande des joueurs
 * devient une colonne à droite, rien d'autre ne change (règle 10).
 *
 * - **Image et valeur basculent au même instant**, celui du palier courant
 *   de l'horloge resynchronisée : l'appelant passe l'image de ce palier et la
 *   valeur du sélecteur, tous deux lus sur la même horloge. Un cadre neuf par
 *   manche (`key`) : l'image d'une manche ne survit jamais dans la suivante ;
 *   entre deux paliers, l'image précédente reste jusqu'à ce que la suivante
 *   soit peignable (`GameFrame`).
 * - **Chrono** : les secondes restantes en texte, formatées par
 *   `Intl.NumberFormat` (unité seconde), doublées d'une phrase `sr-only`
 *   (`game.round.time_left`) ; la barre ne dit rien de plus et sort de
 *   l'arbre d'accessibilité. Jamais signalé par la seule couleur. Les
 *   annonces aux seuils relatifs passent par l'annonceur, pas par ici.
 * - **Clôture** : ni valeur ni secondes ; c'est l'événement serveur qui clôt,
 *   jamais ce chrono (règle 8 reformulée).
 *
 * Composant de présentation : ni Echo, ni horloge, ni requête (C16 § 2.9) ;
 * tokens seulement.
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
    input,
    players,
    className,
}: RoundSceneProps) {
    const { t, tChoice, locale } = useTranslations();
    const number = new Intl.NumberFormat(locale);
    const seconds =
        remainingMs === null
            ? null
            : new Intl.NumberFormat(locale, {
                  style: 'unit',
                  unit: 'second',
                  unitDisplay: 'short',
              }).format(Math.ceil(remainingMs / MS_PER_SECOND));
    const progress =
        stage === 'countdown'
            ? 100
            : remainingMs === null || durationMs <= 0
              ? 0
              : Math.min(100, (remainingMs / durationMs) * 100);

    return (
        <div
            className={cn(
                'flex min-h-0 flex-col gap-1.5 lg:flex-row lg:gap-4',
                className,
            )}
        >
            <div className="flex min-h-0 min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex shrink-0 flex-col gap-1">
                    <p className="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                        <span className="text-muted-foreground">
                            {t('game.round.number', {
                                number: number.format(roundNumber),
                                total: number.format(roundsCount),
                            })}
                        </span>

                        {tierValue !== null && (
                            <span className="font-medium">
                                {tChoice('game.round.tier_value', tierValue, {
                                    points: number.format(tierValue),
                                })}
                            </span>
                        )}

                        {seconds !== null && (
                            <span className="font-semibold tabular-nums">
                                <span aria-hidden="true">{seconds}</span>
                                <span className="sr-only">
                                    {t('game.round.time_left', {
                                        time: seconds,
                                    })}
                                </span>
                            </span>
                        )}
                    </p>

                    <Progress
                        aria-hidden="true"
                        value={progress}
                        className="h-1 motion-reduce:*:transition-none"
                    />
                </div>

                <div className="@container-size flex min-h-0 flex-1 items-center justify-center">
                    {frame !== null ? (
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
                            className={FRAME_SIZE}
                        />
                    ) : (
                        <div
                            className={cn(
                                'flex aspect-frame flex-col items-center justify-center gap-2 overflow-hidden rounded-md bg-muted p-4 text-center',
                                FRAME_SIZE,
                            )}
                        >
                            {notice.map((line) => (
                                <p key={line} className="text-balance">
                                    {line}
                                </p>
                            ))}
                        </div>
                    )}
                </div>

                {input !== null && input !== undefined && (
                    <div className="shrink-0">{input}</div>
                )}
            </div>

            {players}
        </div>
    );
}
