import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import type { DesignPreviewLayoutProps } from '@/lib/design/sandbox';
import type { AuthLayoutKeys } from '@/types';

/**
 * Les écrans d'authentification déclarent leur titre dans `Page.layout`, donc
 * au chargement du module : ils passent des **clés**, et c'est ici qu'elles
 * sont résolues — au rendu, dans la langue du joueur.
 *
 * Le pied de page joueur complet suit le contenu, comme sur toute coquille
 * joueur (spec 90 § 2.4, § 3.1) ; le gabarit occupant toute la hauteur de
 * l'écran, il se trouve sous la ligne de flottaison. `<Toaster />` est monté
 * ici, et non plus globalement par `app.tsx` (spec 90 § 2.3).
 */
export default function AuthLayout({
    title,
    description,
    previewComponent,
    children,
}: AuthLayoutKeys & DesignPreviewLayoutProps & { children: ReactNode }) {
    const { t } = useTranslations();
    // Le banc d'essai du design rend la page sous le nom de son hôte : il
    // nomme la page montée, pour que la variante du gabarit soit la sienne.
    const component = usePage().component;
    const page = previewComponent ?? component;

    return (
        <>
            <AuthLayoutTemplate
                page={page}
                title={title === undefined ? '' : t(title)}
                description={description === undefined ? '' : t(description)}
            >
                {children}
            </AuthLayoutTemplate>
            <Toaster />
        </>
    );
}
