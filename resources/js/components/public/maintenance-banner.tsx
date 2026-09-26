import { usePage } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Bandeau de maintenance (spec 90 § 3.3, D32 du 23/09, contrat C18-bis).
 *
 * Lit la prop partagée `maintenance: boolean` (`DeployDrain::isDraining()`,
 * vraie dans les deux phases du drainage) et, si elle est vraie, rend le seul
 * texte `common.maintenance.banner` ; sinon, rien. Monté par `PublicLayout`,
 * sous l'en-tête, et par `GameLayout`, en tête de coquille : il couvre ainsi
 * tout écran où un lancement peut être demandé (création, solo, lobby,
 * « Rejouer »).
 *
 * - **Ni heure, ni phase, ni nombre de parties** : la prop est un booléen et
 *   rien d'autre (C18-bis § 3) ; une heure affichée serait une promesse que le
 *   déploiement manuel ne tient pas.
 * - **Pas une garantie** : aucune diffusion temps réel au jalon 1, le bandeau
 *   paraît à la réponse Inertia suivante. Seul le refus serveur de tout
 *   lancement (`common.maintenance.launch_blocked`) garantit le drainage, et
 *   la page l'affiche là où elle affiche ses refus (50, 60).
 * - **Il ne parle pas** : `Alert` porte le rôle `note`, qui supplante le
 *   `role="alert"` du composant généré ; un bandeau inséré au rendu n'a rien
 *   à interrompre, et la seule région vivante d'une page de jeu est
 *   l'annonceur (C16 § 4).
 * - Il ne coupe ni ne signale jamais une partie en cours : le drainage n'agit
 *   que sur les lancements (C18-bis § 4).
 *
 * Bandeau de coquille sans prop de texte, il résout lui-même sa seule clé
 * (`common`, domaine joint à toute page, § 7.6). Le sens n'est jamais porté
 * par la seule couleur : icône décorative et texte.
 */
export function MaintenanceBanner() {
    const { t } = useTranslations();
    const { maintenance } = usePage().props;

    if (!maintenance) {
        return null;
    }

    return (
        <div className="border-b border-border bg-muted">
            <Alert
                role="note"
                className="mx-auto max-w-5xl rounded-none border-0 bg-transparent"
            >
                <Wrench aria-hidden="true" />
                <AlertDescription className="text-foreground">
                    {t('common.maintenance.banner')}
                </AlertDescription>
            </Alert>
        </div>
    );
}
