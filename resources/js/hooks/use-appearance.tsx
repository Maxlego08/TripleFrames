import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    return (localStorage.getItem('appearance') as Appearance) || 'system';
};

const isDarkMode = (appearance: Appearance): boolean => {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

/**
 * Attribut posé sur `<html>` par `ForceAdminAppearance` (moitié serveur) puis
 * par `use-forced-appearance.ts` (moitié cliente). Tant qu'il est présent, un
 * sous-arbre force son apparence et la préférence stockée ne doit PAS être
 * réappliquée par-dessus — sinon le document naît clair, passe en sombre au
 * chargement de ce module, puis repasse en clair au montage du layout.
 */
export const FORCED_APPEARANCE_ATTRIBUTE = 'appearanceForced';

/** Le forçage en vigueur, ou `null` si l'arbre suit la préférence du visiteur. */
export function forcedAppearance(): ResolvedAppearance | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const value = document.documentElement.dataset[FORCED_APPEARANCE_ATTRIBUTE];

    return value === 'light' || value === 'dark' ? value : null;
}

/**
 * L'apparence stockée, RÉSOLUE au moment de l'appel — « système » comprise.
 *
 * Elle se recalcule au lieu de se mémoriser : le thème du système peut
 * basculer pendant qu'un sous-arbre force le sien, et restaurer une valeur
 * capturée plus tôt rendrait la main à une préférence périmée.
 */
export function resolveStoredAppearance(): ResolvedAppearance {
    return isDarkMode(getStoredAppearance()) ? 'dark' : 'light';
}

/** Pose (ou retire) la classe `dark` — aucune couleur n'est écrite ici. */
export function applyResolvedAppearance(resolved: ResolvedAppearance): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('dark', resolved === 'dark');
    document.documentElement.style.colorScheme = resolved;
}

const applyTheme = (appearance: Appearance): void => {
    applyResolvedAppearance(isDarkMode(appearance) ? 'dark' : 'light');
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

/**
 * Le thème du système a basculé. Un forçage en vigueur gagne : sans ce test,
 * un curateur en préférence « système » verrait le back-office passer en
 * sombre sous ses yeux.
 */
const handleSystemThemeChange = (): void => {
    const forced = forcedAppearance();

    if (forced !== null) {
        applyResolvedAppearance(forced);

        return;
    }

    applyTheme(currentAppearance);
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }

    currentAppearance = getStoredAppearance();

    // Le forçage serveur gagne au BOOT, sinon il ne gagne jamais : Blade a déjà
    // rendu un document clair, et réappliquer la préférence stockée ici le
    // peindrait en sombre le temps que le layout se monte. Rien n'est écrit
    // dans `localStorage` ni dans le cookie : la préférence du site public
    // reste exactement celle que le visiteur a choisie.
    applyResolvedAppearance(forcedAppearance() ?? resolveStoredAppearance());

    // Set up system theme change listener
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', mode);

        // Store in cookie for SSR...
        setCookie('appearance', mode);

        applyTheme(mode);
        notify();
    };

    return { appearance, resolvedAppearance, updateAppearance } as const;
}
