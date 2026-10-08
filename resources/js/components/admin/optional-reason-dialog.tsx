import { Form } from '@inertiajs/react';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    open: boolean;
    /** La route du geste, par Wayfinder : sa garde et son limiteur l'attendent. */
    form: RouteFormDefinition<'post'>;
    /** Textes DÉJÀ traduits : le composant possède la forme, l'écran la copie. */
    title: string;
    description: string;
    /** Un avertissement propre au geste, affiché au-dessus du motif. */
    notice?: string;
    reasonLabel: string;
    submitLabel: string;
    /** Les champs, hors motif, dont le serveur peut renvoyer une erreur traduite. */
    errorFields?: string[];
    /** Les props que la redirection de l'envoi recharge ; toutes si absent. */
    only?: string[];
    /**
     * Ce que le geste montre en plus avant l'envoi (l'aperçu d'ambiguïté
     * d'une levée de suspension), et s'il bloque l'envoi tant qu'il n'est
     * pas prêt.
     */
    extra?: ReactNode;
    blocked?: boolean;
    /** Appelé après tout refus du serveur (un aperçu à redemander). */
    onError?: () => void;
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone des gestes s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * La confirmation d'un geste à motif **facultatif** consigné au journal
 * d'administration (contrat C14) — dépublier une image ou ignorer depuis la
 * file des signalements, suspendre un film ou une image, lever une
 * suspension (spec 20 § 11.2, § 11.6). `ReasonDialog` exige le sien.
 *
 * - Le motif peut rester vide ; le serveur le borne par la colonne et répond
 *   en erreur traduite sous le champ.
 * - Un refus d'état relu sous verrou revient sous le formulaire, jamais en
 *   403 pour un geste offert.
 * - Focus piégé (Radix), rendu au déclencheur ; la fermeture générée en
 *   anglais est masquée, la boîte compose la sienne (§ 13.4).
 */
export function OptionalReasonDialog({
    open,
    form,
    title,
    description,
    notice,
    reasonLabel,
    submitLabel,
    errorFields = ['frame', 'movie'],
    only,
    extra,
    blocked = false,
    onError,
    onClose,
    onReturnFocus,
}: Props) {
    const { t } = useTranslations();
    const reasonId = useId();
    const errorId = useId();

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
                    options={{
                        preserveScroll: true,
                        preserveState: true,
                        ...(only === undefined ? {} : { only }),
                    }}
                    onSuccess={onClose}
                    onError={() => onError?.()}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => {
                        const disabled = blocked || processing;

                        return (
                            <>
                                <DialogHeader className="pr-12">
                                    <DialogTitle>{title}</DialogTitle>
                                    <DialogDescription>
                                        {description}
                                    </DialogDescription>
                                </DialogHeader>

                                {notice !== undefined && (
                                    <Alert>
                                        <TriangleAlertIcon aria-hidden />
                                        <AlertDescription>
                                            {notice}
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {extra}

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={reasonId}>
                                        {reasonLabel}
                                    </Label>
                                    <Textarea
                                        id={reasonId}
                                        name="reason"
                                        aria-invalid={
                                            errors.reason ? true : undefined
                                        }
                                        aria-describedby={errorId}
                                    />
                                    <AdminInputError
                                        id={errorId}
                                        message={errors.reason}
                                    />
                                </div>

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
                                        aria-disabled={
                                            disabled ? true : undefined
                                        }
                                        aria-busy={processing || undefined}
                                        onClick={(event) => {
                                            if (disabled) {
                                                event.preventDefault();
                                            }
                                        }}
                                        className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                    >
                                        {submitLabel}
                                    </Button>
                                </DialogFooter>
                            </>
                        );
                    }}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
