import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
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
    /** Motif pré-rempli, modifiable (« Aucune image exploitable »). */
    defaultReason?: string;
    submitLabel: string;
    /**
     * Les props que la redirection de l'envoi recharge ; toutes si absent.
     * L'éditeur de la banque nomme les siennes (`BANK_WRITE_PROPS`).
     */
    only?: string[];
    onClose: () => void;
    /**
     * Rend le focus à l'élément déclencheur, ou à la zone des gestes s'il a
     * disparu — un film écarté n'a plus de bouton « Écarter ».
     */
    onReturnFocus: () => void;
};

/**
 * La confirmation d'un geste à **motif obligatoire** consigné au journal
 * d'administration (contrat C14) : dépublier un film, l'écarter, cocher
 * « contenu vérifié » (spec 20 § 4.2, § 4.4, § 8.3).
 *
 * - Le motif est un champ requis : l'envoi reste inactif — jamais retiré —
 *   tant qu'il est vide, et un texte dit pourquoi. Le serveur le revalide
 *   (requis, borné par la colonne) et répond en erreur traduite sous le champ.
 * - Un refus d'état relu sous verrou revient sous le formulaire ; une
 *   déconnexion laisse la saisie telle quelle (toast de l'écran).
 * - Boîte de dialogue au focus piégé (Radix), rendu au déclencheur à la
 *   fermeture (`onReturnFocus`). La fermeture générée par `DialogContent`, au
 *   nom anglais figé, est masquée ; la boîte compose la sienne, étiquetée
 *   `admin.a11y.close` (§ 13.4).
 */
export function ReasonDialog({
    open,
    form,
    title,
    description,
    notice,
    reasonLabel,
    defaultReason,
    submitLabel,
    only,
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

                <ReasonForm
                    form={form}
                    title={title}
                    description={description}
                    notice={notice}
                    reasonLabel={reasonLabel}
                    defaultReason={defaultReason}
                    submitLabel={submitLabel}
                    only={only}
                    onDone={onClose}
                />
            </DialogContent>
        </Dialog>
    );
}

type FormProps = Pick<
    Props,
    | 'form'
    | 'title'
    | 'description'
    | 'notice'
    | 'reasonLabel'
    | 'defaultReason'
    | 'submitLabel'
    | 'only'
> & {
    onDone: () => void;
};

/**
 * Le formulaire, remonté à chaque ouverture — `DialogContent` ne rend ses
 * enfants que boîte ouverte : un motif abandonné ne revient jamais.
 */
function ReasonForm({
    form,
    title,
    description,
    notice,
    reasonLabel,
    defaultReason,
    submitLabel,
    only,
    onDone,
}: FormProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const hintId = useId();
    const errorId = useId();
    const [reason, setReason] = useState(defaultReason ?? '');
    const empty = reason.trim() === '';

    return (
        <Form
            {...form}
            noValidate
            options={{
                preserveScroll: true,
                preserveState: true,
                ...(only === undefined ? {} : { only }),
            }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => {
                const blocked = empty || processing;

                return (
                    <>
                        <DialogHeader className="pr-12">
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </DialogHeader>

                        {notice !== undefined && (
                            <Alert>
                                <AlertDescription>{notice}</AlertDescription>
                            </Alert>
                        )}

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={reasonId}>{reasonLabel}</Label>
                            <Textarea
                                id={reasonId}
                                name="reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                aria-required="true"
                                aria-invalid={errors.reason ? true : undefined}
                                aria-describedby={`${hintId} ${errorId}`}
                            />
                            <p
                                id={hintId}
                                className="text-sm text-muted-foreground"
                            >
                                {t('admin.common.reason_required')}
                            </p>
                            <AdminInputError
                                id={errorId}
                                message={errors.reason}
                            />
                        </div>

                        <AdminInputError message={errors.movie} />

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
                                aria-disabled={blocked ? true : undefined}
                                aria-busy={processing || undefined}
                                onClick={(event) => {
                                    if (blocked) {
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
    );
}
