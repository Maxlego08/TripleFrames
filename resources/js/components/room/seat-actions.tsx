import type { HttpExceptionResponse } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { Crown, Flag, LogOut, UserX, XIcon } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import AvatarReportController from '@/actions/App/Http/Controllers/Room/AvatarReportController';
import HostTransferController from '@/actions/App/Http/Controllers/Room/HostTransferController';
import KickController from '@/actions/App/Http/Controllers/Room/KickController';
import LeaveRoomController from '@/actions/App/Http/Controllers/Room/LeaveRoomController';
import NicknameReportController from '@/actions/App/Http/Controllers/Room/NicknameReportController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import type { SeatView } from '@/types/game-wire';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Ce que la page confie aux gestes de salon : l'état d'écriture et les
 * rappels de chaque requête du lobby (spec 50 § 8.1).
 */
export type RoomGestureContext = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /**
     * Gestes désactivés : onglet supplanté ou siège sorti pour tous, et
     * connexion perdue pour les gestes d'hôte seuls (§ 8.1, état
     * « déconnexion ») — le départ reste possible. Le serveur relit tout
     * sous verrou de toute façon.
     */
    disabled: boolean;
    /** Intercepte le 409 `seat_superseded` de `seat.active` (§ 8.1). */
    onHttpException: (response: HttpExceptionResponse) => boolean | void;
    /**
     * Un refus, déjà traduit : la page l'annonce dans l'unique région
     * vivante ; elle le rend aussi, en `Alert` au rôle `note`, depuis ses
     * erreurs de page (`room`, `publicId`).
     */
    onRefused: (message: string) => void;
};

type SeatActionsProps = RoomGestureContext & {
    /** Le siège visé — jamais celui de l'hôte lui-même. */
    seat: SeatView;
    /** Pseudo affiché du siège (ou ses initiales), pour les confirmations. */
    nickname: string;
    /** `id` de l'élément qui porte le pseudo : décrit chaque bouton. */
    describedBy: string;
    /**
     * Rend le focus à un point stable après un geste réussi : le bouton qui
     * l'a déclenché peut disparaître (siège retiré, rôle confié).
     */
    onDone: () => void;
};

type GestureDialogProps = Pick<
    RoomGestureContext,
    'disabled' | 'onHttpException' | 'onRefused'
> & {
    /** La route du geste, par Wayfinder : `seat.active` et `game-write`. */
    form: RouteFormDefinition<'post'>;
    /** Textes DÉJÀ traduits : libellé du déclencheur, titre, question. */
    label: string;
    title: string;
    description: string;
    icon: LucideIcon;
    /** Le geste est irréversible (expulsion, départ) : bouton destructif. */
    destructive?: boolean;
    describedBy?: string;
    /** Champs cachés du corps posté (le `publicId` d'un transfert). */
    fields?: ReactNode;
    onDone?: () => void;
};

/** Premier message d'erreur d'un geste refusé, déjà traduit. */
function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors).find((message) => message !== '') ?? null;
}

/**
 * Un geste confirmé côté client (spec 50 § 11.3, § 11.4) : un déclencheur,
 * puis une boîte de dialogue au focus piégé (Radix) qui pose la question et
 * envoie le formulaire Wayfinder — `<Form>` d'Inertia, sous l'en-tête
 * `X-Seat-Token` que `useGameState` attache à toute requête.
 *
 * - Focus initial sur « Annuler », premier élément focalisable : un geste
 *   irréversible ne se confirme pas par un Entrée réflexe. Échap ferme.
 * - Chargement : bouton d'envoi occupé (`aria-busy`, indicateur), jamais un
 *   second envoi.
 * - La boîte se ferme à la fin de la requête ; un refus est annoncé, et la
 *   page le rend en `Alert` au rôle `note` (jamais un toast : aucun
 *   `Toaster` sous `GameLayout`).
 * - Fermée sans geste, le focus revient au déclencheur ; après un geste
 *   réussi, à un point stable ({@see GestureDialogProps.onDone}). La
 *   diffusion du geste arrive souvent AVANT sa réponse HTTP (`seat.updated`,
 *   `host.changed`) et retire la ligne ou les gestes d'hôte : la boîte est
 *   alors démontée ouverte, et Radix n'aurait plus de déclencheur où rendre
 *   le focus. Un démontage pendant que la boîte ou sa requête est en cours
 *   rend donc le focus au même point stable.
 * - La fermeture générée par `DialogContent`, au nom anglais figé, est
 *   masquée : la boîte compose la sienne, étiquetée `common.action.close`.
 */
