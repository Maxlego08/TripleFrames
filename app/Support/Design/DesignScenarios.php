<?php

namespace App\Support\Design;

/**
 * Le registre des scénarios du banc d'essai du design (`admin.design.index`,
 * spec 20 § 13.8, demande du porteur du 08/10) — **seule liste des clés
 * valides côté serveur**. Elle sert l'index (liste groupée) et contraint
 * `design.frame` (une clé hors registre répond 404).
 *
 * Deux sortes, lues sur le préfixe de la clé :
 *
 * - **`live.*`** : une page qui s'affiche déjà, avec de vraies données, à sa
 *   vraie URL pour un administrateur connecté. L'iframe charge cette URL
 *   (helper Wayfinder côté client) ; `route` nomme la route, pour les tests.
 * - **fixture** (`game.*`, `auth.*`, `public.*`) : la vraie page rendue avec
 *   des données FICTIVES décrites en TypeScript (`resources/js/lib/design/`),
 *   par `design.frame` et le composant hôte de sa coquille :
 *   `game/design-preview` (GameLayout), `auth/design-preview` (AuthLayout),
 *   `legal/design-preview` (PublicLayout). Rien n'est écrit en base.
 *
 * Le registre client (`resources/js/lib/design/scenario-keys.ts`) déclare
 * exactement les mêmes clés : `DesignPreviewTest` refuse tout écart.
 *
 * Libellés : `admin.design.scenario.{clé}` et `admin.design.group.{groupe}`,
 * des clés envoyées en données, traduites par le client (domaine `admin`).
 */
final class DesignScenarios
{
    public const string KIND_LIVE = 'live';

    public const string KIND_FIXTURE = 'fixture';

    /** Préfixe des scénarios « live ». */
    public const string LIVE_PREFIX = 'live';

    /** Composant hôte de chaque préfixe de scénario fictif. */
    public const array HOSTS = [
        'game' => 'game/design-preview',
        'auth' => 'auth/design-preview',
        'public' => 'legal/design-preview',
    ];

    /** Motif de la clé `{scenario}` de `design.frame` : un préfixe d'hôte, un nom. */
    public const string ROUTE_PATTERN = '(game|auth|public)\.[a-z0-9_]+';

    /** Les groupes, dans l'ordre de la liste. */
    public const array GROUPS = [
        'entry',
        'lobby',
        'round',
        'pause',
        'reveal',
        'podium',
        'solo',
        'account',
        'legal',
        'errors',
    ];

