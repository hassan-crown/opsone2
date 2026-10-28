import { useLocale } from '@/lib/i18n';
import type { ChartPoint } from '@/types/reports';
import { ChartDataTable } from './chart-data-table';
import { chartColor } from './chart-colors';

export type LineChartProps = {
    title: string;
    points: ChartPoint[];
    labels: string[];
    formatValue: (value: number) => string;
    formatValueWithUnit: (value: number) => string;
    colorIndex?: number;
};

const WIDTH = 520;
const HEIGHT = 240;
const AXIS_W = 64;
const TOP = 16;
const BOTTOM = 34;
const SIDE = 12;
const TICKS = 4;

/**
 * Time-ordered line chart. The time axis runs with the reading direction (right to left in RTL); y labels sit on
 * the start side. First, last, highest and lowest points are labelled with their values (not colour-only).
 */
export function LineChart({
    title,
    points,
    labels,
    formatValue,
    formatValueWithUnit,
    colorIndex = 0,
}: LineChartProps) {
    const { dir } = useLocale();
    const rtl = dir === 'rtl';
    const values = points.map((p) => p.value);
    const min = Math.min(0, ...values);
    const max = Math.max(0, ...values);
    const span = max - min || 1;
    const plotW = WIDTH - AXIS_W - SIDE * 2;
    const plotH = HEIGHT - TOP - BOTTOM;
    const px = (x: number) => (rtl ? WIDTH - x : x);
    const anchorStart = rtl ? 'end' : 'start';
    const anchorEnd = rtl ? 'start' : 'end';
    const n = points.length;
    const xAt = (i: number) =>
        AXIS_W + SIDE + (n <= 1 ? plotW / 2 : (i / (n - 1)) * plotW);
    const yAt = (v: number) => TOP + (1 - (v - min) / span) * plotH;
    const rows = points.map((p, i) => ({
        label: labels[i] ?? p.label,
        value: formatValueWithUnit(p.value),
    }));
    const maxI = values.indexOf(Math.max(...values));
    const minI = values.indexOf(Math.min(...values));
    const marked = new Set([0, n - 1, maxI, minI]);
    const ticks = Array.from(
        { length: TICKS + 1 },
        (_, i) => min + (span * i) / TICKS,
    );
    const xLabelIdx = Array.from(new Set([0, Math.floor((n - 1) / 2), n - 1]));
    const path = points
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${px(xAt(i))},${yAt(p.value)}`)
        .join(' ');

    return (
        <figure className="m-0">
            <svg
                viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                width="100%"
                role="img"
                aria-label={`${title}: ${rows.map((r) => `${r.label} ${r.value}`).join('، ')}`}
                className="block h-auto max-w-full text-strong"
            >
                {ticks.map((tick, i) => (
                    <g key={i}>
                        <line
                            x1={px(AXIS_W)}
                            x2={px(WIDTH - SIDE)}
                            y1={yAt(tick)}
                            y2={yAt(tick)}
                            className="stroke-stroke-weak"
                            strokeWidth={1}
                        />
                        <text
                            x={px(AXIS_W - 6)}
                            y={yAt(tick)}
                            dominantBaseline="middle"
                            textAnchor={anchorEnd}
                            fontSize={12}
                            fill="currentColor"
                            direction="ltr"
                            className="tabular-nums"
                        >
                            {formatValue(Math.round(tick))}
                        </text>
                    </g>
                ))}
                <path
                    d={path}
                    fill="none"
                    stroke={chartColor(colorIndex)}
                    strokeWidth={2.5}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                />
                {points.map((p, i) => (
                    <g key={`${p.label}-${i}`}>
                        <circle
                            cx={px(xAt(i))}
                            cy={yAt(p.value)}
                            r={marked.has(i) ? 4 : 2.5}
                            fill={chartColor(colorIndex)}
                        >
                            <title>{`${labels[i] ?? p.label}: ${formatValueWithUnit(p.value)}`}</title>
                        </circle>
                        {marked.has(i) && (
                            <text
                                x={px(xAt(i))}
                                y={yAt(p.value) - 9}
                                textAnchor="middle"
                                fontSize={12}
                                fill="currentColor"
                                direction="ltr"
                                className="tabular-nums"
                            >
                                {formatValue(p.value)}
                            </text>
                        )}
                    </g>
                ))}
                {xLabelIdx.map((i, k) => (
                    <text
                        key={`${i}-${k}`}
                        x={px(xAt(i))}
                        y={HEIGHT - 10}
                        textAnchor={
                            i === 0
                                ? anchorStart
                                : i === n - 1
                                  ? anchorEnd
                                  : 'middle'
                        }
                        fontSize={12}
                        fill="currentColor"
                    >
                        {labels[i] ?? points[i]?.label}
                    </text>
                ))}
            </svg>
            <ChartDataTable title={title} rows={rows} />
        </figure>
    );
}
