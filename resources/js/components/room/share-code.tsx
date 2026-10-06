import { Check, Copy, Eye, EyeOff, Link as LinkIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';

type ShareCodeProps = {
    /** Code du salon, masqué au premier rendu comme sur la maquette. */
    code: string;
    /** Lien absolu du salon, copiable depuis le menu du bouton. */
    url: string;
};

type CopySuccessKey = 'room.lobby.code_copied' | 'room.lobby.link_copied';

/** Barre compacte du code de la partie, fidèle à `waiting-room.html`. */
export function ShareCode({ code, url }: ShareCodeProps) {
    const { t } = useTranslations();
    const titleId = useId();
    const closeTimer = useRef<number | null>(null);
    const [visible, setVisible] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [toastMessage, setToastMessage] = useState<string | null>(null);

    useEffect(() => {
        if (toastMessage === null) {
            return;
        }

        const timeout = window.setTimeout(() => setToastMessage(null), 3000);

        return () => window.clearTimeout(timeout);
    }, [toastMessage]);

    useEffect(
        () => () => {
            if (closeTimer.current !== null) {
                window.clearTimeout(closeTimer.current);
            }
        },
        [],
    );

    const cancelClose = (): void => {
        if (closeTimer.current !== null) {
            window.clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    };

    const scheduleClose = (): void => {
        cancelClose();
        closeTimer.current = window.setTimeout(() => setMenuOpen(false), 180);
    };

    const confirmCopy = (successKey: CopySuccessKey): void => {
        const message = t(successKey);

        setToastMessage(message);
        setMenuOpen(false);
        announce(message);
    };

    const legacyCopy = (value: string): boolean => {
        const textarea = document.createElement('textarea');

        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, value.length);

        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            textarea.remove();
        }
    };

    const copy = async (
        value: string,
        successKey: CopySuccessKey,
    ): Promise<void> => {
        setToastMessage(null);

        try {
            await navigator.clipboard.writeText(value);
            confirmCopy(successKey);
            return;
        } catch {
            // Les origines HTTP locales peuvent refuser l'API moderne.
        }

        if (legacyCopy(value)) {
            confirmCopy(successKey);
        }
    };

    return (
        <>
            <section className="room-code" aria-labelledby={titleId}>
                <div
                    className="room-code__copy"
                    onMouseEnter={() => {
                        cancelClose();
                        setMenuOpen(true);
                    }}
                    onMouseLeave={scheduleClose}
                >
                    <DropdownMenu
                        modal={false}
                        open={menuOpen}
                        onOpenChange={setMenuOpen}
                    >
                        <DropdownMenuTrigger asChild>
                            <button type="button" className="room-code__action">
                                <span
                                    className="room-code__action-icon"
                                    aria-hidden="true"
                                >
                                    <Copy />
                                </span>
                                <span>{t('room.lobby.copy_code')}</span>
                            </button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent
                            align="start"
                            sideOffset={6}
                            className="room-copy-menu"
                            onMouseEnter={cancelClose}
                            onMouseLeave={scheduleClose}
                            onCloseAutoFocus={(event) => event.preventDefault()}
                        >
                            <DropdownMenuItem
                                className="room-copy-menu__item"
                                onSelect={() =>
                                    void copy(code, 'room.lobby.code_copied')
                                }
                            >
                                <Copy aria-hidden="true" />
                                {t('room.lobby.copy_room_code')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="room-copy-menu__item"
                                onSelect={() =>
                                    void copy(url, 'room.lobby.link_copied')
                                }
                            >
                                <LinkIcon aria-hidden="true" />
                                {t('room.lobby.copy_link')}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div className="room-code__display">
                    <p id={titleId}>{t('room.lobby.code_label')}</p>
                    <output aria-live="polite">
                        {visible ? code : '****'}
                    </output>
                </div>

                <button
                    type="button"
                    className="room-code__action"
                    aria-pressed={visible}
                    onClick={() => setVisible((current) => !current)}
                >
                    <span className="room-code__action-icon" aria-hidden="true">
                        {visible ? <EyeOff /> : <Eye />}
                    </span>
                    <span>
                        {visible
                            ? t('room.lobby.hide_code')
                            : t('room.lobby.show_code')}
                    </span>
                </button>
            </section>

            {toastMessage !== null && (
                <div className="room-copy-toast" aria-hidden="true">
                    <Check />
                    <span>{toastMessage}</span>
                </div>
            )}
        </>
    );
}
