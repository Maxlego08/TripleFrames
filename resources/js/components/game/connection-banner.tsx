import { RotateCw, WifiOff } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';

/** État de connexion d'un écran de jeu, fourni par la spec 60. */
export type ConnectionState = 'connected' | 'reconnecting' | 'offline';

/**
 * Bandeau de connexion des écrans de jeu (spec 90 § 7.6, contrat C16 § 2.8,
 * R-38).
 *
 * Monté par les pages de 60, qui lui fournissent l'état : ce composant ne lit
 * ni Echo ni horloge. **Rien n'est rendu à `connected`.** Sinon, un texte
 * `common.connection.*` dit la coupure et que le jeu continue : pendant une
 * déconnexion, le serveur ne s'arrête pas, le chrono non plus, et l'écran le
 * dit.
 *
 * Bandeau de coquille sans prop de texte, il résout lui-même ses seules clés
 * (`common`, domaine joint à toute page). Ce n'est **pas** une région
 * vivante : `Alert` porte ici le rôle `note`, pour que l'annonceur reste la
 * seule région qui parle sur une page de jeu (C16 § 4). Le retour de la
 * connexion est annoncé par le hook de 60 qui fournit l'état
 * (`common.connection.restored`, par `announce()`), jamais par ce bandeau.
 * L'état n'est jamais porté par la seule couleur : icône et texte.
 */
export function ConnectionBanner({ state }: { state: ConnectionState }) {
    const { t } = useTranslations();

    if (state === 'connected') {
        return null;
    }

    const offline = state === 'offline';

    return (
        <Alert role="note">
            {offline ? (
                <WifiOff aria-hidden="true" />
            ) : (
                <RotateCw aria-hidden="true" />
            )}
            <AlertDescription className="text-foreground">
                {offline
                    ? t('common.connection.offline')
                    : t('common.connection.reconnecting')}
            </AlertDescription>
        </Alert>
    );
}
