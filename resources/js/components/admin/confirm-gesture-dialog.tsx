import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useRef } from 'react';
import type { ReactNode, RefObject } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
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
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Le focus d'un geste confirmé : l'élément qui a ouvert la boîte, retenu à
 * l'ouverture, et la zone qui le reprend si un geste réussi l'a fait
 * disparaître — un titre retiré n'a plus de bouton « Retirer » (§ 13.4).
 */
export function useGestureFocus(): {
    zoneRef: RefObject<HTMLElement | null>;
    remember: () => void;
    restore: () => void;
} {
    const triggerRef = useRef<HTMLElement | null>(null);
    const zoneRef = useRef<HTMLElement | null>(null);

    function remember(): void {
        triggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
    }

    function restore(): void {
        const trigger = triggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            zoneRef.current?.focus();
        }
    }

    return { zoneRef, remember, restore };
}

type Props = {
    open: boolean;
    /** La route du geste, par Wayfinder : sa garde et son limiteur l'attendent. */
    form: RouteFormDefinition<'post'>;
    /** Textes DÉJÀ traduits : le composant possède la forme, l'écran la copie. */
    title: string;
    description: string;
    /** Un avertissement propre au geste, affiché au-dessus des champs. */
    notice?: string;
    submitLabel: string;
    /** Les champs dont le serveur peut renvoyer une erreur traduite. */
    errorFields: string[];
    /** Les champs du geste, s'il en a (libellé et note d'un regroupement). */
    children?: ReactNode;
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * La confirmation d'un geste de curation sans motif — retirer un titre ou un
 * alias, regrouper deux films, retirer un film de son groupe (spec 20 § 9.1,
 * § 9.2, § 9.4) : aucun n'est consigné au journal, `edited_by_id`,
 * `created_by_id` le tracent.
 *
 * - Chaque envoi passe par sa route, sa garde et son limiteur ; un refus —
 *   ligne TMDB qui ne se retire pas, film déjà groupé ailleurs — revient en
 *   erreur traduite sous le formulaire, jamais en 403.
 * - Une déconnexion laisse la boîte ouverte et la saisie telle quelle (toast
 *   de l'écran, `admin.common.offline`).
 * - Boîte de dialogue au focus piégé (Radix), rendu au déclencheur à la
 *   fermeture. La fermeture générée par `DialogContent`, au nom anglais
 *   figé, est masquée ; la boîte compose la sienne, étiquetée
 *   `admin.a11y.close` (§ 13.4).
 */
export function ConfirmGestureDialog({
    open,
    form,
    title,
    description,
    notice,
    submitLabel,
    errorFields,
    children,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
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

                <Form
                    {...form}
                    noValidate
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader className="pr-12">
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>

                            {notice !== undefined && (
                                <Alert>
                                    <AlertDescription>
                                        {notice}
                                    </AlertDescription>
                                </Alert>
                            )}

                            {children}

                            {errorFields.map((field) => (
                                <AdminInputError
                                    key={field}
                                    message={errors[field]}
                                />
                            ))}

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
                                    aria-disabled={processing || undefined}
                                    aria-busy={processing || undefined}
                                    onClick={(event) => {
                                        if (processing) {
                                            event.preventDefault();
                                        }
                                    }}
                                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                >
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
