import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './Modules/**/Resources/views/**/*.blade.php',
        // A theme's views are Blade like any other, and a class only used by
        // one theme still has to survive the purge (wave 4.1).
        './themes/**/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: 'var(--brand-primary)',
                    contrast: 'var(--brand-primary-contrast)',
                },
                accent: 'var(--brand-accent)',
                // Theme tokens as utilities, so a theme's views can say
                // `bg-surface` instead of hard-coding a slate that the next
                // theme would have to override everywhere.
                surface: {
                    DEFAULT: 'var(--surface)',
                    muted: 'var(--surface-muted)',
                },
                ink: {
                    DEFAULT: 'var(--ink)',
                    muted: 'var(--ink-muted)',
                },
                line: 'var(--line)',
            },
            borderRadius: {
                token: 'var(--radius)',
                'token-lg': 'var(--radius-lg)',
                button: 'var(--button-radius)',
            },
            maxWidth: {
                container: 'var(--container)',
            },
        },
    },

    plugins: [forms],
};
