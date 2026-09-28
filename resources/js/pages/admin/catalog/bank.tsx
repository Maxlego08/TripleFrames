import {
    Deferred,
    Form,
    Head,
    Link,
    router,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ArrowRightIcon,
    ImagePlusIcon,
    InfoIcon,
    TriangleAlertIcon,
    XIcon,
} from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { flushSync } from 'react-dom';
import { toast } from 'sonner';
import FrameCaptureController from '@/actions/App/Http/Controllers/Admin/FrameCaptureController';
import FrameTmdbController from '@/actions/App/Http/Controllers/Admin/FrameTmdbController';
import {
    AvailabilityBadge,
    ContentFlagBadge,
} from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { BackdropGrid } from '@/components/admin/backdrop-grid';
import type { BackdropGridHandle } from '@/components/admin/backdrop-grid';
import { BackdropStrip } from '@/components/admin/backdrop-strip';
import { CaptureSourcePicker } from '@/components/admin/capture-source-picker';
import type { CapturePickerStatus } from '@/components/admin/capture-source-picker';
import { CoverageMeter } from '@/components/admin/coverage-meter';
import { FrameBankList } from '@/components/admin/frame-bank-list';
import type {
    FrameGestureKind,
    FrameGestureTarget,
} from '@/components/admin/frame-bank-list';
import { FrameCropper } from '@/components/admin/frame-cropper';
import { FrameGestureDialog } from '@/components/admin/frame-gesture-dialog';
import type {
    CoverageWarning,
    FrameGesture,
} from '@/components/admin/frame-gesture-dialog';
import { GameConditionsPreview } from '@/components/admin/game-conditions-preview';
import { FRAME_LEVEL_KEYS, LevelPicker } from '@/components/admin/level-picker';
import {
    PublishButton,
    PublishDialog,
    usePublicationPreview,
} from '@/components/admin/publish-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useCurationHeartbeat } from '@/hooks/admin/use-curation-heartbeat';
import { useThroughputShortcuts } from '@/hooks/admin/use-throughput-shortcuts';
import { useTranslations } from '@/hooks/use-translations';
import { stripAfterSend, stripNeighbour } from '@/lib/admin/backdrop-strip';
import type { StripDirection } from '@/lib/admin/backdrop-strip';
import { BANK_WRITE_PROPS } from '@/lib/admin/bank-visits';
import {
    CAPTURE_FILE_NAME,
    CAPTURE_MIME_TYPE,
    normalizeCapture,
    parseTimecode,
    pickClipboardImage,
} from '@/lib/admin/capture-encoder';
import type { CaptureRefusal } from '@/lib/admin/capture-encoder';
import { curationFiltersFromUrl } from '@/lib/admin/curation-query';
import {
    applyCropCommand,
    cropSecondsAt,
    cropStateViolation,
    openCrop,
} from '@/lib/admin/crop-state';
import type { CropState } from '@/lib/admin/crop-state';
import { formatInteger } from '@/lib/admin-format';
import { FRAME_GEOMETRY, masterHeightFor } from '@/lib/frame-geometry';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    index as catalogIndex,
    show as catalogShow,
} from '@/routes/admin/catalog';
import { next as curationNext } from '@/routes/admin/curation';
import type {
    AdminBackdrop,
    AdminBackdropSet,
    AdminBankAbilities,
    AdminBankMovie,
    AdminFrameLimits,
    AdminMovieFrame,
    AdminPublicationPreview,
    AdminSequencePreview,
    AdminUnpublishPreview,
    ContentFlag,
    FrameLevel,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    movie: AdminBankMovie;
    frames: AdminMovieFrame[];
    limits: AdminFrameLimits;
    captureEnabled: boolean;
    pollSeconds: number;
    /** Cadence du battement de débit (`catalog.curation.heartbeat_seconds`). */
    heartbeatSeconds: number;
    sequencePreview: AdminSequencePreview[];
    abilities: AdminBankAbilities;
    /** Prop facultative : servie au seul rechargement qui la demande. */
    unpublish_preview?: AdminUnpublishPreview | null;
    /** Prop facultative : l'aperçu d'ambiguïté, au rechargement qui ouvre la publication. */
    publication_preview?: AdminPublicationPreview;
    /** Prop différée : absente tant que TMDB n'a pas répondu. */
    backdrops?: AdminBackdropSet;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.catalog', href: catalogIndex() },
    { title: 'admin.bank.title', href: catalogIndex() },
];

const MS_PER_SECOND = 1000;

/** Les props que le rechargement partiel rafraîchit pendant un traitement. */
const POLLED_PROPS = ['frames', 'movie', 'sequencePreview'];

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-bank-offline';

/** Paramètre du rechargement qui demande l'avertissement de couverture. */
const PREVIEW_FRAME_PARAMETER = 'preview_frame';

/**
 * Raccourcis de débit que le cadre déclare (`aria-keyshortcuts`, § 6.4) : les
 * cinq niveaux, puis les deux paires de touches pour parcourir la bande.
 */
const CROPPER_KEY_SHORTCUTS = '1 2 3 4 5 PageUp PageDown [ ]';

/**
 * Ce qui est ouvert dans le cadre, et le cadre lui-même. `focusFrame` : le
 * cadre prend le focus en s'ouvrant — visuel voisin ou suivant ouvert depuis
 * le cadre ou après un envoi, « sans quitter le cadre » (§ 6.2, § 6.3), ou
 * capture tout juste préparée.
 *
 * - `tmdb` : un visuel TMDB de la grille ou de la bande (§ 5.3) ;
 * - `capture` : une capture choisie ou collée (§ 5.4, D38 du 28/09), déjà
 *   préparée par le navigateur — `blob` est le WebP qui partira, `objectUrl`
 *   son URL d'objet, affichée dans le cadre et rendue au navigateur dès que
 *   la capture est remplacée, fermée, envoyée ou l'écran quitté ; `key`
 *   distingue deux captures successives, pour un formulaire vierge chacune.
 */
type OpenedVisual =
    | {
          kind: 'tmdb';
          backdrop: AdminBackdrop;
          state: CropState;
          focusFrame: boolean;
      }
    | {
          kind: 'capture';
          key: string;
          blob: Blob;
          objectUrl: string;
          state: CropState;
          focusFrame: boolean;
      };

/**
 * Le chemin TMDB du visuel ouvert — ce que la grille et la bande situent —,
 * `null` sans visuel ouvert ou pour une capture, qui n'est dans aucune des
 * deux.
 */
function openedPath(opened: OpenedVisual | null): string | null {
    return opened?.kind === 'tmdb' ? opened.backdrop.file_path : null;
}

/**
 * Retient l'URL d'objet de la capture ouverte, et rend au navigateur celle
 * qu'elle remplace : une capture remplacée, fermée ou envoyée ne garde pas
 * son image en mémoire.
 */
