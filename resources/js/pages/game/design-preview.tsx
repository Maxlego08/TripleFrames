import { useState } from 'react';
import type { PresetOption } from '@/components/room/preset-picker';
import { useTranslations } from '@/hooks/use-translations';
import { buildGameFixture } from '@/lib/design/game-fixtures';
import { useDesignSandbox } from '@/lib/design/sandbox';
import { isGameScenarioKey } from '@/lib/design/scenario-keys';
import Lobby from '@/pages/game/lobby';
import RoomExpired from '@/pages/game/room-expired';
import Solo from '@/pages/game/solo';
import { game as frameImage } from '@/routes/admin/catalog/frames';
import type { LocaleCode } from '@/types/game-wire';
import type { AvatarPresetOption } from '@/types/player';
import type {
    PlatformLimitsPayload,
    RoomSettingsBoundsPayload,
    RoomSettingsState,
} from '@/types/room-settings';

/** Props de `design.frame` lues par cet hôte (`DesignPreviewController`). */
type GameDesignPreviewProps = {
    scenario: string;
    images: string[];
    bounds: RoomSettingsBoundsPayload;
    limits: PlatformLimitsPayload;
    settings: RoomSettingsState;
    presets: PresetOption[];
    launch: { minConnected: number };
    editor: {
        advancedAvailable: boolean;
        themeSelectorVisible: boolean;
        lateJoinAvailable: boolean;
    };
    avatars: AvatarPresetOption[];
};

/** Jeton d'onglet fictif : présenté sur une requête, il ne tient aucun siège. */
const PREVIEW_SEAT_TOKEN = 'design-preview';

/**
 * Hôte des scénarios `game.*` du banc d'essai du design (spec 20 § 13.8,
 * demande du porteur du 08/10), sous `GameLayout` par son nom de page : il
 * monte la VRAIE page — `game/lobby`, `game/solo` ou `game/room-expired` —
 * avec un paquet fictif (`lib/design/game-fixtures`) et les vraies props
 * serveur. `designPreview` coupe toute requête de jeu ; le bac à sable annule
 * toute visite Inertia d'un geste.
 *
 * Sans image au catalogue publié, les paliers pointent l'aperçu admin d'une
 * image qui n'existe pas : le cadre dit « indisponible ».
 */
export default function GameDesignPreview({
    scenario,
    images,
    bounds,
    limits,
    settings,
    presets,
    launch,
    editor,
    avatars,
}: GameDesignPreviewProps) {
    useDesignSandbox();

    const { locale } = useTranslations();
    // Construit une fois par montage : un paquet recréé à chaque rendu
    // serait réappliqué par le magasin.
    const [fixture] = useState(() =>
        isGameScenarioKey(scenario)
            ? buildGameFixture(scenario, {
                  nowMs: Date.now(),
                  images:
                      images.length > 0
                          ? images
                          : [frameImage.url({ movie: 0, frame: 0 })],
                  avatars,
                  settings,
                  locale: (locale === 'en' ? 'en' : 'fr') satisfies LocaleCode,
              })
            : null,
    );

    if (fixture === null) {
        return null;
    }

    switch (fixture.page) {
        case 'room_expired':
            return <RoomExpired />;
        case 'solo':
            return (
                <Solo
                    state={fixture.packet}
                    seatToken={PREVIEW_SEAT_TOKEN}
                    settingsNotice={null}
                    limits={limits}
                    presets={presets}
                    designPreview
                />
            );
        case 'lobby':
            return (
                <Lobby
                    room={{ code: fixture.code }}
                    state={fixture.packet}
                    seatToken={PREVIEW_SEAT_TOKEN}
                    settings={fixture.settings}
                    bounds={bounds}
                    limits={limits}
                    presets={presets}
                    launch={launch}
                    editor={editor}
                    themes={null}
                    configs={null}
                    avatars={{
                        options: avatars,
                        taken: avatars.slice(1, 6).map((option) => option.key),
                        current: avatars[0]?.key ?? '',
                        account: null,
                    }}
                    designPreview
                />
            );
    }
}
