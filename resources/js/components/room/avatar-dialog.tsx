import { X } from 'lucide-react';
import { AvatarPicker } from '@/components/game/avatar-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslations } from '@/hooks/use-translations';
import { lobbyAvatarOptions } from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';
import type { SeatAvatarChoice } from '@/types/player';

type AvatarDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Prop `avatars` de la page, relue à l'ouverture. */
    avatars: LobbyAvatars;
    /** Le choix affiché du siège, optimiste compris (`useSeatAvatar`). */
    value: SeatAvatarChoice | null;
    onChoose: (choice: SeatAvatarChoice) => void;
    /** Onglet supplanté, connexion perdue : la grille est inactive. */
    disabled: boolean;
};

/**
 * « Votre avatar » : la grille ouverte par un clic sur l'avatar du siège
 * dans la salle d'attente (D55 du 02/10, amendé le 06/10). Chaque tuile
 * cochée s'applique **aussitôt**, à la souris comme au clavier — l'envoi est
 * regroupé par `useSeatAvatar`, qui n'envoie que le dernier choix d'un
 * parcours ; « Fermer » ferme. Une tuile tenue par un autre siège est
 * désactivée ; le serveur reste juge.
 */
export function AvatarDialog({
    open,
    onOpenChange,
    avatars,
    value,
    onChoose,
    disabled,
}: AvatarDialogProps) {
    const { t } = useTranslations();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="waiting-room-dialog [&>button:last-child]:hidden">
                <DialogHeader className="waiting-room-dialog__header">
                    <div className="waiting-room-dialog__heading">
                        <DialogTitle>
                            {t('room.lobby.avatar.title')}
                        </DialogTitle>
                        <DialogClose asChild>
                            <Button type="button" variant="outline" size="sm">
                                <X aria-hidden="true" />
                                {t('common.action.close')}
                            </Button>
                        </DialogClose>
                    </div>
                    <DialogDescription>
                        {t('room.lobby.avatar.hint')}
                    </DialogDescription>
                </DialogHeader>

                <AvatarPicker
                    name="avatar"
                    options={lobbyAvatarOptions(avatars, t)}
                    account={
                        avatars.account === null
                            ? null
                            : {
                                  url: avatars.account.url,
                                  label: t('common.avatar.picker.account'),
                              }
                    }
                    value={value}
                    onValueChange={onChoose}
                    disabled={disabled}
                    legend={t('common.avatar.picker.label')}
                    takenLabel={t('common.avatar.picker.taken')}
                />
            </DialogContent>
        </Dialog>
    );
}
