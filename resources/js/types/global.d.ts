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
