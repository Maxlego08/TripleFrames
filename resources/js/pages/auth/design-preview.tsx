import { setLayoutProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useDesignSandbox } from '@/lib/design/sandbox';
import { isAuthScenarioKey } from '@/lib/design/scenario-keys';
import type { AuthScenarioKey } from '@/lib/design/scenario-keys';
import ConfirmPassword from '@/pages/auth/confirm-password';
import ForgotPassword from '@/pages/auth/forgot-password';
import Login from '@/pages/auth/login';
import OAuthFinish from '@/pages/auth/oauth-finish';
import Register from '@/pages/auth/register';
import ResetPassword from '@/pages/auth/reset-password';
import TermsUpdate from '@/pages/auth/terms-update';
import TwoFactorChallenge from '@/pages/auth/two-factor-challenge';
import VerifyEmail from '@/pages/auth/verify-email';
import type { AuthLayoutKeys } from '@/types';

/** Props de `design.frame` lues par cet hôte (`DesignPreviewController`). */
type AuthDesignPreviewProps = {
    scenario: string;
    passwordRules: string;
};

/** Adresse fictive des formulaires préremplis : une donnée de test. */
const SAMPLE_EMAIL = 'camille@example.com';

/** Ce que l'hôte monte : la page, son nom et les clés de son gabarit. */
type AuthScene = {
    component: string;
    layout: AuthLayoutKeys;
    page: ReactNode;
};

function sceneOf(key: AuthScenarioKey, passwordRules: string): AuthScene {
    switch (key) {
        case 'auth.login':
            return {
                component: 'auth/login',
                layout: Login.layout,
                page: (
                    <Login
                        canResetPassword
                        canRegister
                        canUsePasskeys={false}
                    />
                ),
            };
        case 'auth.register':
            return {
                component: 'auth/register',
                layout: Register.layout,
                page: <Register passwordRules={passwordRules} />,
            };
        case 'auth.forgot_password':
            return {
                component: 'auth/forgot-password',
                layout: ForgotPassword.layout,
                page: <ForgotPassword />,
            };
        case 'auth.reset_password':
            return {
                component: 'auth/reset-password',
                layout: ResetPassword.layout,
                page: (
                    <ResetPassword
                        token="design-preview"
                        email={SAMPLE_EMAIL}
                        passwordRules={passwordRules}
                    />
                ),
            };
        case 'auth.verify_email':
            return {
                component: 'auth/verify-email',
                layout: VerifyEmail.layout,
                page: <VerifyEmail />,
            };
        case 'auth.two_factor_challenge':
            // La page pose elle-même ses clés de gabarit.
            return {
                component: 'auth/two-factor-challenge',
                layout: {},
                page: <TwoFactorChallenge />,
            };
        case 'auth.confirm_password':
            return {
                component: 'auth/confirm-password',
                layout: ConfirmPassword.layout,
                page: (
                    <ConfirmPassword
                        canUsePasskeys={false}
                        hasPassword
                        confirmProviders={['discord']}
                    />
                ),
            };
        case 'auth.oauth_finish':
            return {
                component: 'auth/oauth-finish',
                layout: OAuthFinish.layout,
                page: (
                    <OAuthFinish
                        provider="discord"
                        email={SAMPLE_EMAIL}
                        suggestedName="Camille"
                    />
                ),
            };
        case 'auth.terms_update':
            return {
                component: 'auth/terms-update',
                layout: TermsUpdate.layout,
                page: <TermsUpdate firstAcceptance={false} asksAge />,
            };
    }
}

/**
 * Hôte des scénarios `auth.*` du banc d'essai du design (spec 20 § 13.8,
 * demande du porteur du 08/10), sous `AuthLayout` par son nom de page : il
 * monte la VRAIE page d'authentification avec des props fictives, et passe
 * au gabarit les clés de titre de cette page et son nom (variante connexion
 * ou inscription). Le bac à sable annule tout envoi de formulaire.
 */
export default function AuthDesignPreview({
    scenario,
    passwordRules,
}: AuthDesignPreviewProps) {
    useDesignSandbox();

    const scene = isAuthScenarioKey(scenario)
        ? sceneOf(scenario, passwordRules)
        : null;

    // Même geste que `two-factor-challenge` : des props de gabarit posées au
    // rendu, des clés que `AuthLayout` traduit.
    setLayoutProps({
        ...scene?.layout,
        previewComponent: scene?.component,
    });

    return scene?.page ?? null;
}
