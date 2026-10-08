/**
 * Les clés des scénarios du banc d'essai du design (spec 20 § 13.8, demande du
 * porteur du 08/10), miroir EXACT de `App\Support\Design\DesignScenarios` :
 * `DesignPreviewTest` lit ce fichier et refuse tout écart, dans un sens comme
 * dans l'autre. Module pur, sans import : Vitest le lit tel quel.
 *
 * - `live.*` : une vraie page, à sa vraie URL (`LIVE_SCENARIO_URLS` de la
 *   page `admin/design/index`, par Wayfinder) ;
 * - `game.*`, `auth.*`, `public.*` : une vraie page rendue avec des données
 *   fictives par `design.frame`, sous la coquille de son préfixe.
 */

export const LIVE_SCENARIO_KEYS = [
    'live.home',
    'live.room_create',
    'live.solo_create',
    'live.settings_profile',
    'live.settings_security',
    'live.settings_avatar',
    'live.settings_accounts',
    'live.settings_history',
    'live.legal_notice',
    'live.legal_terms',
    'live.legal_privacy',
    'live.takedown',
] as const;

export const GAME_SCENARIO_KEYS = [
    'game.lobby_host',
    'game.lobby_guest',
    'game.lobby_blocked',
    'game.late_joiner',
    'game.room_expired',
    'game.countdown',
    'game.round_normal',
    'game.round_normal_qcm',
    'game.round_text_exhausted',
    'game.round_expert',
    'game.round_easy',
    'game.round_locked',
    'game.round_closed',
    'game.round_cancelled',
    'game.pause_requested',
    'game.pause_manual',
    'game.pause_empty',
    'game.reveal_found',
    'game.reveal_nobody',
    'game.podium_completed',
    'game.podium_interrupted',
    'game.podium_scoreless',
    'game.solo_round',
    'game.solo_reveal',
    'game.solo_podium',
] as const;

export const AUTH_SCENARIO_KEYS = [
    'auth.login',
    'auth.register',
    'auth.forgot_password',
    'auth.reset_password',
    'auth.verify_email',
    'auth.two_factor_challenge',
    'auth.confirm_password',
    'auth.oauth_finish',
    'auth.terms_update',
] as const;

export const PUBLIC_SCENARIO_KEYS = [
    'public.room_join_open',
    'public.room_join_late_join',
    'public.room_join_in_progress',
    'public.room_join_full',
    'public.room_join_kicked',
    'public.report_movie',
    'public.report_frame',
    'public.error_403',
    'public.error_404',
    'public.error_419',
    'public.error_429',
    'public.error_500',
    'public.error_503',
] as const;

export type LiveScenarioKey = (typeof LIVE_SCENARIO_KEYS)[number];
export type GameScenarioKey = (typeof GAME_SCENARIO_KEYS)[number];
export type AuthScenarioKey = (typeof AUTH_SCENARIO_KEYS)[number];
export type PublicScenarioKey = (typeof PUBLIC_SCENARIO_KEYS)[number];
export type FixtureScenarioKey =
    | GameScenarioKey
    | AuthScenarioKey
    | PublicScenarioKey;
export type DesignScenarioKey = LiveScenarioKey | FixtureScenarioKey;

/** Garde de type sur une liste de clés figée. */
function within<K extends string>(
    keys: readonly K[],
    value: string,
): value is K {
    return (keys as readonly string[]).includes(value);
}

export function isLiveScenarioKey(value: string): value is LiveScenarioKey {
    return within(LIVE_SCENARIO_KEYS, value);
}

export function isGameScenarioKey(value: string): value is GameScenarioKey {
    return within(GAME_SCENARIO_KEYS, value);
}

export function isAuthScenarioKey(value: string): value is AuthScenarioKey {
    return within(AUTH_SCENARIO_KEYS, value);
}

export function isPublicScenarioKey(value: string): value is PublicScenarioKey {
    return within(PUBLIC_SCENARIO_KEYS, value);
}

export function isFixtureScenarioKey(
    value: string,
): value is FixtureScenarioKey {
    return (
        isGameScenarioKey(value) ||
        isAuthScenarioKey(value) ||
        isPublicScenarioKey(value)
    );
}