    /**
     * Chaque scénario : clé → groupe et, pour un scénario `live.*`, la route
     * qu'il affiche. L'ordre est celui de la liste.
     *
     * @var array<string, array{group: string, route: string|null}>
     */
    public const array SCENARIOS = [
        // Accueil et entrée.
        'live.home' => ['group' => 'entry', 'route' => 'home'],
        'live.room_create' => ['group' => 'entry', 'route' => 'room.create'],
        'public.room_join_open' => ['group' => 'entry', 'route' => null],
        'public.room_join_late_join' => ['group' => 'entry', 'route' => null],
        'public.room_join_in_progress' => ['group' => 'entry', 'route' => null],
        'public.room_join_full' => ['group' => 'entry', 'route' => null],
        'public.room_join_kicked' => ['group' => 'entry', 'route' => null],

        // Salon.
        'game.lobby_host' => ['group' => 'lobby', 'route' => null],
        'game.lobby_guest' => ['group' => 'lobby', 'route' => null],
        'game.lobby_blocked' => ['group' => 'lobby', 'route' => null],
        'game.late_joiner' => ['group' => 'lobby', 'route' => null],
        'game.room_expired' => ['group' => 'lobby', 'route' => null],

        // Manche.
        'game.countdown' => ['group' => 'round', 'route' => null],
        'game.round_normal' => ['group' => 'round', 'route' => null],
        'game.round_normal_qcm' => ['group' => 'round', 'route' => null],
        'game.round_text_exhausted' => ['group' => 'round', 'route' => null],
        'game.round_expert' => ['group' => 'round', 'route' => null],
        'game.round_easy' => ['group' => 'round', 'route' => null],
        'game.round_locked' => ['group' => 'round', 'route' => null],
        'game.round_closed' => ['group' => 'round', 'route' => null],
        'game.round_cancelled' => ['group' => 'round', 'route' => null],

        // Pause.
        'game.pause_requested' => ['group' => 'pause', 'route' => null],
        'game.pause_manual' => ['group' => 'pause', 'route' => null],
        'game.pause_empty' => ['group' => 'pause', 'route' => null],

        // Révélation.
        'game.reveal_found' => ['group' => 'reveal', 'route' => null],
        'game.reveal_nobody' => ['group' => 'reveal', 'route' => null],

        // Podium.
        'game.podium_completed' => ['group' => 'podium', 'route' => null],
        'game.podium_interrupted' => ['group' => 'podium', 'route' => null],
        'game.podium_scoreless' => ['group' => 'podium', 'route' => null],

        // Solo.
        'live.solo_create' => ['group' => 'solo', 'route' => 'solo.create'],
        'game.solo_round' => ['group' => 'solo', 'route' => null],
        'game.solo_reveal' => ['group' => 'solo', 'route' => null],
        'game.solo_podium' => ['group' => 'solo', 'route' => null],

        // Comptes et authentification.
        'auth.login' => ['group' => 'account', 'route' => null],
        'auth.register' => ['group' => 'account', 'route' => null],
        'auth.forgot_password' => ['group' => 'account', 'route' => null],
        'auth.reset_password' => ['group' => 'account', 'route' => null],
        'auth.verify_email' => ['group' => 'account', 'route' => null],
        'auth.two_factor_challenge' => ['group' => 'account', 'route' => null],
        'auth.confirm_password' => ['group' => 'account', 'route' => null],
        'auth.oauth_finish' => ['group' => 'account', 'route' => null],
        'auth.terms_update' => ['group' => 'account', 'route' => null],
        'live.settings_profile' => ['group' => 'account', 'route' => 'profile.edit'],
        'live.settings_security' => ['group' => 'account', 'route' => 'security.edit'],
        'live.settings_avatar' => ['group' => 'account', 'route' => 'avatar.edit'],
        'live.settings_accounts' => ['group' => 'account', 'route' => 'linked_accounts.edit'],
        'live.settings_history' => ['group' => 'account', 'route' => 'history.index'],

        // Pages légales et signalement.
        'live.legal_notice' => ['group' => 'legal', 'route' => 'legal.notice'],
        'live.legal_terms' => ['group' => 'legal', 'route' => 'legal.terms'],
        'live.legal_privacy' => ['group' => 'legal', 'route' => 'legal.privacy'],
        'live.takedown' => ['group' => 'legal', 'route' => 'takedown.create'],
        'public.report_movie' => ['group' => 'legal', 'route' => null],
        'public.report_frame' => ['group' => 'legal', 'route' => null],

        // Erreurs.
        'public.error_403' => ['group' => 'errors', 'route' => null],
        'public.error_404' => ['group' => 'errors', 'route' => null],
        'public.error_419' => ['group' => 'errors', 'route' => null],
        'public.error_429' => ['group' => 'errors', 'route' => null],
        'public.error_500' => ['group' => 'errors', 'route' => null],
        'public.error_503' => ['group' => 'errors', 'route' => null],
    ];

    /** Vrai pour un scénario fictif du registre, rendu par `design.frame`. */
    public static function isFixture(string $key): bool
    {
        return array_key_exists($key, self::SCENARIOS) && self::host($key) !== null;
    }

    /**
     * Le composant hôte d'un scénario fictif, `null` pour un scénario
     * `live.*` ou une clé hors registre.
     */
    public static function host(string $key): ?string
    {
        if (! array_key_exists($key, self::SCENARIOS)) {
            return null;
        }

        $prefix = explode('.', $key, 2)[0];

        return self::HOSTS[$prefix] ?? null;
    }

    /** La sorte d'un scénario du registre. */
    public static function kind(string $key): string
    {
        return str_starts_with($key, self::LIVE_PREFIX.'.') ? self::KIND_LIVE : self::KIND_FIXTURE;
    }

    /**
     * Les clés d'une sorte, dans l'ordre du registre.
     *
     * @return list<string>
     */
    public static function keys(string $kind): array
    {
        return array_values(array_filter(
            array_keys(self::SCENARIOS),
            static fn (string $key): bool => self::kind($key) === $kind,
        ));
    }

    /**
     * La liste de l'index, en données : clé, sorte, groupe et clés de
     * libellé. Aucune URL : le client les compose par Wayfinder.
     *
     * @return list<array{key: string, kind: string, group: string, labelKey: string}>
     */
    public static function forIndex(): array
    {
        $list = [];

        foreach (self::SCENARIOS as $key => $scenario) {
            $list[] = [
                'key' => $key,
                'kind' => self::kind($key),
                'group' => $scenario['group'],
                'labelKey' => self::labelKey($key),
            ];
        }

        return $list;
    }

    /**
     * Les groupes, dans l'ordre, et leur clé de libellé.
     *
     * @return list<array{key: string, labelKey: string}>
     */
    public static function groupsForIndex(): array
    {
        return array_map(
            static fn (string $group): array => ['key' => $group, 'labelKey' => 'admin.design.group.'.$group],
            self::GROUPS,
        );
    }

    /** La clé de libellé d'un scénario. */
    public static function labelKey(string $key): string
    {
        return 'admin.design.scenario.'.$key;
    }
}
