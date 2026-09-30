import { Check, Copy, Share2 } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';

type ShareCodeProps = {
    /** Code du salon, affiché tel quel : jamais dans un titre (§ 6.5). */
    code: string;
    /**
     * Lien de partage ABSOLU, construit par la page depuis Wayfinder
     * (`show.url()` préfixé de `window.location.origin`), jamais par
     * concaténation d'un code (§ 6.5).
     */
    url: string;
};

/**
 * Code et lien de partage du salon (spec 50 § 6.5 et § 8.1) : tous les sièges
 * les voient, l'hôte comme les autres.
 *
 * - Le code s'affiche en grand (`room.lobby.code_label`) ; il se dicte, le
 *   lien se copie.
 * - « Copier le lien » (`room.lobby.copy_link`) écrit l'URL dans le
 *   presse-papiers ; le succès s'affiche (`room.lobby.link_copied`) et
 *   s'annonce par l'annonceur, jamais par un toast. Sans presse-papiers
 *   accessible (contexte non sécurisé, refus du navigateur), le champ du
 *   lien est sélectionné et reçoit le focus : la copie se fait à la main.
 * - « Partager » (`room.lobby.share`) n'est proposé que si
 *   `navigator.share` existe ; une feuille de partage fermée sans choix
 *   n'est pas une erreur.
 *
 * Le lien porte un code : la page reste `noindex`, et `Referrer-Policy` est
 * posée globalement (§ 6.5). Aucun préfixe de locale : le salon n'a pas de
 * langue, chaque joueur a la sienne.
 */
export function ShareCode({ code, url }: ShareCodeProps) {
    const { t } = useTranslations();
    const linkId = useId();
    const codeLabelId = useId();
    const linkInput = useRef<HTMLInputElement>(null);
    const [copied, setCopied] = useState(false);
    const canShare =
        typeof navigator !== 'undefined' &&
        typeof navigator.share === 'function';

    const selectLink = (): void => {
        linkInput.current?.focus();
        linkInput.current?.select();
    };

    const copy = async (): Promise<void> => {
        setCopied(false);

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            announce(t('room.lobby.link_copied'));
        } catch {
            selectLink();
        }
    };

    const share = async (): Promise<void> => {
        try {
            await navigator.share({ url });
        } catch {
            // Feuille fermée sans partage, ou partage refusé : rien à dire.
        }
    };

    return (
        <section
            aria-labelledby={codeLabelId}
            className="flex flex-col gap-3 rounded-md border border-border p-4"
        >
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2
                    id={codeLabelId}
                    className="text-sm font-medium text-muted-foreground"
                >
                    {t('room.lobby.code_label')}
                </h2>
                <p className="font-mono text-3xl font-semibold tracking-widest select-all">
                    {code}
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={linkId} className="text-muted-foreground">
                    {t('room.lobby.share_hint')}
                </Label>
                <Input
                    ref={linkInput}
                    id={linkId}
                    readOnly
                    value={url}
                    spellCheck={false}
                    onFocus={(event) => event.currentTarget.select()}
                    className="min-h-11 font-mono text-sm"
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => void copy()}
                >
                    <Copy aria-hidden="true" />
                    {t('room.lobby.copy_link')}
                </Button>

                {canShare && (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => void share()}
                    >
                        <Share2 aria-hidden="true" />
                        {t('room.lobby.share')}
                    </Button>
                )}

                {copied && (
                    <p className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                        <Check aria-hidden="true" className="size-4" />
                        {t('room.lobby.link_copied')}
                    </p>
                )}
            </div>
        </section>
    );
}
