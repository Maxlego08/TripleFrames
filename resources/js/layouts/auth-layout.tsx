import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import type { AuthLayoutKeys } from '@/types';

/**
 * Les écrans d'authentification déclarent leur titre dans `Page.layout`, donc
 * au chargement du module : ils passent des **clés**, et c'est ici qu'elles
 * sont résolues — au rendu, dans la langue du joueur.
 */
export default function AuthLayout({
    title,
    description,
    children,
}: AuthLayoutKeys & { children: ReactNode }) {
    const { t } = useTranslations();

    return (
        <AuthLayoutTemplate
            title={title === undefined ? '' : t(title)}
            description={description === undefined ? '' : t(description)}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