function holdCaptureUrl(
    held: RefObject<string | null>,
    next: string | null,
): void {
    if (held.current !== null && held.current !== next) {
        URL.revokeObjectURL(held.current);
    }

    held.current = next;
}

/**
 * L'écran quitté : la préparation en cours devient périmée — son résultat
 * n'ouvrira rien —, et l'URL d'objet de la capture ouverte est rendue.
 */
function abandonCapture(
    sequence: RefObject<number>,
    held: RefObject<string | null>,
): void {
    sequence.current += 1;
    holdCaptureUrl(held, null);
}

/**
 * Vrai pour un champ où un collage de texte a un sens : champ de saisie
 * textuel, zone de texte, contenu éditable. Un collage de texte y suit
 * toujours son cours, même quand le presse-papiers porte aussi une image.
 */
function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.isContentEditable || target instanceof HTMLTextAreaElement) {
        return true;
    }

    return (
        target instanceof HTMLInputElement &&
        !['button', 'checkbox', 'file', 'radio', 'range', 'submit'].includes(
            target.type,
        )
    );
}

/** L'avertissement de couverture demandé, pour quelle image. */
type WarningRequest = {
    frameId: number;
    status: 'loading' | 'ready' | 'failed';
};

type SequenceMask = 'in_play' | 'after_review';

/**
 * L'éditeur de la banque d'images d'un film (spec 20 § 6, lot L20-10).
 *
 * Trois zones et un pied, dans l'ordre de tabulation du § 6.4 : la grille des
 * visuels TMDB, le recadreur avec le niveau et l'envoi, la banque du film —
 * couverture, images groupées par niveau et leurs gestes, prévisualisation
 * en conditions de jeu —, puis la publiabilité.
 *
 * - **La page n'attend jamais TMDB** : `backdrops` est une prop différée,
 *   chargée après l'affichage ; une panne ou un quota atteint deviennent un
 *   état traduit, rejouable d'un bouton (§ 3.6, § 6.2).
 * - **Le curateur n'attend jamais le job** : l'ajout part en file, la banque
 *   montre l'image « en traitement », et l'écran se recharge partiellement
 *   toutes les `pollSeconds` secondes tant qu'une image est en traitement.
 * - Le recadreur et ses gestes demandent un écran large : sous `lg`, la zone
 *   le dit, et le reste de la page demeure utilisable (§ 6.1).
 * - Une déconnexion pendant une visite laisse les formulaires tels quels et
 *   se signale par un toast (§ 6.8).
 * - **Raccourcis de débit et bande balayable** (lot L20-11) : le cadre
 *   focalisé classe et envoie d'une touche (`1` à `5`) ; `[` et `]`, la
 *   bande des visuels non utilisés et ses gestes passent au visuel voisin
 *   sans quitter le cadre ; après chaque envoi, le visuel suivant de la
 *   bande s'ouvre dans le cadre, qui garde le focus. Une région vivante
 *   annonce le niveau choisi et le visuel ouvert. L'écran reste
 *   intégralement opérable par ses boutons et le clavier sans eux
 *   (principe 8) : ils sont hors de la barre « terminé ».
 *
 * - **Publier le film** (lot L20-13), au pied : actif quand le film est
 *   publiable, sinon inactif avec la condition manquante nommée ; la
 *   confirmation montre d'abord l'avertissement nominatif d'ambiguïté
 *   (spec 20 § 8.1, § 8.2).
 * - **Film suivant** (lot L20-15), au pied, après la publication : le premier
 *   film de la file autre que celui-ci, dans la strate que les filtres de la
 *   file ont posée sur l'URL (§ 4.1, § 6.1) ; dernier arrêt de tabulation,
 *   après la publication (§ 6.4).
 * - **Temps actif** (lot L20-17) : un battement de débit après chaque saisie
 *   (§ 10.1) ; le serveur n'ajoute que les écarts qui tiennent dans la
 *   fenêtre d'inactivité, et seulement tant que le film est en passe 1.
 * - **Voie capture** (lot L20-33, D38 du 28/09), tant que le site la laisse
 *   ouverte : une image choisie, ou collée n'importe où sur l'écran, est
 *   préparée par le navigateur (`capture-encoder` : WebP à la largeur du
 *   master, sous le plafond d'entrée — R-46), puis s'ouvre dans le MÊME
 *   cadre, avec le même niveau et le même envoi, plus son minutage
 *   obligatoire. Le serveur revalide tout et dérive le jeu lui-même. Une
 *   capture n'est ni dans la grille ni dans la bande : les raccourcis de
 *   voisinage n'y font rien, et après l'envoi le cadre se ferme, le focus
 *   revenant à « Choisir une image ».
 */
