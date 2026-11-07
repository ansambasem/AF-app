import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * A color whose value lives in resources/css/app.css as an RGB triple
 * (e.g. `--brand-500: 165 29 38`), so opacity modifiers such as
 * `bg-brand-500/10` keep working.
 */
const variable = (name) => `rgb(var(--${name}) / <alpha-value>)`;

const scale = (name, steps) => Object.fromEntries(steps.map((step) => [step, variable(`${name}-${step}`)]));

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"IBM Plex Sans Arabic"', ...defaultTheme.fontFamily.sans],
                luxe: ['"El Messiri"', '"IBM Plex Sans Arabic"', ...defaultTheme.fontFamily.serif],
                display: ['Alexandria', '"IBM Plex Sans Arabic"', ...defaultTheme.fontFamily.sans],
            },
            /*
             * Arabic needs more room than Latin text to read comfortably, so
             * every size sits two pixels above Tailwind's default (xs 14,
             * sm 16, base 17, lg 20…) with looser lines, all in step so the
             * proportions hold. The smallest text anywhere is 12px.
             */
            fontSize: {
                xs: ['0.875rem', { lineHeight: '1.6' }],
                sm: ['1rem', { lineHeight: '1.6' }],
                base: ['1.0625rem', { lineHeight: '1.7' }],
                lg: ['1.25rem', { lineHeight: '1.6' }],
                xl: ['1.375rem', { lineHeight: '1.5' }],
                '2xl': ['1.625rem', { lineHeight: '1.4' }],
                '3xl': ['2rem', { lineHeight: '1.3' }],
            },
            colors: {
                brand: scale('brand', [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]),
                gray: scale('gray', [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]),
                graphite: scale('graphite', [700, 800, 900]),
                blue: scale('blue', [500, 600, 700]),
                print: scale('print', [300, 500, 600, 700]),
                excel: scale('excel', [300, 500, 600, 700]),
                surface: variable('surface'),
            },
            borderRadius: {
                control: '12px',
                row: '18px',
                card: '24px',
                panel: '28px',
                hero: '32px',
            },
            boxShadow: {
                card: '0 1px 2px rgb(16 24 40 / 0.04), 0 14px 34px -18px rgb(16 24 40 / 0.18)',
                lift: '0 2px 6px rgb(16 24 40 / 0.06), 0 24px 44px -20px rgb(16 24 40 / 0.32)',
                glow: '0 10px 24px -12px rgb(165 29 38 / 0.9)',
            },
            backgroundImage: {
                spectrum: 'var(--spectrum)',
                'brand-gradient': 'linear-gradient(180deg, #b8232c, #7d121b)',
                'graphite-gradient': 'linear-gradient(135deg, #262c33, #1b2026 60%, #111519)',
            },
        },
    },

    plugins: [forms],
};
