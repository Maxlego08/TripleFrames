import type { FrameFormat } from '@/components/game/game-frame';
import type { LocaleOption } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { RealtimeConfig } from '@/types/game-wire';
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
             * Fournisseurs de connexion ACTIFS, clés posées
             * (`OAuthProviders::values()`, spec 40 § 12.1, D51 du 01/10) :
             * boutons de connexion, et « Se connecter » de l'en-tête public
             * même inscription fermée.
             */
            oauthProviders: Array<'google' | 'discord'>;
            /**
             * Le choix de la bannière de consentement (D62 du 06/10) ; nul
             * tant que le visiteur n'a pas répondu à la version courante.
             */
            consent: 'accepted' | 'refused' | null;
            /**
             * Format fixe de la frame servable (`FrameGeometry::GAME_WIDTH` /
             * `GAME_HEIGHT`, contrat C9), identique pour tous et sans aucune
             * donnée de manche. Réservé aux attributs `width` / `height` de
             * l'image de `GameFrame` : le ratio du cadre vient du jeton
             * `--aspect-frame` (R-37).
             */
            frameFormat: FrameFormat;
            /**
             * Configuration du client temps réel (`RealtimeClientConfig`,
             * spec 60 § 10.5) : clé PUBLIQUE de Reverb, hôte, port et schéma
             * visés (nuls = `window.location`), cadence du battement et
             * échantillons d'horloge. Lue au runtime par `lib/game/echo.ts`,
             * jamais depuis une variable figée au build.
             */
            realtime: RealtimeConfig;
            /**
             * Drapeau de drainage de déploiement (`DeployDrain::isDraining()`,
             * spec 100 § 11.3, contrat C18-bis) : vrai tant qu'aucune nouvelle
             * partie ne peut être lancée, dans les deux phases du drainage.
             * Un booléen seulement — ni heure, ni phase, ni compte de
             * parties. Lu par le bandeau de maintenance (spec 90) ; le refus
             * de lancement, côté serveur, reste la seule garantie.
             */
            maintenance: boolean;
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
