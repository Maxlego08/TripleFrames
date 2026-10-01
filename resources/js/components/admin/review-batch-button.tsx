import { Form } from '@inertiajs/react';
import { ListChecksIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';
import MovieFramesReviewController from '@/actions/App/Http/Controllers/Admin/MovieFramesReviewController';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { useTranslations } from '@/hooks/use-translations';
import type { AdminReviewBatch } from '@/types/admin';

/** Un seul toast de refus à la fois, quel que soit le film. */
const BATCH_REFUSAL_TOAST_ID = 'admin-review-batch-refusal';

type Props = {
    movieId: number;
    movieTitle: string;
    batch: AdminReviewBatch;
    /**
     * Les props que la redirection de l'envoi recharge ; toutes si absent.
     * La file de revue nomme les siennes (`REVIEW_WRITE_PROPS`).
     */
    only?: string[];
    /**
     * Où rendre le focus quand un lot validé a fait disparaître le bouton
     * (plus rien à valider) : jamais au document.
     */
    fallbackFocusRef?: RefObject<HTMLElement | null>;
    size?: 'sm' | 'default';
};

/**
 * « Tout valider » — la validation en lot des images d'un film en attente de
 * revue (D42 du 30/09, spec 20 § 7.9), sur la fiche du film et en tête de
 * son groupe dans la file de revue.
 *
 * Le bouton n'existe que s'il y a un lot (`batch`), et ouvre TOUJOURS une
 * confirmation qui dit combien d'images seront validées, que la grille
 * d'exclusion est enregistrée « rien à signaler » pour chacune, ce qui reste
 * en revue individuelle, et que le film n'est jamais publié automatiquement.
 *
 * L'envoi rend la liste `{ id, hash }` affichée, telle quelle : le serveur
 * refuse tout le lot — erreur traduite sous `frames`, rien d'écrit — si elle
 * ne correspond plus aux images en attente ou si une empreinte a changé.
 *
 * Le refus passe TOUJOURS par un toast et ferme la confirmation : le
 * rechargement qui suit un refus peut avoir vidé le lot (un autre curateur
 * vient de valider les mêmes images, `admin.review.batch.empty`) et démonté
 * ce bouton avec sa boîte — une erreur rendue dans la boîte disparaîtrait
 * sans avoir été lue. Si le lot existe encore, le bouton affiche son nouveau
 * compte, et le curateur rouvre une confirmation à jour.
 */
export function ReviewBatchButton({
    movieId,
    movieTitle,
    batch,
    only,
    fallbackFocusRef,
    size = 'default',
}: Props) {
    const { t, tChoice } = useTranslations();
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const count = batch.frames.length;

    return (
        <>
            <Button
                ref={triggerRef}
                type="button"
                size={size}
                onClick={() => setOpen(true)}
                aria-label={t('admin.review.batch.action_label', {
                    count,
                    title: movieTitle,
                })}
                className="min-h-11"
            >
                <ListChecksIcon aria-hidden />
                {t('admin.review.batch.action', { count })}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();

                        const trigger = triggerRef.current;

                        if (trigger !== null && trigger.isConnected) {
                            trigger.focus();
                        } else {
                            fallbackFocusRef?.current?.focus();
                        }
                    }}
                    className="max-h-[90dvh] overflow-y-auto sm:max-w-lg [&>button:last-child]:hidden"
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

                    <Form
                        {...MovieFramesReviewController.store.form(movieId)}
                        noValidate
                        options={{
                            preserveScroll: true,
                            preserveState: true,
                            ...(only === undefined ? {} : { only }),
                        }}
                        onSuccess={() => setOpen(false)}
                        onError={(errors) => {
                            const [message, ...details] = [
                                ...new Set(Object.values(errors)),
                            ];

                            if (message !== undefined) {
                                toast.error(message, {
                                    id: BATCH_REFUSAL_TOAST_ID,
                                    description:
                                        details.length > 0
                                            ? details.join(' ')
                                            : undefined,
                                });
                            }

                            setOpen(false);
                        }}
                        className="flex flex-col gap-4"
                    >
                        {({ processing }) => (
                            <>
                                <DialogHeader className="pr-12">
                                    <DialogTitle>
                                        {t('admin.review.batch.title')}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {tChoice(
                                            'admin.review.batch.description',
                                            count,
                                            { count },
                                        )}
                                    </DialogDescription>
                                </DialogHeader>

                                {batch.frames.map((frame, index) => (
                                    <span key={frame.id} hidden>
                                        <input
                                            type="hidden"
                                            name={`frames[${index}][id]`}
                                            value={frame.id}
                                        />
                                        <input
                                            type="hidden"
                                            name={`frames[${index}][hash]`}
                                            value={frame.hash}
                                        />
                                    </span>
                                ))}

                                <Alert>
                                    <AlertDescription className="space-y-2">
                                        <p>
                                            {t(
                                                'admin.review.batch.grid_notice',
                                                {
                                                    version: batch.grid_version,
                                                },
                                            )}
                                        </p>
                                        <p>
                                            {t(
                                                'admin.review.batch.excluded_notice',
                                            )}
                                        </p>
                                        <p>
                                            {t(
                                                'admin.review.batch.publish_notice',
                                            )}
                                        </p>
                                    </AlertDescription>
                                </Alert>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                        >
                                            {t('admin.common.cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        aria-disabled={
                                            processing ? true : undefined
                                        }
                                        aria-busy={processing || undefined}
                                        onClick={(event) => {
                                            if (processing) {
                                                event.preventDefault();
                                            }
                                        }}
                                        className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                    >
                                        {t('admin.review.batch.submit')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
