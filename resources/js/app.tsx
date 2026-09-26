import { createInertiaApp } from '@inertiajs/react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import GameLayout from '@/layouts/game/game-layout';
import PublicLayout from '@/layouts/public/public-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'TripleFrames';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Coquille choisie par le nom de page, dans l'ordre de la spec 90 § 2.1
    // (contrat C16 § 2.1).
    layout: (name) => {
        switch (true) {
            // Page du starter, sans coquille jusqu'à sa réécriture en accueil
            // (L90-8), qui la fait entrer dans `PublicLayout`.
            case name === 'welcome':
                return null;
            // La page d'erreur joueur est une page publique, dans l'apparence
            // du visiteur, même levée depuis une route de jeu (spec 90 § 4.8).
            // `admin/error` reste dans la coquille du back-office.
            case name === 'error':
            case name.startsWith('legal/'):
                return PublicLayout;
            // Toute page `game/*`, et elle seule, est plein écran et forcée en
            // sombre (spec 90 § 2.1, § 2.2) : lobby, salon expiré, solo. Le
            // salon est UNE page du lobby au podium : manche, révélation et
            // podium en sont des états, jamais des pages, pour qu'aucune
            // visite ne démonte souscription, horloge ni annonceur.
            case name.startsWith('game/'):
                return GameLayout;
            // Le back-office a sa propre coquille : il n'hérite pas de la
            // barre latérale joueur. Son thème n'est pas forcé : il suit
            // l'apparence du visiteur (D8 du 23/09, spec 90 § 2.2).
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            // Page du starter conservée au jalon 1 comme cible de
            // `fortify.home` ; son retrait relève de 40 au jalon 2.
            case name === 'dashboard':
                return AppLayout;
            // Toute autre page est une page publique ordinaire, dans
            // l'apparence du visiteur : pages d'entrée `room/*` comprises.
            default:
                return PublicLayout;
        }
    },
    strictMode: true,
    // Aucun `<Toaster />` global : la section que rend sonner est une région
    // `aria-live` toujours présente, même vide, et une page de jeu ne doit en
    // compter qu'une, son annonceur (C16 § 4). `PublicLayout`, `AuthLayout`,
    // `AppLayout` et `AdminLayout` montent chacun le leur ; `GameLayout`,
    // jamais (spec 90 § 2.3).
    withApp(app) {
        return <TooltipProvider delayDuration={0}>{app}</TooltipProvider>;
    },
    // La barre de progression se peint au TOKEN, jamais à une couleur
    // littérale : elle s'affiche par-dessus toutes les coquilles, dans le thème
    // choisi par le visiteur comme sous un forçage, et un re-skin ne doit
    // toucher que le thème (règle 5). `--primary` est défini sur `:root` dans
    // `resources/css/app.css` et redéfini pour `.dark` : la barre suit donc le
    // thème en vigueur.
    progress: {
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
