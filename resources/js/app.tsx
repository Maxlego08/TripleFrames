import { createInertiaApp } from '@inertiajs/react';
import '../scss/auth.scss';
import '../scss/home.scss';
import '../scss/legal.scss';
import '../scss/lobby.scss';
import '../scss/room-entry.scss';
import '../scss/settings.scss';
import { TooltipProvider } from '@/components/ui/tooltip';
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
            // L'accueil, les pages légales et la page d'erreur joueur sont des
            // pages publiques (spec 90 § 4.7).
            // La page d'erreur l'est même levée depuis une route de jeu (spec
            // 90 § 4.8) ; `admin/error` reste dans la coquille du back-office.
            case name === 'welcome':
            case name === 'error':
            case name.startsWith('legal/'):
                return PublicLayout;
            // Toute page `game/*`, et elle seule, est plein écran (spec 90
            // § 2.1) : lobby, salon expiré, solo. Le
            // salon est UNE page du lobby au podium : manche, révélation et
            // podium en sont des états, jamais des pages, pour qu'aucune
            // visite ne démonte souscription, horloge ni annonceur.
            case name.startsWith('game/'):
                return GameLayout;
            // Le back-office a sa propre coquille : il n'hérite pas de la
            // barre latérale joueur. Il garde le design du starter, en sombre
            // comme tout le site (D56 du 02/10).
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            // Toute autre page est une page publique ordinaire : pages
            // d'entrée `room/*` comprises.
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
    // littérale : elle s'affiche par-dessus toutes les coquilles, et un
    // re-skin ne doit toucher que le thème (règle 5). `--primary` est défini
    // pour `.dark` dans `resources/css/app.css`, classe que `<html>` porte
    // toujours (D56 du 02/10).
    progress: {
        color: 'var(--primary)',
    },
});
