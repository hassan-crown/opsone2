import { useLocale } from '@/lib/i18n';
import type { ChartPoint } from '@/types/reports';
import { ChartDataTable } from './chart-data-table';
import { chartColor } from './chart-colors';

export type BarChartProps = {
    title: string;
    points: ChartPoint[];
    /** Resolved label per point (enum labels already translated). */
    labels: string[];
    formatValue: (value: number) => string;
    formatValueWithUnit: (value: number) => string;
    colorIndex?: number;
};

const WIDTH = 520;
const ROW = 34;
const BAR = 18;
const LABEL_W = 150;
const VALUE_W = 80;
const PAD = 4;
const MAX_LABEL = 22;

function clip(text: string): string {
    return text.length > MAX_LABEL ? `${text.slice(0, MAX_LABEL - 1)}…` : text;
}

/**
 * Horizontal bar chart: categories run top to bottom with their label on the start side and the bar growing from
 * the start edge (mirrored in RTL). Every bar carries its value as text, so nothing relies on colour alone.
 */
export function BarChart({
    title,
    points,
    labels,
    formatValue,
    formatValueWithUnit,
    colorIndex = 0,
}: BarChartProps) {
    const { dir } = useLocale();
    const rtl = dir === 'rtl';
    const values = points.map((p) => p.value);
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min || 1;
    const plotW = WIDTH - LABEL_W - VALUE_W - PAD * 2;
    const zeroX = (-min / span) * plotW;
    const height = points.length * ROW + PAD * 2;
    // Logical x (0 = start edge) → physical x.
    const px = (x: number) => (rtl ? WIDTH - x : x);
    const anchorStart = rtl ? 'end' : 'start';
    const anchorEnd = rtl ? 'start' : 'end';
    const rows = points.map((p, i) => ({
        label: labels[i] ?? p.label,
        value: formatValueWithUnit(p.value),
    }));

    return (
        <figure className="m-0">
            <svg
                viewBox={`0 0 ${WIDTH} ${height}`}
                width="100%"
                role="img"
                aria-label={`${title}: ${rows.map((r) => `${r.label} ${r.value}`).join('، ')}`}
                className="block h-auto max-w-full text-strong"
            >
                <line
                    x1={px(LABEL_W + zeroX)}
                    x2={px(LABEL_W + zeroX)}
                    y1={0}
                    y2={height}
                    className="stroke-stroke-control"
                    strokeWidth={1}
                />
                {points.map((p, i) => {
                    const y = PAD + i * ROW;
                    const barW = (Math.abs(p.value) / span) * plotW;
                    const startX =
                        LABEL_W + (p.value < 0 ? zeroX - barW : zeroX);
                    const left = px(startX);
                    const right = px(startX + barW);
                    const valueX = p.value < 0 ? startX - 6 : startX + barW + 6;
                    const valueAnchor = p.value < 0 ? anchorEnd : anchorStart;
                    const label = labels[i] ?? p.label;

                    return (
                        <g key={`${p.label}-${i}`}>
                            <text
                                x={px(0)}
                                y={y + ROW / 2}
                                dominantBaseline="middle"
                                // SVG anchors follow the text direction: `start` is already the right edge in RTL.
                                textAnchor="start"
                                fontSize={13}
                                fill="currentColor"
                            >
                                <title>{label}</title>
                                {clip(label)}
                            </text>
                            <rect
                                x={Math.min(left, right)}
                                y={y + (ROW - BAR) / 2}
                                width={Math.max(barW, p.value === 0 ? 0 : 1)}
                                height={BAR}
                                rx={3}
                                fill={chartColor(colorIndex)}
                            />
                            <text
                                x={px(valueX)}
                                y={y + ROW / 2}
                                dominantBaseline="middle"
                                textAnchor={valueAnchor}
                                fontSize={13}
                                fill="currentColor"
                                direction="ltr"
                                className="tabular-nums"
                            >
                                {formatValue(p.value)}
                            </text>
                        </g>
                    );
                })}
            </svg>
            <ChartDataTable title={title} rows={rows} />
        </figure>
    );
}
