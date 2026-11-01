import { Link } from '@inertiajs/react';

/**
 * One dashboard figure: label, the number (never wrapped from its unit), an optional hint, and the whole tile is the
 * drill-down link. Money keeps the unit small beside the number so a seven-digit amount fits a phone column.
 */
export function KpiTile({
    label,
    value,
    unit,
    hint,
    href,
    tone,
}: {
    label: string;
    value: string;
    unit?: string;
    hint?: string;
    href: string;
    tone?: 'danger';
}) {
    return (
        <Link
            href={href}
            className="flex min-w-0 flex-col gap-1 rounded-lg border border-stroke-weak bg-surface p-4 text-inherit no-underline hover:border-stroke-control focus-visible:outline-2 focus-visible:outline-offset-2"
        >
            <span className="text-label text-weak">{label}</span>
            <span
                className={`m-0 flex flex-wrap items-baseline gap-x-1.5 text-title tabular-nums ${tone === 'danger' ? 'text-danger-text' : 'text-strong'}`}
            >
                <bdi className="whitespace-nowrap">{value}</bdi>
                {unit && (
                    <span className="text-caption font-normal text-weak">
                        {unit}
                    </span>
                )}
            </span>
            {hint && <span className="text-caption text-weak">{hint}</span>}
        </Link>
    );
}
