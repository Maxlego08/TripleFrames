import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId } from 'react';
import FrameUnpublishController from '@/actions/App/Http/Controllers/Admin/FrameUnpublishController';
import { AdminInputError } from '@/components/admin/admin-input-error';
import {
    CoverageNotice,
    isWarningPending,
} from '@/components/admin/frame-gesture-dialog';
import type { CoverageWarning } from '@/components/admin/frame-gesture-dialog';
import {
    failedItemLabels,
    REVIEW_WRITE_PROPS,
} from '@/components/admin/review-panel';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { AdminReviewFrame } from '@/types/admin';

/** L'image à dépublier ou écarter depuis la passe de revue, et pourquoi. */
export type ReviewUnpublishTarget = {
    frame: AdminReviewFrame;
    /** Les points en défaut, qui pré-remplissent le motif. */
    failedSlugs: string[];
    /** Ouverte d'elle-même, juste après le rejet d'une image en jeu. */
    afterRejection: boolean;
    /** Instant d'ouverture : une boîte neuve à chaque ouverture. */
    openedAtMs: number;
};

type Props = {
    target: ReviewUnpublishTarget | null;
    warning: CoverageWarning;
    onRetryWarning: () => void;
    onClose: () => void;
    /** Rend le focus à l'élément déclencheur, ou à l'écran s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * Dépublier une image rejetée en jeu, ou écarter une image rejetée jamais
 * publiée, depuis la passe de revue (spec 20 § 7.3, § 7.5, § 8.4).
 *
 * - **Geste distinct et attribué** : il passe par la route de dépublication
 *   (`admin.catalog.frames.unpublish`), sa garde, son limiteur et sa ligne
 *   `frame.unpublished`. Après le rejet d'une image en jeu, la boîte s'ouvre
 *   d'elle-même ; la refermer laisse l'image en jeu, et « Rejetées »
 *   l'affiche jusqu'à décision.
 * - **Motif pré-rempli** par la liste des points en défaut, modifiable,
 *   facultatif (C14).
 * - **Avertissement de couverture avant tout envoi**, pour une image en
 *   jeu (§ 8.4) : l'envoi reste inactif tant qu'il n'est pas sous les yeux.
 * - Focus piégé (Radix), rendu à l'élément déclencheur ; la fermeture
 *   générée en anglais est masquée, la boîte compose la sienne (§ 13.4).
 */
export function ReviewUnpublishDialog({
    target,
    warning,
    onRetryWarning,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={target !== null}
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

                {target !== null && (
                    <UnpublishForm
                        key={`${target.frame.id}-${target.openedAtMs}`}
                        target={target}
                        warning={warning}
                        onRetryWarning={onRetryWarning}
                        onDone={onClose}
                    />
                )}

                {target === null && (
                    <DialogHeader className="sr-only">
                        <DialogTitle>
                            {t('admin.bank.gesture.unpublish.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('admin.bank.gesture.unpublish.description')}
                        </DialogDescription>
                    </DialogHeader>
                )}
            </DialogContent>
        </Dialog>
    );
}

function UnpublishForm({
    target,
    warning,
    onRetryWarning,
    onDone,
}: {
    target: ReviewUnpublishTarget;
    warning: CoverageWarning;
    onRetryWarning: () => void;
    onDone: () => void;
}) {
    const { t } = useTranslations();
    const reasonId = useId();
    const { frame } = target;
    const inPlay = frame.availability === 'published';
    const labels = failedItemLabels(frame.items, target.failedSlugs, t);

    return (
        <Form
            {...FrameUnpublishController.store.form({
                movie: frame.movie_id,
                frame: frame.id,
            })}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                only: REVIEW_WRITE_PROPS,
            }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {inPlay
                                ? t('admin.bank.gesture.unpublish.title')
                                : t('admin.bank.gesture.set_aside.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {inPlay
                                ? t('admin.bank.gesture.unpublish.description')
                                : t('admin.bank.gesture.set_aside.description')}
                        </DialogDescription>
                    </DialogHeader>

                    {target.afterRejection && (
                        <Alert>
                            <AlertDescription>
                                {t('admin.review.unpublish.rejected_notice')}
                            </AlertDescription>
                        </Alert>
                    )}

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
                            defaultValue={
                                labels.length === 0
                                    ? ''
                                    : t(
                                          'admin.review.unpublish.default_reason',
                                          {
                                              items: labels.join(
                                                  t(
                                                      'admin.common.list_separator',
                                                  ),
                                              ),
                                          },
                                      )
                            }
                            aria-invalid={errors.reason ? true : undefined}
                        />
                        <AdminInputError message={errors.reason} />
                    </div>

                    <AdminInputError message={errors.frame} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                            >
                                {t('admin.bank.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            aria-disabled={
                                isWarningPending(inPlay, warning) || processing
                                    ? true
                                    : undefined
                            }
                            aria-busy={processing || undefined}
                            onClick={(event) => {
                                if (
                                    isWarningPending(inPlay, warning) ||
                                    processing
                                ) {
                                    event.preventDefault();
                                }
                            }}
                            className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                        >
                            {inPlay
                                ? t('admin.bank.gesture.unpublish.submit')
                                : t('admin.bank.gesture.set_aside.submit')}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}
