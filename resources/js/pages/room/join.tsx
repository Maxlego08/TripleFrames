import { Head, Link, usePage } from '@inertiajs/react';
import { Info, TriangleAlert } from 'lucide-react';
import RoomEntryController from '@/actions/App/Http/Controllers/Room/RoomEntryController';
import { SeatForm } from '@/components/room/seat-form';
import type { NicknameBounds } from '@/components/room/seat-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';
import { home } from '@/routes';
import type { TranslationKey } from '@/types/translations';

/**
 * État de la page d'entrée (spec 50 § 7.2), calculé par le serveur dans cet
 * ordre de priorité, et seulement INDICATIF : la prise de siège le recalcule
 * sous le verrou du salon.
 */
type RoomEntryState = 'kicked' | 'full' | 'late_join' | 'in_progress' | 'open';

type RoomJoinProps = {
    room: { code: string };
    entry: RoomEntryState;
    nickname: NicknameBounds;
};

/** États sans formulaire : le message dit tout, aucune mention des CGU. */
const CLOSED_ENTRY_KEYS: Partial<Record<RoomEntryState, TranslationKey>> = {
    kicked: 'room.join.kicked',
    full: 'room.join.full',
};

/**
 * États à formulaire qui portent un message : la partie en cours, où le
 * retardataire entre à la manche suivante sans aucun point (`late_join`,
 * § 15), ou attend la partie suivante (`in_progress`).
 */
const OPEN_ENTRY_KEYS: Partial<Record<RoomEntryState, TranslationKey>> = {
    late_join: 'room.join.late_join',
    in_progress: 'room.join.in_progress',
};

/**
 * Entrée dans un salon — `room.entry` (spec 50 § 7), dans `PublicLayout`, à
 * l'apparence du visiteur : le code ou le lien suffit, dans la limite des
 * sièges ; l'hôte ne valide pas chaque arrivée.
 *
 * C'est la cible du lien partagé `/r/{code}` (D55 du 02/10) : le code est
 * déjà dans l'adresse, la page ne demande QUE le pseudo. Aucun avatar :
 * le serveur l'attribue à la prise de siège, et il se change au lobby.
 * Un visiteur sans siège ne voit pas qui est dans le salon : aucun pseudo,
 * aucun identifiant, aucun avatar. Le titre ne porte ni code ni paramètre
 * (§ 6.5).
 *
 * États : `kicked` et `full` sans formulaire ni mention des CGU, avec un
 * lien vers l'accueil ; `late_join` avec le formulaire (le siège entrera à
 * la manche suivante, sans aucun point) ; `in_progress` avec le formulaire
 * (le siège attendra la partie suivante) ; `open`. Un refus rendu APRÈS
 * l'envoi (salon devenu complet, jeton expulsé entre-temps) revient sous
 * l'erreur `room`, dans la langue du joueur ; une page rechargée dans l'un
 * des deux états sans formulaire le dit déjà, et l'erreur n'y est pas
 * répétée. Aucune donnée à charger ; un refus du limiteur (429) rend la page
 * `error`.
 */
export default function RoomJoin({ room, entry, nickname }: RoomJoinProps) {
    const { t } = useTranslations();
    const { errors } = usePage().props;
    const closedKey = CLOSED_ENTRY_KEYS[entry];
    const noticeKey = OPEN_ENTRY_KEYS[entry];

    return (
        <>
            <Head title={t('room.join.title')} />

            <section className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-8">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('room.join.title')}
                </h1>

                {closedKey !== undefined ? (
                    <>
                        <Alert role="note">
                            <Info aria-hidden="true" />
                            <AlertDescription className="text-foreground">
                                {t(closedKey)}
                            </AlertDescription>
                        </Alert>

                        <Link
                            href={home()}
                            className="inline-flex min-h-11 items-center self-start rounded-sm underline underline-offset-4 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {t('common.nav.home')}
                        </Link>
                    </>
                ) : (
                    <>
                        {noticeKey !== undefined && (
                            <Alert role="note">
                                <Info aria-hidden="true" />
                                <AlertDescription className="text-foreground">
                                    {t(noticeKey)}
                                </AlertDescription>
                            </Alert>
                        )}

                        {errors.room !== undefined && (
                            <Alert variant="destructive">
                                <TriangleAlert aria-hidden="true" />
                                <AlertDescription>
                                    {errors.room}
                                </AlertDescription>
                            </Alert>
                        )}

                        <SeatForm
                            form={RoomEntryController.store.form({
                                room: room.code,
                            })}
                            nickname={nickname}
                            submitLabel={t('room.join.submit')}
                        />
                    </>
                )}
            </section>
        </>
    );
}
