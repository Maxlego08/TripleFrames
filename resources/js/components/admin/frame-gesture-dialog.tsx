import { Form } from '@inertiajs/react';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import FrameCropController from '@/actions/App/Http/Controllers/Admin/FrameCropController';
import FrameLevelController from '@/actions/App/Http/Controllers/Admin/FrameLevelController';
import FrameSuspendController from '@/actions/App/Http/Controllers/Admin/FrameSuspendController';
import FrameUnpublishController from '@/actions/App/Http/Controllers/Admin/FrameUnpublishController';
import FrameUnsuspendController from '@/actions/App/Http/Controllers/Admin/FrameUnsuspendController';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { unpublishKind } from '@/components/admin/frame-bank-list';
import type {
    FrameGestureKind,
    FrameGestureTarget,
} from '@/components/admin/frame-bank-list';
import { FrameCropper } from '@/components/admin/frame-cropper';
import { LevelPicker } from '@/components/admin/level-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useMasterImage } from '@/hooks/admin/use-master-image';
import { useTranslations } from '@/hooks/use-translations';
import { BANK_WRITE_PROPS } from '@/lib/admin/bank-visits';
import {
    applyCropCommand,
    cropSecondsAt,
    cropStateViolation,
    openCrop,
} from '@/lib/admin/crop-state';
import type { CropState } from '@/lib/admin/crop-state';
import { cn } from '@/lib/utils';
import type {
    AdminFrameLimits,
    AdminUnpublishPreview,
    FrameLevel,
} from '@/types/admin';

/** Un geste ouvert : lequel, sur quelle image, et depuis quand. */
export type FrameGesture = {
    kind: FrameGestureKind;
    target: FrameGestureTarget;
    /** Instant d'ouverture, horloge monotone : d'où court `crop_seconds`. */
    openedAtMs: number;
};

/**
 * L'avertissement de couverture d'une image EN JEU (§ 5.7, § 8.4) :
 * `loading` pendant le rechargement partiel qui le calcule, `failed` s'il
 * n'est pas revenu — la confirmation reste alors fermée, puisque
 * l'avertissement doit précéder tout envoi —, `ready` sinon, `preview` nul
 * quand le geste ne casse aucune couverture.
 */
export type CoverageWarning =
    | { status: 'loading' }
    | { status: 'failed' }
    | { status: 'ready'; preview: AdminUnpublishPreview | null };

type Props = {
    gesture: FrameGesture | null;
    movieId: number;
    limits: AdminFrameLimits;
    warning: CoverageWarning;
    onRetryWarning: () => void;
    onClose: () => void;
    /**
     * Rend le focus à l'élément déclencheur à la fermeture. Radix ne le fait
     * que pour un `DialogTrigger` ; les gestes de la banque ouvrent la boîte
     * depuis un bouton ordinaire, que l'éditeur retient.
     */
    onReturnFocus: () => void;
};

/**
 * La confirmation des trois gestes qui touchent une image de la banque
 * (spec 20 § 5.7, § 8.4) : re-recadrer, changer de niveau, dépublier ou
 * écarter.
 *
 * - Sur une image **en jeu**, le geste la fait sortir du jeu : la boîte le
 *   dit, et affiche AVANT tout envoi l'avertissement de couverture que le
 *   serveur calcule (`unpublish_preview`, `CoverageLossPreview`) quand le
 *   geste casse la couverture 1-3-5 d'un film publié. Le geste reste permis,
 *   le film reste publié (E10-23).
 * - Chaque envoi passe par sa route, sa garde et son limiteur ; un refus
 *   d'état revient en erreur traduite sous le formulaire, jamais en 403.
 * - Boîte de dialogue au focus piégé (Radix), rendu à l'élément déclencheur
 *   à la fermeture (`onReturnFocus`). La fermeture générée par `DialogContent`, au nom
 *   anglais figé, est masquée ; la boîte compose la sienne, étiquetée
 *   `admin.a11y.close` (§ 13.4).
 */
