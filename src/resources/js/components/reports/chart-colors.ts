/**
 * The ONE place for chart colours (Q-52 b). Values are CSS variable references defined for light and dark in
 * resources/css/app.css; swapping the designer's palette later only touches those variables.
 */
export const CHART_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
] as const;

export function chartColor(index: number): string {
    const size = CHART_COLORS.length;

    return CHART_COLORS[((index % size) + size) % size];
}
