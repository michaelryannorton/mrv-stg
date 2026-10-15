/**
 * What production's chart pages share (public_html/js/city.js, garden.js and air-quality.js each repeat it):
 * the validated categorical palette (8 hues in a fixed order, each from a different hue family; deliberately not
 * affected by the seasonal theme, so chart data colors stay perceptually validated whatever the mood color), the
 * muted gray for "last year" reference lines, and the three Chart.js defaults. See logs/2026 0723 0030 Phase 1e
 * Palette And Geolocation.md and logs/2026 0920 0343 Chart And Graph Audit Proposal.md for the color rules.
 */
import type { Chart } from 'chart.js';

export const PALETTE = {
    terracotta: '#C1592F',
    indigo: '#3F4E96',
    teal: '#00897B',
    wine: '#8B2E4E',
    gold: '#B8860B',
    sky: '#2A6FB0',
    plum: '#7A3080',
    rust: '#963F30',
    dusk: '#55504A',
} as const;

/** The eight series colors, in order. `dusk` is axis and text ink, not a series color. */
export const CATEGORICAL: string[] = [
    PALETTE.terracotta,
    PALETTE.indigo,
    PALETTE.teal,
    PALETTE.wine,
    PALETTE.gold,
    PALETTE.sky,
    PALETTE.plum,
    PALETTE.rust,
];

/** Muted gray for "last year" reference lines, deliberately outside the categorical set. */
export const NEUTRAL_COMPARE = '#9A9488';

/** Set the Chart.js defaults every production chart page sets, once per Chart class. */
export function applyChartDefaults(ChartClass: typeof Chart): void {
    ChartClass.defaults.font.family = "'Inter', sans-serif";
    ChartClass.defaults.color = PALETTE.dusk;
    ChartClass.defaults.plugins.legend.labels.usePointStyle = true;
}
