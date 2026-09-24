import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'TripleFrames';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            // Le back-office a sa propre coquille : il n'hérite pas de la
            // barre latérale joueur. Son thème n'est pas forcé : il suit
            // l'apparence du visiteur (D8 du 23/09, spec 90 § 2.2).
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
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
