import type { HttpExceptionResponse } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useId } from 'react';
import ReplayController from '@/actions/App/Http/Controllers/Room/ReplayController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

type ReplayButtonProps = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /** Le siège est l'hôte : lui seul rejoue, les autres attendent. */
    isHost: boolean;
    /**
     * Geste désactivé : onglet supplanté, connexion perdue ou siège sorti
     * (spec 50 § 8.1, état « déconnexion »). Le serveur relit tout sous
     * verrou de toute façon.
     */
    disabled: boolean;
    /**
     * Motifs, déjà traduits, qui rendent le geste impossible — le drainage
     * (`common.maintenance.launch_blocked`). Une aide d'affichage seulement :
     * le refus du serveur fait seul autorité.
     */
    motives: string[];
    /** Intercepte le 409 `seat_superseded` de `seat.active` (§ 8.1). */
    onHttpException: (response: HttpExceptionResponse) => boolean | void;
    /**
     * Un refus, déjà traduit (`room.refusal.*`, maintenance, échec
     * technique) : la page l'annonce et le rend en `Alert` au rôle `note`
     * depuis son erreur `room` — jamais un toast.
     */
    onRefused: (message: string) => void;
};

/** Premier message d'erreur d'un geste refusé, déjà traduit. */
function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors).find((message) => message !== '') ?? null;
}

/**
 * « Rejouer » (spec 50 § 13) — sur le podium, dans la page unique du salon :
 * le geste de l'hôte qui ramène le salon au lobby, les réglages de nouveau
 * modifiables ; les autres joueurs lisent `room.replay.waiting`.
 *
 * Le serveur relit tout sous le verrou du salon — autorité d'hôte, partie
 * figée, drainage — et le lancement suivant repasse par la garde de vivier.
 * Le retour au lobby vient du magasin (`room.replayed`, ou la page relue
 * par la redirection), jamais d'une navigation vers une autre page.
 *
 * Composant de présentation : aucune lecture d'Echo ni d'horloge ; textes
 * du dictionnaire, jamais de couleur en dur. Chargement : bouton occupé
 * (`aria-busy`, indicateur), jamais un second envoi ; motif d'impossibilité
 * lié au bouton par `aria-describedby`.
 */
export function ReplayButton({
    roomCode,
    isHost,
    disabled,
    motives,
    onHttpException,
    onRefused,
}: ReplayButtonProps) {
    const { t } = useTranslations();
    const motiveId = useId();

    if (!isHost) {
        return (
            <p className="text-muted-foreground">{t('room.replay.waiting')}</p>
        );
    }

    return (
        <Form
            {...ReplayController.store.form({ room: roomCode })}
            options={{ preserveScroll: true, preserveState: true }}
            onHttpException={onHttpException}
            onError={(errors) => {
                const message = firstError(errors);

                if (message !== null) {
                    onRefused(message);
                }
            }}
            className="flex flex-col gap-2"
        >
            {({ processing }) => (
                <>
                    <Button
                        type="submit"
                        disabled={processing || disabled || motives.length > 0}
                        aria-busy={processing}
                        aria-describedby={
                            motives.length > 0 ? motiveId : undefined
                        }
                        className="min-h-11 w-full sm:w-auto sm:self-start"
                    >
                        {processing ? (
                            <Spinner
                                aria-hidden="true"
                                role="presentation"
                                aria-label={undefined}
                                className="motion-reduce:animate-none"
                            />
                        ) : (
                            <RotateCcw aria-hidden="true" />
                        )}
                        {t('room.replay.action')}
                    </Button>

                    {motives.length > 0 && (
                        <ul
                            id={motiveId}
                            className="flex flex-col gap-1 text-sm text-muted-foreground"
                        >
                            {motives.map((motive) => (
                                <li key={motive}>{motive}</li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </Form>
    );
}
