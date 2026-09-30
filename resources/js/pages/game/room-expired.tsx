import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useTranslations } from '@/hooks/use-translations';
import { home } from '@/routes';
import { create } from '@/routes/room';

/**
 * Le salon expiré — `room.show` sur un salon archivé, en 410 (spec 50
 * § 16.3 ; 90 § 10), sous `GameLayout`, forcée en sombre.
 *
 * On y arrive par un vieux lien, ou depuis la page du salon encore ouverte :
 * à `room.archived`, le magasin de 60 pose la sortie et la page visite
 * `room.show`. **Aucune prop** : aucune autre information sur le salon — ni
 * code, ni joueurs, ni date. Deux sorties : un nouveau salon (`room.create`)
 * et l'accueil, liens Wayfinder.
 *
 * États : aucun chargement propre (la barre de progression d'Inertia suit un
 * lien), aucune erreur, rien de temps réel à perdre. Clavier : le titre
 * reçoit le focus au montage — venue du lobby, le contrôle qui avait le
 * focus a été démonté avec lui, et le titre est lu par le lecteur d'écran
 * sans seconde région vivante (C16 § 4) ; puis les deux liens, dans l'ordre
 * du document. Le double montage de `strictMode` refocalise le même titre,
 * sans effet visible.
 */
export default function RoomExpired() {
    const { t } = useTranslations();
    const heading = useRef<HTMLHeadingElement>(null);

    useEffect(() => {
        heading.current?.focus();
    }, []);

    return (
        <>
            <Head title={t('room.expired.title')} />

            <ScrollArea className="h-full">
                <section
                    aria-labelledby="room-expired-title"
                    className="mx-auto flex w-full max-w-2xl flex-col gap-4 px-4 py-12"
                >
                    <h1
                        ref={heading}
                        id="room-expired-title"
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight focus-visible:outline-none"
                    >
                        {t('room.expired.title')}
                    </h1>

                    <p className="max-w-prose text-muted-foreground">
                        {t('room.expired.description')}
                    </p>

                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Button asChild className="min-h-11">
                            <Link href={create()}>
                                {t('room.expired.create')}
                            </Link>
                        </Button>

                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={home()}>{t('room.expired.home')}</Link>
                        </Button>
                    </div>
                </section>
            </ScrollArea>
        </>
    );
}
