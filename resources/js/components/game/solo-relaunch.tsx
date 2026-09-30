import { Form } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { useId, useRef } from 'react';
import SoloGameController from '@/actions/App/Http/Controllers/Game/SoloGameController';
import type { PresetOption } from '@/components/room/preset-picker';
import { SoloPresetField } from '@/components/room/solo-preset-field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

export type SoloRelaunchProps = {
    /**
     * Prop `presets` de `game/solo` (spec 60 § 16.4) : les quatre presets du
     * site et leur `N` jouable le plus proche, même forme que sur `room/solo`.
     */
    presets: PresetOption[];
    /**
     * Geste désactivé : onglet supplanté, connexion perdue. Le serveur relit
     * tout sous verrou de toute façon.
     */
    disabled: boolean;
    /**
     * Motifs, déjà traduits, qui rendent le geste impossible — le drainage
     * (`common.maintenance.launch_blocked`). Une aide d'affichage : le refus
     * du serveur fait seul autorité.
     */
    motives: string[];
    /** Un refus, déjà traduit par le serveur : la page l'annonce. */
    onRefused: (message: string) => void;
};

/** Champ sous lequel le serveur rend refus et échec technique (§ 16.2). */
const PRESET_FIELD = 'preset';

/**
 * La relance d'une partie solo depuis `game/solo` (spec 60 § 16.4 et § 16.6 ;
 * D19 du 23/09) : le choix d'un des quatre presets du site, sans formulaire
 * de réglages ni identité — le siège solo existe déjà —, envoyé à
 * `solo.store` par le `<Form>` d'Inertia (Wayfinder).
 *
 * Relancer pendant une partie en cours l'interrompt côté serveur ; un refus
 * (drainage, vivier sans `N` jouable) ou un échec technique n'interrompt
 * rien et revient sous le groupe des presets, déjà traduit, focus porté sur
 * le preset coché et message annoncé par la page. La page reste montée
 * (`preserveState`) : la nouvelle partie arrive en prop `state`, que le
 * magasin applique sans navigation ; le `N` ramené d'office s'annonce par
 * `settingsNotice`.
 *
 * États : chargement (bouton occupé, `aria-busy`, indicateur neutralisé,
 * aucun second envoi) ; erreur sous les presets ; motif d'impossibilité lié
 * au bouton (`aria-describedby`). Cibles d'au moins `min-h-11` ; tokens
 * seulement.
 */
export function SoloRelaunch({
    presets,
    disabled,
    motives,
    onRefused,
}: SoloRelaunchProps) {
    const { t } = useTranslations();
    const motiveId = useId();
    const presetGroup = useRef<HTMLDivElement>(null);

    return (
        <Form
            {...SoloGameController.store.form()}
            options={{ preserveScroll: true, preserveState: true }}
            onError={(errors) => {
                const message = errors[PRESET_FIELD];

                if (typeof message === 'string' && message !== '') {
                    presetGroup.current
                        ?.querySelector<HTMLButtonElement>(
                            '[data-state="checked"]',
                        )
                        ?.focus();
                    onRefused(message);
                }
            }}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <SoloPresetField
                        presets={presets}
                        legend={t('room.solo.choose_preset')}
                        error={errors[PRESET_FIELD]}
                        groupRef={presetGroup}
                    />

                    <div className="flex flex-col gap-2">
                        <Button
                            type="submit"
                            disabled={
                                processing || disabled || motives.length > 0
                            }
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
                                <Play aria-hidden="true" />
                            )}
                            {t('room.solo.start')}
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
                    </div>
                </>
            )}
        </Form>
    );
}
