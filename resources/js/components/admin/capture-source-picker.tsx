import { UploadIcon } from 'lucide-react';
import { useId, useRef } from 'react';
import type { ChangeEvent, RefObject } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * Où en est la préparation de la dernière capture choisie ou collée :
 * rien en cours, en préparation, ou refusée — `message` DÉJÀ traduit.
 */
export type CapturePickerStatus =
    | { kind: 'idle' }
    | { kind: 'preparing' }
    | { kind: 'failed'; message: string };

type Props = {
    status: CapturePickerStatus;
    /**
     * Un envoi est en cours : aucune capture ne remplace le cadre avant la
     * réponse. Le bouton reste focalisable (`aria-disabled`) : le focus y
     * revient après un envoi, avant même que l'envoi ne soit dit fini.
     */
    disabled: boolean;
    /** Le fichier choisi, tel quel : l'écran le prépare. */
    onPick: (file: File) => void;
    /** « Choisir une image », où l'écran rend le focus après un envoi. */
    buttonRef: RefObject<HTMLButtonElement | null>;
    className?: string;
};

/**
 * Le point d'entrée de la voie capture, dans la zone du recadreur (spec 20
 * § 5.4 et § 6.3, lot L20-33, D38 du 28/09) : choisir un fichier d'image, ou
 * le coller depuis le presse-papiers — le collage est écouté par l'écran, sur
 * tout le document, et cette zone n'en porte que l'indication.
 *
 * Le champ de fichier n'a **aucun `name`** et vit hors de tout formulaire :
 * la source brute, souvent plus lourde que ce que PHP accepte, n'est jamais
 * sérialisée. Seul le fichier que le navigateur a préparé — WebP, à la
 * largeur du master, sous le plafond d'entrée — part, injecté par l'écran à
 * l'envoi (R-46 : le serveur dérive toujours le jeu).
 *
 * Accessibilité : un bouton natif de `min-h-11`, décrit par l'explication,
 * l'indication de collage et l'éventuel refus ; la préparation est annoncée
 * par une région d'état (`aria-busy` sur la zone), un refus par une alerte.
 * Tokens seulement (règle 5).
 */
export function CaptureSourcePicker({
    status,
    disabled,
    onPick,
    buttonRef,
    className,
}: Props) {
    const { t } = useTranslations();
    const baseId = useId();
    const headingId = `${baseId}-heading`;
    const descriptionId = `${baseId}-description`;
    const pasteId = `${baseId}-paste`;
    const errorId = `${baseId}-error`;
    const inputRef = useRef<HTMLInputElement>(null);

    const preparing = status.kind === 'preparing';
    const failure = status.kind === 'failed' ? status.message : undefined;

    function handleChange(event: ChangeEvent<HTMLInputElement>): void {
        const input = event.currentTarget;
        const file = input.files?.item(0) ?? null;

        // Vidé aussitôt : choisir de nouveau le même fichier le rouvre.
        input.value = '';

        if (file !== null) {
            onPick(file);
        }
    }

    return (
        // `aria-busy` vit sur le seul bouton, jamais sur la section : posé
        // sur un ancêtre de la région vivante, il ferait taire son annonce
        // « Préparation… » chez certains lecteurs d'écran (NVDA + Firefox).
        <section
            aria-labelledby={headingId}
            className={cn(
                'flex flex-col gap-2 rounded-md border border-dashed border-border p-3',
                className,
            )}
        >
            <h3
                id={headingId}
                className="text-sm font-semibold text-foreground"
            >
                {t('admin.frame.capture.ui.heading')}
            </h3>
            <p id={descriptionId} className="text-sm text-muted-foreground">
                {t('admin.frame.capture.ui.description')}
            </p>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <Button
                    ref={buttonRef}
                    type="button"
                    variant="outline"
                    aria-describedby={
                        failure === undefined
                            ? `${descriptionId} ${pasteId}`
                            : `${descriptionId} ${pasteId} ${errorId}`
                    }
                    aria-disabled={disabled ? true : undefined}
                    aria-busy={preparing ? true : undefined}
                    onClick={() => {
                        if (!disabled) {
                            inputRef.current?.click();
                        }
                    }}
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    <UploadIcon aria-hidden />
                    {t('admin.frame.capture.ui.choose')}
                </Button>
                <span id={pasteId} className="text-sm text-muted-foreground">
                    {t('admin.frame.capture.ui.paste_hint')}
                </span>
                {/* Toujours rendue, vide au repos : une région vivante
                    masquée puis montrée n'est pas annoncée. */}
                <p
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    className="text-sm text-foreground"
                >
                    {preparing ? t('admin.frame.capture.ui.preparing') : null}
                </p>
            </div>

            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                hidden
                onChange={handleChange}
            />

            <AdminInputError id={errorId} message={failure} />
        </section>
    );
}
