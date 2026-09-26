import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * Format fixe de la frame servable, prop partagée `frameFormat`
 * (`FrameGeometry::GAME_WIDTH` / `GAME_HEIGHT`, contrat C9) : sert aux seuls
 * attributs `width` / `height` de l'image, jamais au ratio du cadre.
 */
export type FrameFormat = { width: number; height: number };

export type GameFrameProps = {
    /**
     * URL fournie par le serveur, jamais reconstruite par Wayfinder (R-37) :
     * URL d'objet produite par `frame-loader` à partir de `TierImageRef.url`
     * en jeu (C7, C8), ou `game_url` de l'aperçu admin (C9). `null` = rien de
     * servable : le cadre affiche l'indisponibilité — sauf si `pending`.
     */
    src: string | null;
    /**
     * Image attendue, pas encore là (E39-1, ajout de 60 à C16 § 2.5) : avec
     * `src` nul, le cadre montre le chargement et non l'indisponibilité —
     * client lent au palier 1, avant que `frame-loader` ait produit une URL
     * d'objet. Sans effet quand `src` est une URL. Défaut faux : l'aperçu
     * admin, qui ne la passe jamais, est inchangé.
     */
    pending?: boolean;
    /** Déjà traduit par l'appelant : neutre, jamais descriptif (principe 8). */
    alt: string;
    /** Déjà traduit : lu par un lecteur d'écran pendant le chargement. */
    loadingLabel: string;
    /** Déjà traduit : affiché en clair quand l'image manque. */
    unavailableLabel: string;
    /** Prop partagée `frameFormat`. */
    format: FrameFormat;
    className?: string;
};

/** Une image affichée, avec le texte alternatif qu'elle avait à son `load`. */
type ShownFrame = { src: string; alt: string };

/** Une couche `<img>` du cadre, clé = sa source. */
type Layer = { src: string; alt: string; hidden: boolean };

function preventDefault(event: SyntheticEvent): void {
    event.preventDefault();
}

/**
 * Conteneur d'image des écrans de jeu (spec 90 § 7.1, contrat C16 § 2.5 ;
 * D5, D7 et D8 du 23/09).
 *
 * **Aucun appel à `t()`, aucune dépendance à Inertia** : tous ses textes
 * arrivent traduits, son format arrive en prop. C'est ce qui le rend
 * réutilisable tel quel dans l'aperçu du back-office, sous `GameThemeScope`
 * (20, C9-bis) : la revue voit l'image exactement comme un joueur, cadre et
 * tokens sombres compris.
 *
 * - **Cadre neutre à dimensions fixes** : utilitaire `aspect-frame` (jeton
 *   `--aspect-frame` de C9, source unique du ratio), largeur pleine, aucun
 *   style en ligne. Le cadre occupe sa place avant toute image : rien ne
 *   saute à l'arrivée des octets. En manche, la zone image est un conteneur
 *   (`@container`) et l'appelant passe par `className` la plus grande largeur
 *   16:9 qui y tienne, `w-[min(100cqw,calc(100cqh*var(--aspect-frame)))]`
 *   (§ 7.2) ; l'aperçu admin garde la largeur pleine de sa colonne.
 * - **Aucun LQIP, aucun flou** (D7, E10-60). Sans image chargée : un aplat au
 *   token, un `Spinner` neutralisé — sans quoi son `role="status"` généré
 *   ferait une seconde région vivante — et `aria-busy`, avec `loadingLabel`
 *   pour les lecteurs d'écran — aussi quand `src` est nul mais `pending`
 *   vrai (image attendue, E39-1). Rien de servable (`src` nul sans
 *   `pending`) ou échec du chargement : l'aplat reste et `unavailableLabel`
 *   s'affiche en clair. Le serveur ne décale jamais le chrono pour un client
 *   lent.
 * - **Au changement de `src`, l'image précédente reste jusqu'au `load` de la
 *   suivante** : la nouvelle se charge dans une couche invisible, qui devient
 *   la couche affichée une fois chargée et décodée — même nœud, même clé,
 *   aucun second téléchargement — et l'ancienne est retirée. Une couche
 *   retirée avant la fin de son décodage (`src` changé entre-temps) ne se
 *   montre jamais. Aucun flash d'aplat entre deux paliers. Un
 *   cadre neuf est monté par manche (`key` fourni par 60) : l'image d'une
 *   manche ne survit jamais dans la suivante.
 * - **Anti-recherche inversée** (principe 3) : image non déplaçable, menu
 *   contextuel et glisser empêchés, sélection et appui long désactivés. Ce
 *   n'est pas incassable, c'est une friction ; en jeu, `src` est une URL
 *   d'objet locale, jamais l'URL signée elle-même.
 */
