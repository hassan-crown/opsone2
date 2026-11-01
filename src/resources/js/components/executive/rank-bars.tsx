import { Link } from '@inertiajs/react';

export type RankRow = {
    key: string;
    label: string;
    /** Bar length basis. */
    value: number;
    /** Text shown at the end of the row (already formatted). */
    text: string;
    sub?: string;
    href?: string;
    /** 1–5: which --chart-N colour; defaults to the first. */
    color?: number;
};

/**
 * Horizontal ranking bars in plain HTML: they follow the page direction by themselves (RTL), reflow at 390px, and
 * every row carries its value as text and, when it has one, a drill-down link. Nothing relies on colour alone.
 */
export function RankBars({ rows, label }: { rows: RankRow[]; label: string }) {
    const max = Math.max(1, ...rows.map((r) => Math.abs(r.value)));

    return (
        <ul
            className="m-0 flex list-none flex-col gap-3 p-0"
            aria-label={label}
        >
            {rows.map((row) => {
                const body = (
                    <>
                        <span className="flex items-baseline justify-between gap-3">
                            <span className="min-w-0 truncate text-body text-strong">
                                {row.label}
                            </span>
                            <span className="shrink-0 text-body font-semibold text-strong tabular-nums">
                                {row.text}
                            </span>
                        </span>
                        <span
                            className="bg-surface-weak mt-1 block h-2 w-full rounded-full"
                            aria-hidden
                        >
                            <span
                                className="block h-2 rounded-full"
                                style={{
                                    width: `${Math.max(2, (Math.abs(row.value) / max) * 100)}%`,
                                    background: `var(--chart-${row.color ?? 1})`,
                                }}
                            />
                        </span>
                        {row.sub && (
                            <span className="mt-1 block text-caption text-weak">
                                {row.sub}
                            </span>
                        )}
                    </>
                );

                return (
                    <li key={row.key}>
                        {row.href ? (
                            <Link
                                href={row.href}
                                className="hover:bg-surface-weak -mx-2 block rounded-md px-2 py-1 focus-visible:outline-2 focus-visible:outline-offset-2"
                            >
                                {body}
                            </Link>
                        ) : (
                            <div className="px-0 py-1">{body}</div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
