import type { ReactNode } from 'react';
import { SiteFooter } from '@/components/public/site-footer';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
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
    children,
}: AuthLayoutKeys & { children: ReactNode }) {
    const { t } = useTranslations();

    return (
        <>
            <AuthLayoutTemplate
                title={title === undefined ? '' : t(title)}
                description={description === undefined ? '' : t(description)}
            >
                {children}
            </AuthLayoutTemplate>
            <SiteFooter variant="full" />
            <Toaster />
        </>
    );
}