export function GameFrame({
    src,
    pending = false,
    alt,
    loadingLabel,
    unavailableLabel,
    format,
    className,
}: GameFrameProps) {
    // Dernière image chargée : elle reste affichée jusqu'au `load` de la
    // suivante.
    const [shown, setShown] = useState<ShownFrame | null>(null);
    // Source dont le chargement a échoué.
    const [failedSrc, setFailedSrc] = useState<string | null>(null);

    // Image attendue : aucune URL encore, mais rien d'indisponible (E39-1).
    const awaited = src === null && pending;
    const unavailable = src === null ? !awaited : src === failedSrc;
    const isCurrent = shown !== null && shown.src === src;
    const loadingSrc = src !== null && !unavailable && !isCurrent;
    const visible = unavailable ? null : shown;
    const loading = (loadingSrc || awaited) && visible === null;

    const layers: Layer[] = [];

    if (visible !== null) {
        layers.push({
            src: visible.src,
            alt: isCurrent ? alt : visible.alt,
            hidden: false,
        });
    }

    if (loadingSrc) {
        layers.push({ src, alt: '', hidden: true });
    }

    return (
        <div
            className={cn(
                'relative aspect-frame w-full overflow-hidden bg-muted select-none [-webkit-touch-callout:none]',
                className,
            )}
            aria-busy={loading ? true : undefined}
            onContextMenu={preventDefault}
            onDragStart={preventDefault}
        >
            {layers.map((layer) => (
                <img
                    key={layer.src}
                    src={layer.src}
                    alt={layer.alt}
                    width={format.width}
                    height={format.height}
                    decoding="async"
                    draggable={false}
                    className={cn(
                        'absolute inset-0 size-full object-contain',
                        layer.hidden && 'invisible',
                    )}
                    onLoad={(event) => {
                        const image = event.currentTarget;
                        const show = (): void => {
                            // Couche retirée pendant `decode()` (`src` a
                            // changé) : sa source n'est plus demandée, puisque
                            // les couches ne portent que l'image affichée et
                            // la source attendue. La montrer ferait revenir
                            // une image périmée (A → B → A) ou un aplat.
                            if (image.isConnected) {
                                setShown({
                                    src: layer.src,
                                    alt: layer.alt || alt,
                                });
                            }
                        };

                        // Décodée avant d'être montrée : la couche précédente
                        // n'est retirée qu'une fois la suivante peignable. Un
                        // décodage refusé n'empêche pas l'affichage.
                        void image.decode().then(show, show);
                    }}
                    onError={() => setFailedSrc(layer.src)}
                />
            ))}

            {loading && (
                <div className="absolute inset-0 flex items-center justify-center">
                    <Spinner
                        aria-hidden="true"
                        role="presentation"
                        aria-label={undefined}
                        className="size-6 text-muted-foreground motion-reduce:animate-none"
                    />
                    <span className="sr-only">{loadingLabel}</span>
                </div>
            )}

            {unavailable && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-sm text-muted-foreground">
                    <ImageOff aria-hidden="true" className="size-6" />
                    <p>{unavailableLabel}</p>
                </div>
            )}
        </div>
    );
}
