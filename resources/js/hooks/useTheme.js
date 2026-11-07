import { useEffect, useState } from 'react';

const preferences = ['light', 'dark', 'auto'];

function readPreference() {
    try {
        const saved = localStorage.getItem('theme');
        return preferences.includes(saved) ? saved : 'light';
    } catch {
        return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    }
}

export function useTheme() {
    const [preference, setPreference] = useState(readPreference);
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        function sync() {
            const next = readPreference();
            const isDark = next === 'dark' || (next === 'auto' && media.matches);
            document.documentElement.classList.toggle('dark', isDark);
            setPreference(next);
            setDark(isDark);
        }
        sync();
        window.addEventListener('theme-changed', sync);
        window.addEventListener('storage', sync);
        media.addEventListener('change', sync);
        return () => {
            window.removeEventListener('theme-changed', sync);
            window.removeEventListener('storage', sync);
            media.removeEventListener('change', sync);
        };
    }, []);

    function changePreference(next) {
        if (!preferences.includes(next)) {
            return;
        }
        const root = document.documentElement;
        const isDark = next === 'dark' || (next === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.classList.add('theme-transition');
        root.classList.toggle('dark', isDark);
        window.setTimeout(() => root.classList.remove('theme-transition'), 400);
        try {
            localStorage.setItem('theme', next);
        } catch {
            // The selected appearance still applies when browser storage is unavailable.
        }
        setPreference(next);
        setDark(isDark);
        window.dispatchEvent(new Event('theme-changed'));
    }

    return { preference, dark, changePreference };
}
