import { Link, router } from '@inertiajs/react';
import { formatHalalas } from '@/components/reports/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TrendMonth } from '@/types/executive';

const W = 560;
const H = 250;
const TOP = 16;
const BOTTOM = 34;
const GROUP_PAD = 14;

/** 1,2,2.5,5 × 10ⁿ ceiling so the grid lines are round numbers. */
function niceMax(value: number): number {
    if (value <= 0) {
        return 100;
    }
    const exp = 10 ** Math.floor(Math.log10(value));
    const f = value / exp;
    const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;

    return nice * exp;
}

/**
 * Sales booked vs collected per month: grouped columns, the oldest month at the start edge (right in RTL). Each month
 * is a link to that month's sales report; the numbers are also in a native table for assistive tech.
 */
export function TrendChart({ months }: { months: TrendMonth[] }) {
    const t = useT();
    const { locale, dir } = useLocale();
    const rtl = dir === 'rtl';
    const unit = t('reports.money_unit');
    const compact = new Intl.NumberFormat(
        locale === 'ar' ? 'ar-SA-u-nu-latn' : 'en',
        {
            notation: 'compact',
            maximumFractionDigits: 1,
        },
    );
    const monthName = (m: string) =>
        new Intl.DateTimeFormat(locale === 'ar' ? 'ar-SA-u-nu-latn' : 'en', {
            month: 'short',
        }).format(new Date(`${m}-01T00:00:00`));

    const maxValue = niceMax(
        Math.max(
            0,
            ...months.flatMap((m) => [m.sales_minor, m.collected_minor]),
        ),
    );
    const plotH = H - TOP - BOTTOM;
    const slot = W / months.length;
    const bar = (slot - GROUP_PAD * 2) / 2 - 2;
    const px = (x: number) => (rtl ? W - x : x);
    const y = (v: number) => TOP + plotH - (v / maxValue) * plotH;
    const grid = [0, 0.25, 0.5, 0.75, 1];

    return (
        <figure
            className="m-0 mx-auto w-full max-w-[720px]"
            data-test="chart-trend"
        >
            <svg
                viewBox={`0 0 ${W} ${H}`}
                width="100%"
                role="img"
                aria-label={`${t('executive.chart.trend')}: ${months
                    .map(
                        (m) =>
                            `${monthName(m.month)} ${formatHalalas(m.sales_minor)} / ${formatHalalas(m.collected_minor)} ${unit}`,
                    )
                    .join('، ')}`}
                className="block h-auto max-w-full text-strong"
            >
                {grid.map((g) => (
                    <g key={g}>
                        <line
                            x1={0}
                            x2={W}
                            y1={y(maxValue * g)}
                            y2={y(maxValue * g)}
                            className="stroke-stroke-weak"
                            strokeWidth={1}
                        />
                        <text
                            x={px(2)}
                            y={y(maxValue * g) - 3}
                            fontSize={11}
                            fill="currentColor"
                            opacity={0.65}
                            direction="ltr"
                            textAnchor={rtl ? 'end' : 'start'}
                        >
                            {compact.format((maxValue * g) / 100)}
                        </text>
                    </g>
                ))}
                {months.map((m, i) => {
                    const x0 = i * slot + GROUP_PAD;
                    const series = [
                        { v: m.sales_minor, c: 'var(--chart-1)' },
                        { v: m.collected_minor, c: 'var(--chart-2)' },
                    ];

                    return (
                        <a
                            key={m.month}
                            href={m.href}
                            aria-label={monthName(m.month)}
                            className="cursor-pointer outline-offset-2 hover:opacity-80"
                            onClick={(e) => {
                                e.preventDefault();
                                router.visit(m.href);
                            }}
                        >
                            <rect
                                x={Math.min(px(i * slot), px(i * slot + slot))}
                                y={0}
                                width={slot}
                                height={H}
                                fill="transparent"
                            />
                            {series.map((s, k) => {
                                const left = x0 + k * (bar + 4);

                                return (
                                    <rect
                                        key={k}
                                        x={Math.min(px(left), px(left + bar))}
                                        y={y(s.v)}
                                        width={bar}
                                        height={Math.max(
                                            0,
                                            TOP + plotH - y(s.v),
                                        )}
                                        rx={3}
                                        fill={s.c}
                                    />
                                );
                            })}
                            <text
                                x={px(i * slot + slot / 2)}
                                y={H - 12}
                                fontSize={14}
                                fill="currentColor"
                                textAnchor="middle"
                            >
                                {monthName(m.month)}
                            </text>
                        </a>
                    );
                })}
            </svg>
            <ul
                className="m-0 mt-2 flex list-none flex-wrap gap-x-5 gap-y-1 p-0 text-caption text-weak"
                aria-hidden
            >
                {[
                    ['var(--chart-1)', 'executive.chart.sales'],
                    ['var(--chart-2)', 'executive.chart.collected'],
                ].map(([c, k]) => (
                    <li key={k} className="flex items-center gap-2">
                        <span
                            className="inline-block size-3 rounded-sm"
                            style={{ background: c }}
                        />
                        {t(k as 'executive.chart.sales')}
                    </li>
                ))}
            </ul>
            <details className="mt-3 text-body">
                <summary className="cursor-pointer text-link">
                    {t('executive.table')}
                </summary>
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full border-collapse text-start text-body">
                        <caption className="sr-only">
                            {t('executive.chart.trend')}
                        </caption>
                        <tbody>
                            {months.map((m) => (
                                <tr key={m.month}>
                                    <th
                                        scope="row"
                                        className="border-b border-stroke-weak px-3 py-2 text-start font-normal"
                                    >
                                        <Link
                                            href={m.href}
                                            className="text-link"
                                        >
                                            {m.month}
                                        </Link>
                                    </th>
                                    <td className="border-b border-stroke-weak px-3 py-2 text-end tabular-nums">
                                        {formatHalalas(m.sales_minor)}
                                    </td>
                                    <td className="border-b border-stroke-weak px-3 py-2 text-end tabular-nums">
                                        {formatHalalas(m.collected_minor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </figure>
    );
}