function GestureDialog({
    form,
    label,
    title,
    description,
    icon: Icon,
    destructive = false,
    describedBy,
    fields,
    disabled,
    onHttpException,
    onRefused,
    onDone,
}: GestureDialogProps) {
    const { t } = useTranslations();
    const [open, setOpen] = useState(false);
    // Vrai de l'ouverture jusqu'au rendu du focus : la boîte, ou la requête
    // qu'elle a envoyée, est en cours.
    const engaged = useRef(false);
    const succeeded = useRef(false);
    const restoreFocus = useEffectEvent((): void => {
        onDone?.();
    });

    useEffect(
        () => () => {
            if (engaged.current) {
                engaged.current = false;
                restoreFocus();
            }
        },
        [],
    );

    const changeOpen = (next: boolean): void => {
        engaged.current = next;
        setOpen(next);
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-describedby={describedBy}
                    className="min-h-11"
                >
                    <Icon aria-hidden="true" />
                    {label}
                </Button>
            </DialogTrigger>

            <DialogContent
                onCloseAutoFocus={(event) => {
                    engaged.current = false;

                    if (succeeded.current && onDone !== undefined) {
                        event.preventDefault();
                        succeeded.current = false;
                        onDone();
                    }
                }}
                className="sm:max-w-md [&>button:last-child]:hidden"
            >
                <Form
                    {...form}
                    options={{ preserveScroll: true, preserveState: true }}
                    onHttpException={onHttpException}
                    onSuccess={() => {
                        succeeded.current = true;
                    }}
                    onError={(errors) => {
                        const message = firstError(errors);

                        if (message !== null) {
                            onRefused(message);
                        }
                    }}
                    onFinish={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing }) => (
                        <>
                            {fields}

                            <DialogHeader className="pr-12">
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        {t('common.action.cancel')}
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={processing || disabled}
                                    aria-busy={processing}
                                    className="min-h-11"
                                >
                                    {processing ? (
                                        <Spinner
                                            aria-hidden="true"
                                            role="presentation"
                                            aria-label={undefined}
                                            className="motion-reduce:animate-none"
                                        />
                                    ) : (
                                        <Icon aria-hidden="true" />
                                    )}
                                    {label}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>

                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('common.action.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden="true" />
                    </Button>
                </DialogClose>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Les gestes de l'hôte sur un autre siège (spec 50 § 8.1, § 11.3, § 11.4) :
 * « Nommer hôte » pour un siège connecté et non retiré, « Retirer du
 * salon » pour tout siège non retiré — un siège parti peut encore revenir
 * par le lien, et son retrait le lui interdit jusqu'à l'archivage.
 *
 * Composant de présentation : aucune lecture d'Echo ni d'horloge ; les
 * textes viennent du dictionnaire, les sièges du magasin par la page. Chaque
 * bouton est décrit par le pseudo du siège (`aria-describedby`), pour qu'un
 * lecteur d'écran sache qui il vise. Le serveur relit tout sous verrou :
 * autorité d'hôte, cible dans ce salon, cible connectée pour un transfert.
 */
export function SeatActions({
    roomCode,
    seat,
    nickname,
    describedBy,
    disabled,
    onHttpException,
    onRefused,
    onDone,
}: SeatActionsProps) {
    const { t } = useTranslations();

    if (seat.kicked) {
        return null;
    }

    const shared = {
        disabled,
        onHttpException,
        onRefused,
        onDone,
        describedBy,
    };

    return (
        <div className="flex flex-wrap justify-end gap-2">
            {seat.connection === 'connected' && (
                <GestureDialog
                    {...shared}
                    form={HostTransferController.store.form({ room: roomCode })}
                    label={t('room.lobby.transfer')}
                    title={t('room.lobby.transfer')}
                    description={t('room.lobby.transfer_confirm', {
                        nickname,
                    })}
                    icon={Crown}
                    fields={
                        <input
                            type="hidden"
                            name="publicId"
                            value={seat.publicId}
                        />
                    }
                />
            )}

            <GestureDialog
                {...shared}
                form={KickController.store.form({
                    room: roomCode,
                    target: seat.publicId,
                })}
                label={t('room.lobby.kick')}
                title={t('room.lobby.kick')}
                description={t('room.lobby.kick_confirm', { nickname })}
                icon={UserX}
                destructive
            />
        </div>
    );
}

/**
 * « Signaler l'avatar » (spec 40 § 11.6, D49 du 01/10) : un geste de TOUT
 * siège sur un autre siège qui affiche une image téléversée — jamais un
 * prédéfini. Confirmé ; l'envoi est annoncé dans l'unique région vivante
 * (`common.avatar.report.sent`), le même message que le seuil soit atteint
 * ou non. Le bouton disparaît après l'envoi : un second signalement du même
 * siège ne compterait pas.
 */
export function ReportAvatarAction({
    roomCode,
    seat,
    nickname,
    describedBy,
    disabled,
    onHttpException,
    onRefused,
    onDone,
}: SeatActionsProps) {
    const { t } = useTranslations();
    const [sent, setSent] = useState(false);

    if (sent || seat.kicked || seat.avatar.kind !== 'upload') {
        return null;
    }

    return (
        <GestureDialog
            form={AvatarReportController.store.form({
                room: roomCode,
                target: seat.publicId,
            })}
            label={t('common.avatar.report.action')}
            title={t('common.avatar.report.confirm_title', { nickname })}
            description={t('common.avatar.report.confirm_body')}
            icon={Flag}
            describedBy={describedBy}
            disabled={disabled}
            onHttpException={onHttpException}
            onRefused={onRefused}
            onDone={() => {
                announce(t('common.avatar.report.sent'));
                setSent(true);
                onDone();
            }}
        />
    );
}

/**
 * « Signaler le pseudo » (spec 40 § 13.3, D66 du 07/10) : un geste de TOUT
 * siège sur un autre siège dont le pseudo est affiché — ni masqué, ni
 * effacé. Confirmé ; l'envoi est annoncé dans l'unique région vivante
 * (`common.player.report.sent`), le même message que le seuil soit atteint
 * ou non. Le bouton disparaît après l'envoi : un second signalement du même
 * siège ne compterait pas. Au seuil, la diffusion `seat.updated` masque le
 * pseudo et retire le bouton pour tous.
 */
export function ReportNicknameAction({
    roomCode,
    seat,
    nickname,
    describedBy,
    disabled,
    onHttpException,
    onRefused,
    onDone,
}: SeatActionsProps) {
    const { t } = useTranslations();
    const [sent, setSent] = useState(false);

    if (sent || seat.kicked || seat.masked || seat.nickname === null) {
        return null;
    }

    return (
        <GestureDialog
            form={NicknameReportController.store.form({
                room: roomCode,
                target: seat.publicId,
            })}
            label={t('common.player.report.action')}
            title={t('common.player.report.confirm_title', { nickname })}
            description={t('common.player.report.confirm_body')}
            icon={Flag}
            describedBy={describedBy}
            disabled={disabled}
            onHttpException={onHttpException}
            onRefused={onRefused}
            onDone={() => {
                announce(t('common.player.report.sent'));
                setSent(true);
                onDone();
            }}
        />
    );
}

/**
 * « Quitter le salon » (spec 50 § 11.4) : un geste de tout joueur, hôte
 * compris, confirmé (`room.lobby.leave_confirm`). Le serveur répond par
 * l'accueil ; le rôle d'hôte passe au plus ancien connecté. Le partant peut
 * revenir par le lien.
 */
export function LeaveRoomAction({
    roomCode,
    disabled,
    onHttpException,
    onRefused,
    icon = LogOut,
}: RoomGestureContext & { icon?: LucideIcon }) {
    const { t } = useTranslations();

    return (
        <GestureDialog
            form={LeaveRoomController.store.form({ room: roomCode })}
            label={t('room.lobby.leave')}
            title={t('room.lobby.leave')}
            description={t('room.lobby.leave_confirm')}
            icon={icon}
            destructive
            disabled={disabled}
            onHttpException={onHttpException}
            onRefused={onRefused}
        />
    );
}
