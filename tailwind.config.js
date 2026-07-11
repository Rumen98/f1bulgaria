import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                /*
                 * Display шрифт за заглавия, stat числа и брандови елементи —
                 * Exo 2 има реални 700-900 тегла и пълна кирилица (Russo One
                 * е само 400, Oswald спира на 700 — и двата връщат faux-bold).
                 */
                display: ['"Exo 2"', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                /*
                 * Брандово червено: официалното F1 червено (#e10600) замества
                 * стоковото red-600 (#dc2626), за да е един червеният акцент в
                 * целия сайт (theme-color, hero glow и SVG art вече ползват
                 * #e10600 като hardcoded стойност). 400/500/700 са изведени от
                 * него, за да пазят hover/active отношенията на скалата.
                 */
                red: {
                    400: '#ff6a5e',
                    500: '#ff2d1f',
                    600: '#e10600',
                    700: '#b30500',
                },
                /*
                 * zinc-500 повдигнат от #71717a: оригиналът е ~4.0:1 върху
                 * #0a0a0a и пада под WCAG AA (4.5:1) за дребния текст, на който
                 * се ползва масово. Тази стойност дава ~5:1, а остава видимо
                 * по-тъмна от zinc-400 (йерархията се запазва).
                 */
                zinc: {
                    500: '#83838d',
                },
            },
        },
    },

    plugins: [forms],
};
