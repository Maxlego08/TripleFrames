/**
 * La cible des parcours Playwright (spec 100 § 19), lue dans les variables du
 * poste et jamais dans le dépôt.
 *
 * Module pur, sans import de Playwright : `playwright.config.ts` le lit, et
 * Vitest le prouve (`tests/Frontend/browser/target.test.ts`).
 *
 * Trois refus, avant tout navigateur :
 * - **en CI** : les parcours ne sont jamais joués à chaque PR, et la CI n'a ni
 *   préproduction ni identifiants (zéro secret, § 7.1) ;
 * - **hors préproduction** : un parcours crée des salons et des sièges ; joué
 *   contre la production, il y écrirait des données de recette. Seul un hôte
 *   `preprod.*` en HTTPS est admis, ou une machine locale (bouclage, `.test`,
 *   `.localhost`) pour la mise au point ;
 * - **identifiants incomplets** : l'authentification HTTP de la préproduction
 *   (`ops/nginx/additional-directives.preprod.conf`) se fournit entière ou
 *   pas du tout.
 */

export type BrowserTarget = {
    baseURL: string;
    httpCredentials?: { username: string; password: string };
};

export type TargetEnvironment = Readonly<Record<string, string | undefined>>;

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

function isLocalHost(hostname: string): boolean {
    return (
        LOOPBACK_HOSTS.has(hostname) ||
        hostname.endsWith('.localhost') ||
        hostname.endsWith('.test')
    );
}

function filled(value: string | undefined): string | null {
    const trimmed = value?.trim() ?? '';

    return trimmed === '' ? null : trimmed;
}

export function resolveTarget(env: TargetEnvironment): BrowserTarget {
    if (filled(env.CI) !== null) {
        throw new Error(
            'Les parcours Playwright ne sont jamais joués en CI (spec 100 § 19) : ils se jouent depuis le poste, contre la préproduction.',
        );
    }

    const raw = filled(env.E2E_BASE_URL);

    if (raw === null) {
        throw new Error(
            'E2E_BASE_URL est vide : adresse de la préproduction attendue (https://preprod.<DOMAINE>), variable du poste.',
        );
    }

    let url: URL;

    try {
        url = new URL(raw);
    } catch {
        throw new Error(`E2E_BASE_URL illisible : ${raw}`);
    }

    const local = isLocalHost(url.hostname);

    if (!local && url.protocol !== 'https:') {
        throw new Error(
            `La préproduction se joue en HTTPS seulement : ${url.origin}`,
        );
    }

    if (!local && !url.hostname.startsWith('preprod.')) {
        throw new Error(
            `Cible refusée : ${url.hostname} n’est pas la préproduction (preprod.*). Un parcours écrit des salons et des sièges : jamais contre la production.`,
        );
    }

    const username = filled(env.E2E_HTTP_USER);
    const password = filled(env.E2E_HTTP_PASSWORD);

    if ((username === null) !== (password === null)) {
        throw new Error(
            'E2E_HTTP_USER et E2E_HTTP_PASSWORD se fournissent ensemble ou pas du tout.',
        );
    }

    return {
        baseURL: url.origin,
        ...(username !== null && password !== null
            ? { httpCredentials: { username, password } }
            : {}),
    };
}
