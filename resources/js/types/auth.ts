/**
 * Miroir front de `App\Enums\UserRole` : `player` ⊂ `curator` ⊂ `admin`.
 *
 * Le rôle n'est PAS dans le `#[Hidden]` de `User` : il arrive déjà au client,
 * et c'est lui qui conditionne l'affichage d'une entrée de navigation. Sans
 * ce champ déclaré, l'index signature `[key: string]: unknown` ci-dessous
 * ferait échouer toute comparaison sous `tsc --noEmit`.
 *
 * Il ne sert qu'à l'affichage : l'autorisation est serveur, posée route par
 * route par `can:` et par les policies. Le front ne rejoue jamais le seuil.
 */
export type UserRole = 'player' | 'curator' | 'admin';

/** Miroir front de `App\Enums\Locale` : les langues activées en v1. */
export type UserLocale = 'fr' | 'en';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    role: UserRole;
    locale: UserLocale;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
