import type { FrameFormat } from '@/components/game/game-frame';
import type { LocaleOption } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { TranslationMessages } from '@/types/translations';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /**
             * Vrai si l'inscription est ouverte (`AccountSwitches::
             * registrationOpen()`, spec 40 § 8.2). Gouverne les liens de
             * connexion et d'inscription de l'en-tête public et le crochet de
             * compte d'après podium : faux en production au jalon 1.
             */
            accountsOpen: boolean;
            /**
             * Format fixe de la frame servable (`FrameGeometry::GAME_WIDTH` /
             * `GAME_HEIGHT`, contrat C9), identique pour tous et sans aucune
             * donnée de manche. Réservé aux attributs `width` / `height` de
             * l'image de `GameFrame` : le ratio du cadre vient du jeton
             * `--aspect-frame` (R-37).
             */
            frameFormat: FrameFormat;
            /**
             * Code de la locale active, ex. `'fr'`. Résolu côté serveur par
             * `SetLocale` avant que les props partagées soient construites.
             */
            locale: string;
            /**
             * Registre des locales activées. `label` est le libellé **natif**,
             * jamais traduit : c'est ce qui permet à un joueur perdu dans une
             * interface qu'il ne lit pas de retrouver la sienne.
             */
            locales: LocaleOption[];
            /**
             * Dictionnaire aplati en clés pointées, **limité aux domaines
             * déclarés par la route** (`common` plus ceux du middleware
             * `translations:…`). Une clé absente ici n'est pas une faute de
             * frappe : c'est un domaine non déclaré.
             */
            translations: TranslationMessages;
            [key: string]: unknown;
        };
    }
}
