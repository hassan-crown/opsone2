import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark' | 'contrast';
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

const prefersMoreContrast = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-contrast: more)').matches;
};

/** `system`: dark if the OS is dark, high contrast if requested (and not dark), else light. */
export const resolveAppearance = (
    appearance: Appearance,
): ResolvedAppearance => {
    if (appearance !== 'system') {
        return appearance;
    }

    if (prefersDark()) {
        return 'dark';
    }

    return prefersMoreContrast() ? 'contrast' : 'light';
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

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const resolved = resolveAppearance(appearance);
    const root = document.documentElement;

    // `.dark` keeps shadcn variants working; data-theme drives the design-system tokens.
    root.classList.toggle('dark', resolved === 'dark');
    root.setAttribute('data-theme', resolved);
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQueries = (): MediaQueryList[] => {
    if (typeof window === 'undefined') {
        return [];
    }

    return [
        window.matchMedia('(prefers-color-scheme: dark)'),
        window.matchMedia('(prefers-contrast: more)'),
    ];
};

const handleSystemThemeChange = (): void => applyTheme(currentAppearance);

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }

    currentAppearance = getStoredAppearance();
    applyTheme(currentAppearance);

    // Set up system theme change listener
    mediaQueries().forEach((query) =>
        query.addEventListener('change', handleSystemThemeChange),
    );
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const resolvedAppearance = resolveAppearance(appearance);

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
