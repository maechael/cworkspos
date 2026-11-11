// Maps a color scheme (schemes.js) onto the CSS variables read by .pos-theme (resources/css/app.css),
// so the styles, dialogs, inputs and the sidebar around them all follow the scheme.
import { SCHEMES } from './schemes';

export const themeClass = 'pos-theme';

export function getScheme(key) {
    return SCHEMES[key] || Object.values(SCHEMES)[0];
}

export function themeStyle(k) {
    return {
        '--pos-brand': k.brand,
        '--pos-brand-dark': k.brandDark,
        '--pos-on-brand': k.onBrand,
        '--pos-soft': k.soft,
        '--pos-soft-ink': k.softInk,
        '--pos-bg': k.bg,
        '--pos-surface': k.surface,
        '--pos-panel': k.panel,
        '--pos-heading': k.heading,
        '--pos-text': k.text,
        '--pos-muted': k.muted,
        '--pos-border': k.border,
        '--pos-side': k.side,
        '--pos-side-text': k.sideText,
        '--pos-side-border': k.sideBorder,
        '--pos-side-active': k.sideActive,
        '--pos-side-active-text': k.sideActiveText,
    };
}
