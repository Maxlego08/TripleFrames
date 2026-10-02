import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { redirect } from '@/routes/oauth';
import type { TranslationKey } from '@/types/translations';

/** Un fournisseur de connexion, miroir d'`OAuthProvider` (spec 40 § 12). */
export type OAuthProviderValue = 'google' | 'discord';

/** L'intention de l'aller-retour, miroir d'`OAuthIntent`. */
export type OAuthIntentValue = 'login' | 'link' | 'confirm';

const PROVIDER_KEYS: Record<OAuthProviderValue, TranslationKey> = {
    google: 'common.provider.google',
    discord: 'common.provider.discord',
};

/** Garde de type d'une valeur reçue du serveur. */
export function isOAuthProvider(value: string): value is OAuthProviderValue {
    return Object.hasOwn(PROVIDER_KEYS, value);
}

/** Le nom traduit d'un fournisseur. */
export function useProviderName(): (provider: OAuthProviderValue) => string {
    const { t } = useTranslations();

    return (provider) => t(PROVIDER_KEYS[provider]);
}

type Props = {
    /** Fournisseurs actifs (`oauthProviders`), ou liés pour une confirmation. */
    providers: readonly string[];
    intent: OAuthIntentValue;
    /** `account.oauth.continue_with` par défaut, `confirm_with` pour confirmer. */
    labelKey?: TranslationKey;
};

/**
 * Les boutons « Continuer avec Google / Discord » (spec 40 § 12.1, D51 du
 * 01/10).
 *
 * **Une redirection hors Inertia** (`CLAUDE.md` § 5, exception OAuth) : de
 * simples liens `<a href>` vers `oauth.redirect`, jamais un `<Form>` ni une
 * visite Inertia, que le fournisseur ne saurait pas recevoir. Rien n'est
 * rendu sans fournisseur actif.
 */
export function OAuthButtons({
    providers,
    intent,
    labelKey = 'account.oauth.continue_with',
}: Props) {
    const { t } = useTranslations();
    const name = useProviderName();
    const active = providers.filter(isOAuthProvider);

    if (active.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-2">
            {active.map((provider) => (
                <Button
                    key={provider}
                    variant="outline"
                    className="min-h-11 w-full"
                    asChild
                >
                    <a href={redirect({ provider }, { query: { intent } }).url}>
                        {t(labelKey, { provider: name(provider) })}
                    </a>
                </Button>
            ))}
        </div>
    );
}
