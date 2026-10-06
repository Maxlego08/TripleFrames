import { Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useId } from 'react';
import RoomController from '@/actions/App/Http/Controllers/Room/RoomController';
import { SeatForm } from '@/components/room/seat-form';
import type { NicknameBounds } from '@/components/room/seat-form';
import { useTranslations } from '@/hooks/use-translations';

type RoomCreateProps = {
    nickname: NicknameBounds;
};

/**
 * Création de salon — `room.create` (spec 50 § 6.1 et § 6.2), dans
 * `PublicLayout`.
 *
 * « Créer un salon » crée le salon TOUT DE SUITE, aux réglages par défaut :
 * l'hôte ne saisit ici que son pseudo, et règle la partie dans le lobby, où
 * le code se partage pendant qu'il règle ; son avatar, attribué par le
 * serveur, s'y change aussi (D55 du 02/10). L'envoi mène à la
 * page du salon (`room.show`).
 *
 * Aucune donnée à charger : les états sont ceux du formulaire (soumission,
 * erreurs de validation) ; un refus du limiteur (429) rend la page `error`.
 * La création reste possible pendant un drainage : seul le lancement est
 * refusé. Le titre ne porte ni code ni paramètre (§ 6.5).
 */
export default function RoomCreate({ nickname }: RoomCreateProps) {
    const { t } = useTranslations();
    const headingId = useId();

    return (
        <>
            <Head title={t('room.create.title')} />

            <section className="auth-stage room-create-stage">
                <section
                    className="auth-ticket room-create-ticket"
                    aria-labelledby={headingId}
                >
                    <div className="auth-ticket__form-panel">
                        <header className="auth-heading">
                            <h1 id={headingId}>{t('room.create.title')}</h1>
                            <p>{t('room.create.intro')}</p>
                        </header>

                        <div className="auth-content">
                            <SeatForm
                                form={RoomController.store.form()}
                                nickname={nickname}
                                submitLabel={t('room.create.submit')}
                                submitIcon={<Sparkles aria-hidden="true" />}
                                className="room-create-form"
                            />
                        </div>
                    </div>
                </section>
            </section>
        </>
    );
}