export function FrameGestureDialog({
    gesture,
    movieId,
    limits,
    warning,
    onRetryWarning,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    const recrop = gesture?.kind === 'recrop';

    return (
        <Dialog
            open={gesture !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
                className={cn(
                    'max-h-[90dvh] overflow-y-auto [&>button:last-child]:hidden',
                    recrop ? 'sm:max-w-lg lg:max-w-6xl' : 'sm:max-w-lg',
                )}
            >
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('admin.a11y.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden />
                    </Button>
                </DialogClose>

                {gesture !== null && (
                    <GestureBody
                        key={`${gesture.kind}-${gesture.target.frame.id}-${gesture.openedAtMs}`}
                        gesture={gesture}
                        movieId={movieId}
                        limits={limits}
                        warning={warning}
                        onRetryWarning={onRetryWarning}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type BodyProps = {
    gesture: FrameGesture;
    movieId: number;
    limits: AdminFrameLimits;
    warning: CoverageWarning;
    onRetryWarning: () => void;
    onDone: () => void;
};

function GestureBody(props: BodyProps) {
    switch (props.gesture.kind) {
        case 'recrop':
            return <RecropForm {...props} />;
        case 'level':
            return <LevelForm {...props} />;
        case 'unpublish':
            return <UnpublishForm {...props} />;
        case 'suspend':
        case 'unsuspend':
            return <SuspensionForm {...props} />;
    }
}

/**
 * L'avertissement de couverture, et son chargement : seulement pour une
 * image en jeu, la seule que ces gestes font sortir du jeu. Partagé avec la
 * confirmation de dépublication de la passe de revue (§ 7.5, § 8.4).
 */
export function CoverageNotice({
    inPlay,
    warning,
    onRetry,
}: {
    inPlay: boolean;
    warning: CoverageWarning;
    onRetry: () => void;
}) {
    const { t } = useTranslations();

    if (!inPlay) {
        return null;
    }

    if (warning.status === 'loading') {
        return (
            <p
                role="status"
                aria-busy="true"
                className="text-sm text-muted-foreground"
            >
                {t('admin.bank.gesture.coverage_loading')}
            </p>
        );
    }

    if (warning.status === 'failed') {
        return (
            <AdminErrorState
                title={t('admin.bank.gesture.coverage_failed')}
                retryLabel={t('admin.bank.retry')}
                onRetry={onRetry}
            />
        );
    }

    if (warning.preview === null) {
        return null;
    }

    return (
        <Alert variant="destructive">
            <TriangleAlertIcon aria-hidden />
            <AlertTitle>
                {warning.preview.playable_up_to === null
                    ? t('admin.frame.unpublish.coverage_warning_unplayable')
                    : t('admin.frame.unpublish.coverage_warning', {
                          max: warning.preview.playable_up_to,
                      })}
            </AlertTitle>
        </Alert>
    );
}

/** Vrai tant que l'avertissement dû n'est pas sous les yeux du curateur. */
export function isWarningPending(
    inPlay: boolean,
    warning: CoverageWarning,
): boolean {
    return inPlay && warning.status !== 'ready';
}

/** Annuler, et l'envoi : inactif, jamais retiré, tant qu'il est bloqué. */
function GestureFooter({
    submitLabel,
    blocked,
    processing,
    className,
}: {
    submitLabel: string;
    blocked: boolean;
    processing: boolean;
    className?: string;
}) {
    const { t } = useTranslations();

    return (
        <DialogFooter className="gap-2">
            <DialogClose asChild>
                <Button type="button" variant="outline" className="min-h-11">
                    {t('admin.bank.cancel')}
                </Button>
            </DialogClose>
            <Button
                type="submit"
                aria-disabled={blocked || processing ? true : undefined}
                aria-busy={processing || undefined}
                onClick={(event) => {
                    if (blocked || processing) {
                        event.preventDefault();
                    }
                }}
                className={cn(
                    'min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
                    className,
                )}
            >
                {submitLabel}
            </Button>
        </DialogFooter>
    );
}

/**
 * Re-recadrer une image, en place (§ 5.7) : le recadreur rouvre la source de
 * recadrage (`master_url`) sur le cadre actuel de l'image. Le temps de
 * recadrage court de l'ouverture de la boîte ; le serveur ne l'écrit que
 * s'il n'est pas déjà posé.
 */
function RecropForm({
    gesture,
    movieId,
    limits,
    warning,
    onRetryWarning,
    onDone,
}: BodyProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const { frame } = gesture.target;
    const inPlay = frame.curation_state === 'in_play';

    const [attempt, setAttempt] = useState(0);
    const master = useMasterImage(frame.master_url, attempt);
    const [edited, setEdited] = useState<CropState | null>(null);

    const opened =
        master.status === 'ready'
            ? openCrop(
                  master.masterHeight,
                  limits,
                  gesture.openedAtMs,
                  frame.crop,
              )
            : null;
    const state = edited ?? opened;
    const violation = state === null ? null : cropStateViolation(state);

    return (
        <Form
            {...FrameCropController.update.form({
                movie: movieId,
                frame: frame.id,
            })}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                only: BANK_WRITE_PROPS,
            }}
            transform={(data) => ({
                ...data,
                crop_seconds:
                    state === null
                        ? null
                        : cropSecondsAt(state, performance.now()),
            })}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {t('admin.bank.gesture.recrop.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.bank.gesture.recrop.description')}
                        </DialogDescription>
                    </DialogHeader>

                    {inPlay && (
                        <Alert>
                            <AlertDescription>
                                {t('admin.bank.gesture.recrop.published')}
                            </AlertDescription>
                        </Alert>
                    )}

                    <CoverageNotice
                        inPlay={inPlay}
                        warning={warning}
                        onRetry={onRetryWarning}
                    />

                    <p className="text-sm text-muted-foreground lg:hidden">
                        {t('admin.bank.desktop_required')}
                    </p>

                    <div className="hidden flex-col gap-3 lg:flex">
                        {master.status === 'loading' && (
                            <div role="status" aria-busy="true">
                                <Skeleton className="aspect-frame w-full" />
                                <span className="sr-only">
                                    {t(
                                        'admin.bank.gesture.recrop.master_loading',
                                    )}
                                </span>
                            </div>
                        )}

                        {(master.status === 'failed' ||
                            (master.status === 'ready' && state === null)) && (
                            <AdminErrorState
                                title={t(
                                    'admin.bank.gesture.recrop.master_failed',
                                )}
                                retryLabel={t('admin.bank.retry')}
                                onRetry={() => setAttempt((value) => value + 1)}
                            />
                        )}

                        {master.status === 'ready' && state !== null && (
                            <>
                                <FrameCropper
                                    imageUrl={master.url}
                                    state={state}
                                    onCommand={(command) =>
                                        setEdited(
                                            applyCropCommand(state, command),
                                        )
                                    }
                                    disabled={processing}
                                />
                                <input
                                    type="hidden"
                                    name="crop_x"
                                    value={state.crop.x}
                                />
                                <input
                                    type="hidden"
                                    name="crop_y"
                                    value={state.crop.y}
                                />
                                <input
                                    type="hidden"
                                    name="crop_width"
                                    value={state.crop.width}
                                />
                                <input
                                    type="hidden"
                                    name="crop_height"
                                    value={state.crop.height}
                                />
                            </>
                        )}

                        <AdminInputError
                            message={
                                errors.frame ??
                                errors.crop ??
                                errors.crop_x ??
                                errors.crop_y ??
                                errors.crop_width ??
                                errors.crop_height
                            }
                        />
                    </div>

                    {inPlay && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={reasonId}>
                                {t('admin.bank.gesture.recrop.reason')}
                            </Label>
                            <Textarea
                                id={reasonId}
                                name="reason"
                                defaultValue={t(
                                    'admin.frame.recrop.default_reason',
                                )}
                                aria-invalid={errors.reason ? true : undefined}
                            />
                            <AdminInputError message={errors.reason} />
                        </div>
                    )}

                    <GestureFooter
                        submitLabel={t('admin.bank.gesture.recrop.submit')}
                        blocked={
                            state === null ||
                            violation !== null ||
                            isWarningPending(inPlay, warning)
                        }
                        processing={processing}
                        className="hidden lg:inline-flex"
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Changer le niveau d'une image (§ 5.7). Le niveau courant est présélectionné
 * — c'est un fait, pas un défaut — et l'envoi reste inactif tant qu'il n'a
 * pas changé.
 */
function LevelForm({
    gesture,
    movieId,
    warning,
    onRetryWarning,
    onDone,
}: BodyProps) {
    const { t } = useTranslations();
    const { frame } = gesture.target;
    const inPlay = frame.curation_state === 'in_play';
    const [level, setLevel] = useState<FrameLevel>(frame.frame_level);
    const unchanged = level === frame.frame_level;

    return (
        <Form
            {...FrameLevelController.update.form({
                movie: movieId,
                frame: frame.id,
            })}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                only: BANK_WRITE_PROPS,
            }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {t('admin.bank.gesture.level.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.bank.gesture.level.description')}
                        </DialogDescription>
                    </DialogHeader>

                    {inPlay && (
                        <Alert>
                            <AlertDescription>
                                {t('admin.bank.gesture.level.published')}
                            </AlertDescription>
                        </Alert>
                    )}

                    <CoverageNotice
                        inPlay={inPlay}
                        warning={warning}
                        onRetry={onRetryWarning}
                    />

                    <LevelPicker
                        value={level}
                        onValueChange={setLevel}
                        error={errors.frame_level}
                        disabled={processing}
                    />

                    {unchanged && (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.bank.gesture.level.unchanged')}
                        </p>
                    )}

                    <AdminInputError message={errors.frame} />

                    <GestureFooter
                        submitLabel={t('admin.bank.gesture.level.submit')}
                        blocked={unchanged || isWarningPending(inPlay, warning)}
                        processing={processing}
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Dépublier une image en jeu, ou écarter une image jamais publiée (§ 8.4) —
 * motif facultatif. Une image écartée ne revient jamais en revue ; une image
 * dépubliée y revient, et seule une revue la remet en jeu.
 */
function UnpublishForm({
    gesture,
    movieId,
    warning,
    onRetryWarning,
    onDone,
}: BodyProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const { frame } = gesture.target;
    const inPlay = frame.curation_state === 'in_play';
    const setAside = unpublishKind(frame) === 'set_aside';

    return (
        <Form
            {...FrameUnpublishController.store.form({
                movie: movieId,
                frame: frame.id,
            })}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                only: BANK_WRITE_PROPS,
            }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {setAside
                                ? t('admin.bank.gesture.set_aside.title')
                                : t('admin.bank.gesture.unpublish.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {setAside
                                ? t('admin.bank.gesture.set_aside.description')
                                : t('admin.bank.gesture.unpublish.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <CoverageNotice
                        inPlay={inPlay}
                        warning={warning}
                        onRetry={onRetryWarning}
                    />

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={reasonId}>
                            {t('admin.bank.gesture.reason_optional')}
                        </Label>
                        <Textarea
                            id={reasonId}
                            name="reason"
                            aria-invalid={errors.reason ? true : undefined}
                        />
                        <AdminInputError message={errors.reason} />
                    </div>

                    <AdminInputError message={errors.frame} />

                    <GestureFooter
                        submitLabel={
                            setAside
                                ? t('admin.bank.gesture.set_aside.submit')
                                : t('admin.bank.gesture.unpublish.submit')
                        }
                        blocked={isWarningPending(inPlay, warning)}
                        processing={processing}
                    />
                </>
            )}
        </Form>
    );
}

/**
 * Suspendre une image publiée, ou lever sa suspension (spec 20 § 11.2) :
 * administrateur seul, motif facultatif. Suspendre une image en jeu peut
 * casser la couverture 1-3-5 d'un film publié : l'avertissement précède
 * l'envoi, comme pour une dépublication.
 */
function SuspensionForm({
    gesture,
    movieId,
    warning,
    onRetryWarning,
    onDone,
}: BodyProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const { frame } = gesture.target;
    const suspend = gesture.kind === 'suspend';
    const inPlay = suspend && frame.curation_state === 'in_play';
    const parameters = { movie: movieId, frame: frame.id };

    return (
        <Form
            {...(suspend
                ? FrameSuspendController.store.form(parameters)
                : FrameUnsuspendController.store.form(parameters))}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                only: BANK_WRITE_PROPS,
            }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {suspend
                                ? t('admin.frame.suspend.title')
                                : t('admin.frame.unsuspend.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {suspend
                                ? t('admin.frame.suspend.description')
                                : t('admin.frame.unsuspend.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <CoverageNotice
                        inPlay={inPlay}
                        warning={warning}
                        onRetry={onRetryWarning}
                    />

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={reasonId}>
                            {t('admin.bank.gesture.reason_optional')}
                        </Label>
                        <Textarea
                            id={reasonId}
                            name="reason"
                            aria-invalid={errors.reason ? true : undefined}
                        />
                        <AdminInputError message={errors.reason} />
                    </div>

                    <AdminInputError message={errors.frame} />

                    <GestureFooter
                        submitLabel={
                            suspend
                                ? t('admin.frame.suspend.submit')
                                : t('admin.frame.unsuspend.submit')
                        }
                        blocked={isWarningPending(inPlay, warning)}
                        processing={processing}
                    />
                </>
            )}
        </Form>
    );
}
