import { useCallback, useSyncExternalStore } from 'react';
import { themes, type ColorTheme } from '@/conf/themes';

const themeClassPrefix = 'theme-';
const storageKey = 'color-theme';

const listeners = new Set<() => void>();

function isValidTheme(theme: string | null): theme is ColorTheme {
    if (!theme) {
        return false;
    }

    return themes.some((config) => config.id === theme);
}

function setCookie(name: string, value: string, days = 365): void {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

function getStoredTheme(): ColorTheme {
    if (typeof window === 'undefined') {
        return 'default';
    }

    const storedTheme = localStorage.getItem(storageKey);

    if (isValidTheme(storedTheme)) {
        return storedTheme;
    }

    return 'default';
}

let currentColorTheme: ColorTheme = getStoredTheme();

function applyTheme(theme: ColorTheme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const themeClasses = Array.from(root.classList).filter((className) =>
        className.startsWith(themeClassPrefix),
    );

    themeClasses.forEach((className) => root.classList.remove(className));

    if (theme !== 'default') {
        root.classList.add(`${themeClassPrefix}${theme}`);
    }
}

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeColorTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem(storageKey)) {
        localStorage.setItem(storageKey, 'default');
        setCookie(storageKey, 'default');
    }

    currentColorTheme = getStoredTheme();
    applyTheme(currentColorTheme);

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey) {
            currentColorTheme = getStoredTheme();
            applyTheme(currentColorTheme);
            notify();
        }
    });
}

export function useColorTheme() {
    const colorTheme: ColorTheme = useSyncExternalStore(
        subscribe,
        () => currentColorTheme,
        () => 'default',
    );

    const updateColorTheme = useCallback((theme: ColorTheme): void => {
        currentColorTheme = theme;
        localStorage.setItem(storageKey, theme);
        setCookie(storageKey, theme);
        applyTheme(theme);
        notify();
    }, []);

    return {
        colorTheme,
        updateColorTheme,
    };
}