export default function AdminCatalogBank({
    movie,
    frames,
    limits,
    captureEnabled,
    pollSeconds,
    heartbeatSeconds,
    sequencePreview,
    abilities,
    unpublish_preview,
    publication_preview,
    backdrops,
}: Props) {
    const { t, locale } = useTranslations();
    const { url } = usePage();
    const gridRef = useRef<BackdropGridHandle>(null);
    const bankRef = useRef<HTMLDivElement>(null);
    const gestureTriggerRef = useRef<HTMLElement | null>(null);

    // Le temps actif de curation du film (spec 20 § 10.1, lot L20-17) : un
    // battement après chaque saisie, le serveur décide du reste.
    useCurationHeartbeat(movie.id, heartbeatSeconds);

    // La publication du film, au pied (lot L20-13).
    const [publishing, setPublishing] = useState(false);
    const publishTriggerRef = useRef<HTMLElement | null>(null);
    const footerRef = useRef<HTMLDivElement>(null);
    const publicationPreview = usePublicationPreview();

    // Zone 2 : le visuel ouvert dans le cadre, et son niveau.
    const [opened, setOpened] = useState<OpenedVisual | null>(null);
    const [level, setLevel] = useState<FrameLevel | null>(null);

    // Envoi en cours, envoi par raccourci, et ce que la région vivante dit.
    const [sending, setSending] = useState(false);
    const submitRef = useRef<(() => void) | null>(null);
    const [announcement, setAnnouncement] = useState('');

    // Voie capture : la préparation en cours et son rang — un résultat
    // dépassé par une capture plus récente, un visuel ouvert entre-temps ou
    // l'écran quitté est ignoré —, l'URL d'objet de la capture ouverte, le
    // bouton où revient le focus, et le minutage saisi.
    const [captureStatus, setCaptureStatus] = useState<CapturePickerStatus>({
        kind: 'idle',
    });
    const captureSequenceRef = useRef(0);
    const captureUrlRef = useRef<string | null>(null);
    const captureButtonRef = useRef<HTMLButtonElement>(null);
    const [timecode, setTimecode] = useState('');
    // Le refus du minutage ne s'affiche qu'une fois le champ quitté rempli,
    // ou l'envoi tenté : jamais pendant la frappe.
    const [timecodeChecked, setTimecodeChecked] = useState(false);
    const timecodeId = useId();
    const timecodeHintId = `${timecodeId}-hint`;
    const timecodeErrorId = `${timecodeId}-error`;

    // Gestes sur une image de la banque.
    const [gesture, setGesture] = useState<FrameGesture | null>(null);
    const [warningRequest, setWarningRequest] = useState<WarningRequest | null>(
        null,
    );

    // Rechargements partiels.
    const [backdropsReloading, setBackdropsReloading] = useState(false);
    const [refreshFailed, setRefreshFailed] = useState(false);

    // Ce qui retient l'envoi : un cadre hors plancher, le minutage d'une
    // capture absent ou mal formé — le même prédicat pour le bouton et pour
    // les raccourcis `1` à `5`, qui posteraient sinon un envoi refusé.
    const cropViolation =
        opened === null ? null : cropStateViolation(opened.state);
    const timecodeMissing =
        opened?.kind === 'capture' && parseTimecode(timecode) === null;
    const canSend = cropViolation === null && !timecodeMissing;
    const capturePreparing = captureStatus.kind === 'preparing';

    /*
     * Tant qu'une image est en traitement, la banque, le film et les
     * séquences se rechargent — jamais les visuels ni les formulaires : un
     * curateur en train de recadrer ne perd rien.
     *
     * Une panne du rechargement reste DANS l'écran (« Réessayer » sous la
     * banque) : rendre `false` retient l'événement global `networkError`,
     * qui ferait sinon un toast à chaque tick du sondage pendant toute la
     * coupure.
     */
    const { start, stop } = usePoll(
        pollSeconds * MS_PER_SECOND,
        () => ({
            only: POLLED_PROPS,
            onSuccess: () => setRefreshFailed(false),
            onHttpException: () => setRefreshFailed(true),
            onNetworkError: () => {
                setRefreshFailed(true);

                return false;
            },
        }),
        { autoStart: false },
    );

    useEffect(() => {
        if (movie.has_pending) {
            start();
        } else {
            stop();
        }
    }, [movie.has_pending, start, stop]);

    // Déconnexion ou erreur réseau d'une visite : rien n'est parti, le
    // formulaire reste tel quel, et le curateur l'apprend (§ 6.8).
    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    function refreshBank(): void {
        router.reload({
            only: POLLED_PROPS,
            onSuccess: () => setRefreshFailed(false),
            onHttpException: () => setRefreshFailed(true),
            onNetworkError: () => {
                setRefreshFailed(true);

                return false;
            },
        });
    }

    function reloadBackdrops(): void {
        router.reload({
            only: ['backdrops'],
            onStart: () => setBackdropsReloading(true),
            onFinish: () => setBackdropsReloading(false),
        });
    }

    const backdropItems: AdminBackdrop[] =
        backdrops?.status === 'ready' ? backdrops.items : [];

    /** Le visuel dans la grille, tel que son texte alternatif le situe. */
    function gridPlace(backdrop: AdminBackdrop): {
        index: number;
        count: number;
    } {
        return {
            index:
                backdropItems.findIndex(
                    (item) => item.file_path === backdrop.file_path,
                ) + 1,
            count: backdropItems.length,
        };
    }

    function openVisual(backdrop: AdminBackdrop, focusFrame = false): boolean {
        // Pendant un envoi, le cadre ne change pas : la réponse de l'envoi
        // fermerait ou remplacerait sinon un visuel ouvert entre-temps.
        if (sending) {
            return false;
        }

        const state = openCrop(
            masterHeightFor(backdrop.width, backdrop.height),
            limits,
            performance.now(),
        );

        // Aucun cadre admis : le serveur l'a déjà dit, le visuel est proposé
        // désactivé et ne s'ouvre pas.
        if (state === null) {
            return false;
        }

        // Un visuel choisi l'emporte sur une capture : celle qui était
        // ouverte rend son URL d'objet, celle qui se préparait est ignorée.
        holdCaptureUrl(captureUrlRef, null);
        captureSequenceRef.current += 1;
        setCaptureStatus({ kind: 'idle' });

        // Chaque visuel ouvert repart sans niveau : aucun défaut pré-coché,
        // pas même celui du visuel précédent (§ 6.5).
        setLevel(null);
        setOpened({ kind: 'tmdb', backdrop, state, focusFrame });

        return true;
    }

    /**
     * Ferme le cadre. Le focus revient d'où le visuel était venu : la grille
     * pour un visuel TMDB, « Choisir une image » pour une capture.
     */
    function closeVisual(): void {
        const capture = opened?.kind === 'capture';

        holdCaptureUrl(captureUrlRef, null);
        setOpened(null);
        setLevel(null);

        if (capture) {
            captureButtonRef.current?.focus();
        } else {
            gridRef.current?.focus();
        }
    }

    /** Le refus d'une capture que le navigateur n'a pas pu préparer. */
    function captureRefusal(refusal: CaptureRefusal): string {
        switch (refusal) {
            case 'unreadable':
                return t('admin.frame.capture.ui.unreadable');
            case 'too_small':
                return t('admin.frame.capture.ui.too_small', {
                    width: formatInteger(FRAME_GEOMETRY.gameWidth, locale),
                });
            case 'too_heavy':
                return t('admin.frame.capture.ui.too_heavy', {
                    max: formatInteger(limits.frameUploadMaxKilobytes, locale),
                });
            case 'unsupported':
                return t('admin.frame.capture.ui.unsupported');
        }
    }

    /**
     * Une capture choisie ou collée (§ 5.4) : préparée par le navigateur,
     * puis ouverte dans le cadre, qui prend le focus, sans niveau ni
     * minutage. Le cadre s'ouvre — et `crop_seconds` court — quand l'image
     * est prête, jamais pendant sa préparation.
     */
    async function openCapture(file: Blob): Promise<void> {
        captureSequenceRef.current += 1;

        const sequence = captureSequenceRef.current;

        setCaptureStatus({ kind: 'preparing' });

        const result = await normalizeCapture(file, limits);

        if (sequence !== captureSequenceRef.current) {
            return;
        }

        const state =
            result.status === 'ok'
                ? openCrop(result.masterHeight, limits, performance.now())
                : null;

        if (result.status !== 'ok' || state === null) {
            setCaptureStatus({
                kind: 'failed',
                message: captureRefusal(
                    result.status === 'ok' ? 'too_small' : result.status,
                ),
            });

            return;
        }

        const objectUrl = URL.createObjectURL(result.blob);

        holdCaptureUrl(captureUrlRef, objectUrl);
        setCaptureStatus({ kind: 'idle' });
        setLevel(null);
        setTimecode('');
        setTimecodeChecked(false);
        setOpened({
            kind: 'capture',
            key: `capture-${sequence}`,
            blob: result.blob,
            objectUrl,
            state,
            focusFrame: true,
        });
        setAnnouncement(t('admin.frame.capture.ui.opened'));
    }

    /**
     * Après l'envoi d'une capture : le cadre se ferme et le focus revient à
     * « Choisir une image », prêt pour la suivante. Aucun visuel ne s'ouvre
     * de lui-même : l'enchaînement de la bande est propre aux visuels TMDB.
     */
    function captureSent(): void {
        closeVisual();
        setAnnouncement(t('admin.frame.capture.ui.sent'));
    }

    /*
     * Coller une image n'importe où sur l'écran l'ouvre comme une capture —
     * seulement si le presse-papiers porte une image, que la voie est
     * affichée (écran large, ajout permis), qu'aucun envoi n'est en cours et
     * qu'aucune boîte de dialogue n'est ouverte. Sinon, rien n'est retenu :
     * le texte collé dans un champ suit son cours — y compris quand le
     * presse-papiers porte AUSSI une image, comme une plage de tableur copiée,
     * que Chromium livre avec son rendu en bitmap. L'Effect Event lit l'état
     * du dernier rendu sans réabonner l'écouteur.
     */
    const handlePaste = useEffectEvent((event: ClipboardEvent): void => {
        const button = captureButtonRef.current;

        if (
            !captureEnabled ||
            !abilities.createFrame ||
            sending ||
            gesture !== null ||
            publishing ||
            button === null ||
            button.getClientRects().length === 0
        ) {
            return;
        }

        if (
            isEditableTarget(event.target) &&
            (event.clipboardData?.types ?? []).includes('text/plain')
        ) {
            return;
        }

        const image = pickClipboardImage(
            Array.from(event.clipboardData?.files ?? []),
        );

        if (image === null) {
            return;
        }

        event.preventDefault();
        void openCapture(image);
    });

    useEffect(() => {
        const listener = (event: ClipboardEvent): void => handlePaste(event);

        document.addEventListener('paste', listener);

        return () => document.removeEventListener('paste', listener);
    }, []);

    // L'écran quitté : une préparation en cours devient périmée, et l'URL
    // d'objet de la capture ouverte est rendue au navigateur.
    useEffect(
        () => () => abandonCapture(captureSequenceRef, captureUrlRef),
        [],
    );

    /**
     * Le visuel voisin de la bande dans le cadre (`Page précédente` /
     * `Page suivante`, `[`, `]`, boutons et glissement de la bande, § 6.2).
     * `focusFrame` : le pas part du cadre ou de ses contrôles, que l'ouverture
     * remplace — le nouveau cadre prend le focus ; depuis la bande, le focus
     * reste dans la bande.
     */
    function stepVisual(
        direction: StripDirection,
        focusFrame: boolean,
    ): AdminBackdrop | null {
        // Une capture n'est pas dans la bande : aucun pas ne la quitte — ni
        // raccourci, ni bouton, ni glissement (§ 6.3). La remplacer demande
        // un geste explicite : un visuel choisi dans la grille ou la bande,
        // ou « Abandonner la capture ».
        if (opened?.kind === 'capture') {
            setAnnouncement(t('admin.frame.capture.ui.strip_locked'));

            return null;
        }

        const neighbour = stripNeighbour(
            backdropItems,
            openedPath(opened),
            direction,
        );

        if (neighbour === null || !openVisual(neighbour, focusFrame)) {
            setAnnouncement(
                t(
                    direction === 'next'
                        ? 'admin.bank.strip.edge_next'
                        : 'admin.bank.strip.edge_previous',
                ),
            );

            return null;
        }

        setAnnouncement(t('admin.bank.strip.opened', gridPlace(neighbour)));

        return neighbour;
    }

    /**
     * Après un envoi réussi, le visuel suivant de la bande s'ouvre dans le
     * cadre, qui garde le focus (§ 6.3) ; la bande épuisée, le cadre se ferme
     * et le focus revient à la grille. La liste lue est celle de l'envoi :
     * le visuel envoyé n'y est jamais proposé.
     */
    function advanceAfterSend(sentPath: string): void {
        const next = stripAfterSend(backdropItems, sentPath);

        if (next !== null && openVisual(next, true)) {
            setAnnouncement(t('admin.bank.strip.sent_next', gridPlace(next)));

            return;
        }

        closeVisual();
        setAnnouncement(t('admin.bank.strip.exhausted'));
    }

    /**
     * `1` à `5` depuis le cadre (§ 6.4) : le niveau est posé et annoncé, puis
     * l'image part — sauf si le cadre viole le plancher, ou si une capture
     * attend encore son minutage, auquel cas le niveau reste choisi et
     * l'envoi attend. Le niveau est rendu avant l'envoi (`flushSync`), pour
     * que le formulaire le soumette.
     */
    function classify(chosen: FrameLevel, send: boolean): void {
        const values = {
            level: chosen,
            label: t(FRAME_LEVEL_KEYS[chosen].label),
        };

        flushSync(() => setLevel(chosen));

        // Un cadre admis, mais une capture sans minutage conforme : c'est lui
        // que l'envoi attend, et son refus s'affiche sous le champ.
        if (!send && cropViolation === null && timecodeMissing) {
            setTimecodeChecked(true);
            setAnnouncement(
                t('admin.frame.capture.ui.timecode_pending', values),
            );

            return;
        }

        setAnnouncement(
            t(
                send
                    ? 'admin.shortcuts.announce.sending'
                    : 'admin.shortcuts.announce.blocked',
                values,
            ),
        );

        if (send) {
            submitRef.current?.();
        }
    }

    function requestWarning(frameId: number): void {
        setWarningRequest({ frameId, status: 'loading' });

        const settle = (status: WarningRequest['status']): void =>
            setWarningRequest((current) =>
                current?.frameId === frameId ? { frameId, status } : current,
            );

        router.reload({
            only: ['unpublish_preview'],
            data: { [PREVIEW_FRAME_PARAMETER]: frameId },
            preserveUrl: true,
            onSuccess: () => settle('ready'),
            onHttpException: () => settle('failed'),
            onNetworkError: () => settle('failed'),
        });
    }

    function openGesture(
        kind: FrameGestureKind,
        target: FrameGestureTarget,
    ): void {
        gestureTriggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setGesture({ kind, target, openedAtMs: performance.now() });

        // Seule une image en jeu peut, en sortant du jeu, casser la
        // couverture d'un film publié : l'avertissement n'est demandé que
        // pour elle, avant tout envoi.
        if (target.frame.curation_state === 'in_play') {
            requestWarning(target.frame.id);
        } else {
            setWarningRequest(null);
        }
    }

    /*
     * À la fermeture d'un geste, le focus revient au bouton qui l'a ouvert.
     * Un geste réussi peut l'avoir fait disparaître — une image écartée n'a
     * plus de bouton « Écarter » — : il revient alors à la banque elle-même,
     * jamais au document.
     */
    function returnGestureFocus(): void {
        const trigger = gestureTriggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            bankRef.current?.focus();
        }
    }

    /**
     * « Publier le film » : l'avertissement d'ambiguïté est demandé à
     * l'ouverture, avant tout envoi (spec 20 § 8.2).
     */
    function openPublish(): void {
        publishTriggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setPublishing(true);
        publicationPreview.request();
    }

    /**
     * Un film publié n'a plus de bouton « Publier » : le focus revient alors
     * au pied lui-même, jamais au document.
     */
    function returnPublishFocus(): void {
        const trigger = publishTriggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            footerRef.current?.focus();
        }
    }

    const warning: CoverageWarning =
        gesture === null ||
        warningRequest === null ||
        warningRequest.frameId !== gesture.target.frame.id
            ? { status: 'ready', preview: null }
            : warningRequest.status === 'ready'
              ? {
                    status: 'ready',
                    preview:
                        unpublish_preview?.frame_id === gesture.target.frame.id
                            ? unpublish_preview
                            : null,
                }
              : { status: warningRequest.status };

    // Pendant la préparation d'une capture, qui va remplacer le cadre, aucun
    // raccourci n'agit ; une capture ouverte n'a pas de voisin : `[`, `]` et
    // Page préc./suiv. suivent alors leur cours.
    const handleCropperShortcut = useThroughputShortcuts(
        { screen: 'cropper', canSend, busy: sending || capturePreparing },
        {
            onClassify: classify,
            ...(opened?.kind === 'capture'
                ? {}
                : {
                      onNeighbour: (direction: StripDirection) => {
                          stepVisual(direction, true);
                      },
                  }),
        },
    );

    const publishable =
        movie.content_flag === 'clear' && movie.coverage.covers_publishable;

    return (
        <>
            <Head title={t('admin.bank.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={movie.title_original}
                    description={t(
                        captureEnabled
                            ? 'admin.bank.description'
                            : 'admin.bank.description_tmdb_only',
                    )}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={catalogShow(movie.id)}>
                                <ArrowLeftIcon aria-hidden />
                                {t('admin.bank.back')}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <AvailabilityBadge value={movie.availability} />
                    <ContentFlagBadge value={movie.content_flag} />
                    {movie.release_year !== null && (
                        <span className="text-sm text-muted-foreground">
                            {t('admin.common.label_value', {
                                label: t('admin.movie.identity.release_year'),
                                value: movie.release_year,
                            })}
                        </span>
                    )}
                </div>

                {movie.processing_stalled && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon aria-hidden />
                        <AlertTitle>
                            {t('admin.bank.processing_stalled')}
                        </AlertTitle>
                    </Alert>
                )}

                {/* Zone 1 — les visuels TMDB du film (§ 6.2). */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.bank.backdrops.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.bank.backdrops.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!captureEnabled && (
                            <Alert>
                                <InfoIcon aria-hidden />
                                <AlertDescription>
                                    {t('admin.frame.capture.disabled_notice')}
                                </AlertDescription>
                            </Alert>
                        )}

                        <Deferred
                            data="backdrops"
                            fallback={
                                <AdminLoadingState
                                    label={t('admin.bank.backdrops.loading')}
                                    rows={3}
                                />
                            }
                        >
                            {backdrops === undefined ? null : (
                                <BackdropsZone
                                    backdrops={backdrops}
                                    reloading={backdropsReloading}
                                    openedPath={openedPath(opened)}
                                    onOpen={openVisual}
                                    onRetry={reloadBackdrops}
                                    gridRef={gridRef}
                                />
                            )}
                        </Deferred>
                    </CardContent>
                </Card>

                {/* Zone 2 — le recadreur, le niveau et l'envoi (§ 6.3). */}
                <Card className="gap-3 py-4">
                    <CardHeader className="gap-1 px-4 sm:px-5 lg:flex-row lg:items-baseline lg:gap-3">
                        <AdminCardTitle>
                            {t('admin.bank.cropper.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.bank.cropper.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 px-4 sm:px-5">
                        <p className="text-sm text-muted-foreground lg:hidden">
                            {t('admin.bank.desktop_required')}
                        </p>

                        <div className="hidden lg:block">
                            {/* Le point d'entrée de la voie capture, hors
                                du formulaire : la source brute n'est jamais
                                sérialisée (§ 5.4). */}
                            {abilities.createFrame && captureEnabled && (
                                <CaptureSourcePicker
                                    status={captureStatus}
                                    disabled={sending}
                                    onPick={(file) => {
                                        void openCapture(file);
                                    }}
                                    buttonRef={captureButtonRef}
                                    className="mb-3"
                                />
                            )}

                            {!abilities.createFrame ? (
                                <Alert>
                                    <InfoIcon aria-hidden />
                                    <AlertDescription>
                                        {t('admin.bank.cropper.locked')}
                                    </AlertDescription>
                                </Alert>
                            ) : opened === null ? (
                                <AdminEmptyState
                                    icon={ImagePlusIcon}
                                    title={t(
                                        captureEnabled
                                            ? 'admin.bank.cropper.empty'
                                            : 'admin.bank.cropper.empty_tmdb_only',
                                    )}
                                />
                            ) : (
                                <Form
                                    // Un formulaire vierge par visuel ou
                                    // par capture : les refus d'un envoi
                                    // (doublon, dimensions) ne
                                    // s'affichent jamais sous un autre.
                                    key={
                                        opened.kind === 'tmdb'
                                            ? opened.backdrop.file_path
                                            : opened.key
                                    }
                                    ref={(handle) => {
                                        submitRef.current =
                                            handle === null
                                                ? null
                                                : () => handle.submit();
                                    }}
                                    {...(opened.kind === 'tmdb'
                                        ? FrameTmdbController.store.form(
                                              movie.id,
                                          )
                                        : FrameCaptureController.store.form(
                                              movie.id,
                                          ))}
                                    noValidate
                                    options={{
                                        preserveScroll: true,
                                        preserveState: true,
                                        only: BANK_WRITE_PROPS,
                                    }}
                                    transform={(data) => ({
                                        ...data,
                                        // Le niveau tenu par l'écran fait
                                        // foi, posé à l'instant par un
                                        // raccourci.
                                        ...(level === null
                                            ? {}
                                            : { frame_level: level }),
                                        crop_seconds: cropSecondsAt(
                                            opened.state,
                                            performance.now(),
                                        ),
                                        // Une capture part avec le seul
                                        // fichier préparé par le
                                        // navigateur (R-46) : sa
                                        // présence fait de l'envoi un
                                        // envoi multipart.
                                        ...(opened.kind === 'capture'
                                            ? {
                                                  source_timecode:
                                                      timecode.trim(),
                                                  source: new File(
                                                      [opened.blob],
                                                      CAPTURE_FILE_NAME,
                                                      {
                                                          type: CAPTURE_MIME_TYPE,
                                                      },
                                                  ),
                                              }
                                            : {}),
                                    })}
                                    onStart={() => setSending(true)}
                                    onFinish={() => setSending(false)}
                                    onSuccess={() => {
                                        if (opened.kind === 'tmdb') {
                                            advanceAfterSend(
                                                opened.backdrop.file_path,
                                            );
                                        } else {
                                            captureSent();
                                        }
                                    }}
                                    onKeyDown={handleCropperShortcut}
                                    className="flex flex-col gap-3"
                                >
                                    {({ processing, errors }) => {
                                        const blocked =
                                            !canSend ||
                                            level === null ||
                                            capturePreparing;
                                        const timecodeError =
                                            timecodeChecked && timecodeMissing
                                                ? t(
                                                      'admin.frame.capture.ui.timecode_invalid',
                                                  )
                                                : errors.source_timecode;

                                        return (
                                            <>
                                                {opened.kind === 'tmdb' && (
                                                    <input
                                                        type="hidden"
                                                        name="tmdb_file_path"
                                                        value={
                                                            opened.backdrop
                                                                .file_path
                                                        }
                                                    />
                                                )}
                                                <input
                                                    type="hidden"
                                                    name="crop_x"
                                                    value={opened.state.crop.x}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="crop_y"
                                                    value={opened.state.crop.y}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="crop_width"
                                                    value={
                                                        opened.state.crop.width
                                                    }
                                                />
                                                <input
                                                    type="hidden"
                                                    name="crop_height"
                                                    value={
                                                        opened.state.crop.height
                                                    }
                                                />

                                                <FrameCropper
                                                    imageUrl={
                                                        opened.kind === 'tmdb'
                                                            ? opened.backdrop
                                                                  .image_url
                                                            : opened.objectUrl
                                                    }
                                                    failedDescription={
                                                        opened.kind ===
                                                        'capture'
                                                            ? t(
                                                                  'admin.cropper.image_failed_capture',
                                                              )
                                                            : undefined
                                                    }
                                                    autoFocus={
                                                        opened.focusFrame
                                                    }
                                                    keyShortcuts={
                                                        CROPPER_KEY_SHORTCUTS
                                                    }
                                                    shortcutHint={t(
                                                        'admin.shortcuts.cropper_hint',
                                                    )}
                                                    state={opened.state}
                                                    onCommand={(command) =>
                                                        setOpened({
                                                            ...opened,
                                                            state: applyCropCommand(
                                                                opened.state,
                                                                command,
                                                            ),
                                                        })
                                                    }
                                                    disabled={processing}
                                                />

                                                {/* Le refus du
                                                    minutage s'affiche
                                                    sous son champ, pas
                                                    ici. */}
                                                <AdminInputError
                                                    message={
                                                        errors.source ??
                                                        errors.tmdb_file_path ??
                                                        errors.crop ??
                                                        errors.crop_x ??
                                                        errors.crop_y ??
                                                        errors.crop_width ??
                                                        errors.crop_height ??
                                                        errors.crop_seconds
                                                    }
                                                />

                                                {opened.kind === 'capture' && (
                                                    <div className="flex flex-wrap items-start gap-x-4 gap-y-2">
                                                        <Badge variant="secondary">
                                                            {t(
                                                                'admin.frame.capture.ui.source_label',
                                                            )}
                                                        </Badge>
                                                        <div className="flex w-full max-w-xs flex-col gap-1.5">
                                                            <Label
                                                                htmlFor={
                                                                    timecodeId
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.frame.capture.ui.timecode_label',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                id={timecodeId}
                                                                name="source_timecode"
                                                                value={timecode}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setTimecode(
                                                                        event
                                                                            .currentTarget
                                                                            .value,
                                                                    )
                                                                }
                                                                onBlur={() => {
                                                                    if (
                                                                        timecode.trim() !==
                                                                        ''
                                                                    ) {
                                                                        setTimecodeChecked(
                                                                            true,
                                                                        );
                                                                    }
                                                                }}
                                                                required
                                                                // Aucun `inputMode="numeric"` : les
                                                                // pavés numériques des tablettes n'ont
                                                                // pas le « : » que le minutage exige.
                                                                autoComplete="off"
                                                                spellCheck={
                                                                    false
                                                                }
                                                                aria-describedby={
                                                                    timecodeError ===
                                                                    undefined
                                                                        ? timecodeHintId
                                                                        : `${timecodeHintId} ${timecodeErrorId}`
                                                                }
                                                                aria-invalid={
                                                                    timecodeError ===
                                                                    undefined
                                                                        ? undefined
                                                                        : true
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
                                                                className="min-h-11"
                                                            />
                                                            <p
                                                                id={
                                                                    timecodeHintId
                                                                }
                                                                className="text-xs text-muted-foreground"
                                                            >
                                                                {t(
                                                                    'admin.frame.capture.ui.timecode_hint',
                                                                )}
                                                            </p>
                                                            <AdminInputError
                                                                id={
                                                                    timecodeErrorId
                                                                }
                                                                message={
                                                                    timecodeError
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                )}

                                                <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-end">
                                                    <LevelPicker
                                                        value={level}
                                                        onValueChange={setLevel}
                                                        error={
                                                            errors.frame_level
                                                        }
                                                        disabled={processing}
                                                    />

                                                    <div className="flex flex-col gap-2 xl:min-w-max">
                                                        {level === null && (
                                                            <p className="text-xs text-muted-foreground">
                                                                {t(
                                                                    'admin.bank.cropper.level_required',
                                                                )}
                                                            </p>
                                                        )}

                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                type="submit"
                                                                aria-disabled={
                                                                    blocked ||
                                                                    processing
                                                                        ? true
                                                                        : undefined
                                                                }
                                                                aria-busy={
                                                                    processing ||
                                                                    undefined
                                                                }
                                                                onClick={(
                                                                    event,
                                                                ) => {
                                                                    if (
                                                                        blocked ||
                                                                        processing
                                                                    ) {
                                                                        event.preventDefault();
                                                                    }

                                                                    // Un envoi tenté sans
                                                                    // minutage conforme
                                                                    // montre son refus.
                                                                    if (
                                                                        timecodeMissing
                                                                    ) {
                                                                        setTimecodeChecked(
                                                                            true,
                                                                        );
                                                                    }
                                                                }}
                                                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                                            >
                                                                <ImagePlusIcon
                                                                    aria-hidden
                                                                />
                                                                {processing
                                                                    ? t(
                                                                          'admin.bank.cropper.adding',
                                                                      )
                                                                    : t(
                                                                          'admin.bank.cropper.add',
                                                                      )}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={
                                                                    closeVisual
                                                                }
                                                                disabled={
                                                                    processing
                                                                }
                                                                className="min-h-11"
                                                            >
                                                                <XIcon
                                                                    aria-hidden
                                                                />
                                                                {opened.kind ===
                                                                'capture'
                                                                    ? t(
                                                                          'admin.frame.capture.ui.discard',
                                                                      )
                                                                    : t(
                                                                          'admin.bank.cropper.close',
                                                                      )}
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </>
                                        );
                                    }}
                                </Form>
                            )}

                            {/*
                             * La région vivante des raccourcis et de la
                             * bande : hors du formulaire, qui se remonte à
                             * chaque visuel, pour qu'une annonce survive à
                             * l'ouverture du suivant.
                             */}
                            <p
                                role="status"
                                aria-live="polite"
                                aria-atomic="true"
                                className="mt-2 text-xs text-foreground"
                            >
                                {announcement}
                            </p>

                            {abilities.createFrame &&
                                backdrops?.status === 'ready' && (
                                    <div className="mt-3">
                                        <BackdropStrip
                                            items={backdrops.items}
                                            openedPath={openedPath(opened)}
                                            disabled={sending}
                                            onOpen={(backdrop) => {
                                                if (openVisual(backdrop)) {
                                                    setAnnouncement(
                                                        t(
                                                            'admin.bank.strip.opened',
                                                            gridPlace(backdrop),
                                                        ),
                                                    );
                                                }
                                            }}
                                            onStep={(direction) =>
                                                stepVisual(direction, false)
                                            }
                                        />
                                    </div>
                                )}
                        </div>
                    </CardContent>
                </Card>

                {/* Zone 3 — la banque du film (§ 6.1, § 6.6, § 6.7). */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.bank.list.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.bank.list.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {movie.has_pending && (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {t('admin.bank.processing_notice')}
                            </p>
                        )}

                        {refreshFailed && (
                            <AdminErrorState
                                title={t('admin.bank.refresh_failed')}
                                retryLabel={t('admin.bank.retry')}
                                onRetry={refreshBank}
                            />
                        )}

                        <section
                            aria-labelledby="bank-coverage-heading"
                            className="space-y-3"
                        >
                            <h3
                                id="bank-coverage-heading"
                                className="text-sm font-semibold text-foreground"
                            >
                                {t('admin.bank.coverage.heading')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.bank.coverage.description')}
                            </p>
                            <CoverageMeter coverage={movie.coverage} />
                        </section>

                        <div
                            ref={bankRef}
                            tabIndex={-1}
                            className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <FrameBankList
                                frames={frames}
                                movieId={movie.id}
                                captureEnabled={captureEnabled}
                                onGesture={openGesture}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.bank.preview.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.bank.preview.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SequencePreview sequences={sequencePreview} />
                    </CardContent>
                </Card>

                {/* Pied — la publiabilité du film (§ 6.1, § 8.1). */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.bank.footer.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <ShortcutReminder />
                        <div
                            ref={footerRef}
                            tabIndex={-1}
                            className="space-y-4 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Alert
                                variant={
                                    publishable ? 'default' : 'destructive'
                                }
                            >
                                <AlertTitle>
                                    {t(
                                        publishabilityKey(
                                            movie.content_flag,
                                            movie.coverage.covers_publishable,
                                        ),
                                    )}
                                </AlertTitle>
                            </Alert>
                            {abilities.publish && (
                                <PublishButton
                                    publication={movie.publication}
                                    onOpen={openPublish}
                                />
                            )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {/* Le geste de débit qui enchaîne deux films
                                (§ 4.1), au pied, après la publication
                                (§ 6.1, § 6.4) : le premier film de la file
                                autre que celui-ci, dans la strate que les
                                filtres de la file ont posée sur cette URL. */}
                            <Button size="sm" className="min-h-11" asChild>
                                <Link
                                    href={curationNext({
                                        query: {
                                            ...curationFiltersFromUrl(url),
                                            current: movie.id,
                                        },
                                    })}
                                >
                                    {t('admin.curation.next')}
                                    <ArrowRightIcon aria-hidden />
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={catalogShow(movie.id)}>
                                    <ArrowLeftIcon aria-hidden />
                                    {t('admin.bank.back')}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <FrameGestureDialog
                gesture={gesture}
                movieId={movie.id}
                limits={limits}
                warning={warning}
                onRetryWarning={() => {
                    if (gesture !== null) {
                        requestWarning(gesture.target.frame.id);
                    }
                }}
                onClose={() => setGesture(null)}
                onReturnFocus={returnGestureFocus}
            />

            <PublishDialog
                open={publishing}
                movieId={movie.id}
                first={movie.publication.first}
                preview={publication_preview}
                status={publicationPreview.status}
                onRetryPreview={publicationPreview.request}
                only={BANK_WRITE_PROPS}
                onClose={() => setPublishing(false)}
                onReturnFocus={returnPublishFocus}
            />
        </>
    );
}

AdminCatalogBank.layout = { breadcrumbs };

/**
 * Le rappel des raccourcis de débit, au pied de l'éditeur (§ 6.1, § 6.4) :
 * facultatifs, et décrits aussi sur la page « premiers pas » (§ 13.6).
 */
function ShortcutReminder() {
    const { t } = useTranslations();

    return (
        <section aria-labelledby="bank-shortcuts-heading" className="space-y-2">
            <h3
                id="bank-shortcuts-heading"
                className="text-sm font-semibold text-foreground"
            >
                {t('admin.shortcuts.heading')}
            </h3>
            <p className="text-sm text-muted-foreground">
                {t('admin.shortcuts.description')}
            </p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-foreground">
                <li>{t('admin.shortcuts.classify')}</li>
                <li>{t('admin.shortcuts.neighbour')}</li>
                <li>{t('admin.shortcuts.pass')}</li>
            </ul>
        </section>
    );
}

/**
 * Le verdict de publiabilité, les deux conditions ensemble (spec 10 § 4.3) :
 * nommer la moitié qui manque évite de chercher du côté des images ce qui
 * manque du côté du contenu, et inversement.
 */
function publishabilityKey(
    contentFlag: ContentFlag,
    coversPublishable: boolean,
): TranslationKey {
    const contentOk = contentFlag === 'clear';

    if (contentOk && coversPublishable) {
        return 'admin.movie.availability.publishable';
    }

    if (!contentOk && !coversPublishable) {
        return 'admin.movie.availability.blocked_by_both';
    }

    return contentOk
        ? 'admin.movie.availability.blocked_by_levels'
        : 'admin.movie.availability.blocked_by_content';
}

/**
 * Les visuels chargés : la grille, ou l'état qui la remplace — aucun visuel,
 * TMDB en panne, quota atteint, TMDB non configuré.
 *
 * Les visuels auxquels TMDB attache une langue ne sont jamais proposés
 * (D39 du 28/09) : leur nombre s'affiche au-dessus de la grille, et, s'ils
 * sont tous écartés, l'état vide le dit au lieu de prétendre que TMDB ne
 * fournit rien.
 */
function BackdropsZone({
    backdrops,
    reloading,
    openedPath,
    onOpen,
    onRetry,
    gridRef,
}: {
    backdrops: AdminBackdropSet;
    reloading: boolean;
    openedPath: string | null;
    onOpen: (backdrop: AdminBackdrop) => void;
    onRetry: () => void;
    gridRef: RefObject<BackdropGridHandle | null>;
}) {
    const { t, tChoice, locale } = useTranslations();

    const excluded =
        backdrops.excluded > 0
            ? tChoice('admin.bank.backdrops_excluded', backdrops.excluded, {
                  count: formatInteger(backdrops.excluded, locale),
              })
            : null;

    if (reloading) {
        return (
            <AdminLoadingState
                label={t('admin.bank.backdrops.loading')}
                rows={3}
            />
        );
    }

    switch (backdrops.status) {
        case 'empty':
            return excluded === null ? (
                <AdminEmptyState title={t('admin.bank.no_backdrops')} />
            ) : (
                <AdminEmptyState
                    title={t('admin.bank.no_backdrops_all_text')}
                    description={excluded}
                />
            );
        case 'not_configured':
            return (
                <AdminErrorState title={t('admin.tmdb.error.not_configured')} />
            );
        case 'failed':
        case 'rate_limited':
            return (
                <AdminErrorState
                    title={
                        backdrops.status === 'failed'
                            ? t('admin.bank.backdrops_failed')
                            : t('admin.tmdb.error.rate_limited_interactive')
                    }
                    retryLabel={t('admin.bank.retry')}
                    onRetry={onRetry}
                />
            );
        case 'ready':
            return (
                <>
                    {excluded !== null && (
                        <p className="text-sm text-muted-foreground">
                            {excluded}
                        </p>
                    )}
                    <BackdropGrid
                        items={backdrops.items}
                        openedPath={openedPath}
                        onOpen={onOpen}
                        handleRef={gridRef}
                    />
                </>
            );
    }
}

/**
 * La prévisualisation par `N` (§ 6.7) : un `N` et un masque choisis, les
 * paliers dans l'ordre, chacun sur son rendu final aux deux largeurs. Le `N`
 * ouvert d'abord est le plus grand que la banque rendrait jouable après
 * revue — le plus parlant —, sinon le plus petit permis.
 */
function SequencePreview({ sequences }: { sequences: AdminSequencePreview[] }) {
    const { t } = useTranslations();

    const initial =
        [...sequences].reverse().find((entry) => entry.after_review.playable) ??
        sequences[0];

    const [framesPerRound, setFramesPerRound] = useState<number | null>(
        initial?.frames_per_round ?? null,
    );
    const [mask, setMask] = useState<SequenceMask>('in_play');

    const entry =
        sequences.find((item) => item.frames_per_round === framesPerRound) ??
        initial;

    if (entry === undefined) {
        return null;
    }

    const sequence = entry[mask];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div className="flex flex-col gap-1.5">
                    <span
                        id="bank-preview-n"
                        className="text-sm font-medium text-foreground"
                    >
                        {t('admin.bank.preview.frames_per_round')}
                    </span>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        aria-labelledby="bank-preview-n"
                        value={String(entry.frames_per_round)}
                        onValueChange={(value) => {
                            if (value !== '') {
                                setFramesPerRound(Number(value));
                            }
                        }}
                    >
                        {sequences.map((item) => (
                            <ToggleGroupItem
                                key={item.frames_per_round}
                                value={String(item.frames_per_round)}
                                className="min-h-11 min-w-11 px-3"
                            >
                                {t(
                                    'admin.bank.preview.frames_per_round_option',
                                    {
                                        count: item.frames_per_round,
                                    },
                                )}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </div>

                <div className="flex flex-col gap-1.5">
                    <span
                        id="bank-preview-mask"
                        className="text-sm font-medium text-foreground"
                    >
                        {t('admin.bank.preview.mask')}
                    </span>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        aria-labelledby="bank-preview-mask"
                        aria-describedby="bank-preview-mask-hint"
                        value={mask}
                        onValueChange={(value) => {
                            if (
                                value === 'in_play' ||
                                value === 'after_review'
                            ) {
                                setMask(value);
                            }
                        }}
                    >
                        <ToggleGroupItem
                            value="in_play"
                            className="min-h-11 px-3"
                        >
                            {t('admin.bank.preview.mask_in_play')}
                        </ToggleGroupItem>
                        <ToggleGroupItem
                            value="after_review"
                            className="min-h-11 px-3"
                        >
                            {t('admin.bank.preview.mask_after_review')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>
            </div>

            <p
                id="bank-preview-mask-hint"
                className="text-xs text-muted-foreground"
            >
                {t('admin.bank.preview.mask_hint')}
            </p>

            <div role="status" aria-live="polite" className="text-sm">
                {!sequence.playable ? (
                    <p className="text-muted-foreground">
                        {t('admin.bank.preview.unplayable', {
                            count: entry.frames_per_round,
                        })}
                    </p>
                ) : sequence.usesFallback ? (
                    <p className="font-medium text-foreground">
                        {t('admin.bank.preview.fallback', {
                            levels: sequence.levels.join(
                                t('admin.common.list_separator'),
                            ),
                        })}
                    </p>
                ) : (
                    <p className="text-foreground">
                        {t('admin.bank.preview.levels', {
                            levels: sequence.levels.join(
                                t('admin.common.list_separator'),
                            ),
                        })}
                    </p>
                )}
            </div>

            {sequence.playable && (
                <ol className="flex flex-col gap-4">
                    {sequence.frames.map((frame, index) => (
                        <li
                            key={`${frame.level}-${frame.game_url}`}
                            className="flex flex-col gap-2"
                        >
                            <span className="text-sm font-medium text-foreground">
                                {t('admin.bank.preview.tier', {
                                    tier: index + 1,
                                    level: frame.level,
                                })}
                            </span>
                            <GameConditionsPreview
                                gameUrl={frame.game_url}
                                level={frame.level}
                            />
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
