import { Head, usePage } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { useId, useRef } from 'react';
import SoloGameController from '@/actions/App/Http/Controllers/Game/SoloGameController';
import type { PresetOption } from '@/components/room/preset-picker';
import { SeatForm } from '@/components/room/seat-form';
import type { NicknameBounds } from '@/components/room/seat-form';
import { SoloPresetField } from '@/components/room/solo-preset-field';
import { useTranslations } from '@/hooks/use-translations';

type RoomSoloProps = {
    /** Les quatre presets du site et leur `N` jouable le plus proche (§ 16.4). */
    presets: PresetOption[];
    nickname: NicknameBounds;
};

/** Champ sous lequel le serveur rend refus et échec technique (§ 16.2). */
const PRESET_FIELD = 'preset';

/**
 * Page d'entrée du solo — `solo.create` (spec 60 § 16.4 ; écart (l) du
 * § 22 bis), dans `PublicLayout`, au gabarit des pages d'entrée
 * (`room-entry.scss`, partagé avec la création et l'entrée d'un salon), en
 * billet large pour les quatre presets : une page `room/*`, qui ne reçoit
 * que les domaines `room` et `legal`.
 *
 * Premier passage d'un jeton qui ne tient aucun siège solo : le choix d'un
 * des quatre presets du site (D19 du 23/09, sans formulaire de réglages),
 * puis le pseudo du premier siège solo, et la mention des CGU
 * sous le bouton d'envoi (`SeatForm`). L'envoi (`solo.store`) démarre la
 * partie et mène à `game/solo` ; un porteur de siège solo n'arrive jamais
 * ici (303 vers `solo.show`). Aucun avatar à choisir : le serveur l'attribue
 * (D55 du 02/10), et un solo n'a pas de lobby où le changer.
 *
 * États : chargement (`processing` du formulaire : bouton désactivé,
 * `aria-busy`) ; erreur — pseudo refusé sous son champ, focus
 * rendu au pseudo ; preset invalide, drainage (`common.maintenance.
 * launch_blocked`), vivier sans `N` jouable (`game.errors.pool_too_small`)
 * ou échec technique (`room.errors.launch_failed`) sous le groupe des
 * presets, déjà traduits par le serveur, focus porté sur le preset coché ;
 * un refus du limiteur (429) rend la page `error`. Rien n'est chargé après
 * le rendu : aucun état de connexion propre à la page. Le titre ne porte
 * aucun paramètre.
 */
export default function RoomSolo({ presets, nickname }: RoomSoloProps) {
    const { t } = useTranslations();
    const { errors } = usePage().props;
    const headingId = useId();
    const presetGroup = useRef<HTMLDivElement>(null);

    function focusPreset(failed: Partial<Record<string, string>>): void {
        if (PRESET_FIELD in failed && !('nickname' in failed)) {
            presetGroup.current
                ?.querySelector<HTMLButtonElement>('[data-state="checked"]')
                ?.focus();
        }
    }

    return (
        <>
            <Head title={t('room.solo.title')} />

            <section className="auth-stage room-entry-stage">
                <section
                    className="auth-ticket room-entry-ticket room-entry-ticket--wide"
                    aria-labelledby={headingId}
                >
                    <div className="auth-ticket__form-panel">
                        <header className="auth-heading">
                            <h1 id={headingId}>{t('room.solo.title')}</h1>
                            <p>{t('room.solo.intro')}</p>
                        </header>

                        <div className="auth-content">
                            <SeatForm
                                form={SoloGameController.store.form()}
                                nickname={nickname}
                                submitLabel={t('room.solo.start')}
                                submitIcon={<Play aria-hidden="true" />}
                                nicknameHintKey="room.solo.nickname_hint"
                                onError={focusPreset}
                                className="room-entry-form"
                            >
                                <SoloPresetField
                                    presets={presets}
                                    legend={t('room.solo.choose_preset')}
                                    error={errors.preset}
                                    groupRef={presetGroup}
                                />
                            </SeatForm>
                        </div>
                    </div>
                </section>
            </section>
        </>
    );
}
