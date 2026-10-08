import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Le bac à sable des pages fictives du banc d'essai du design (spec 20 § 13.8,
 * demande du porteur du 08/10) : tant que l'hôte est monté, **toute visite
 * Inertia est annulée** — envoi d'un formulaire (connexion, lancement,
 * signalement), lien interne, rechargement partiel, changement de langue.
 * Un geste de la vraie page « part » donc sans jamais quitter l'aperçu ni
 * écrire quoi que ce soit (`router.on('before')` qui rend `false` annule la
 * visite). Les liens externes et les onglets nouveaux restent permis.
 *
 * Retiré au démontage : `strictMode` monte deux fois.
 */
export function useDesignSandbox(): void {
    useEffect(() => router.on('before', () => false), []);
}

/**
 * La page dont un hôte emprunte la coquille : `AuthLayout` et
 * `PublicLayout` choisissent leur variante par le nom de page
 * (`auth/login`, `room/join`…), alors que l'hôte s'appelle
 * `auth/design-preview` ou `legal/design-preview`. Prop de coquille
 * facultative, ignorée hors du banc d'essai.
 */
export type DesignPreviewLayoutProps = {
    previewComponent?: string;
};
