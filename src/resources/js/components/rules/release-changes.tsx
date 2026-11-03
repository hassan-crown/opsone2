import { Badge } from '@/components/ui/badge';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type ReleaseChange = {
    section: string;
    item: string | null;
    change: 'added' | 'removed' | 'changed';
    fields: { field: string; before: string | null; after: string | null }[];
};

const CHANGE_VARIANT = {
    added: 'success',
    removed: 'danger',
    changed: 'info',
} as const;

/** A label from the dictionary, or the raw key when the dictionary has none (field names of the snapshot). */
function useLabel() {
    const t = useT();

    return (key: string, fallback: string) => {
        const text = t(key as TranslationKey);

        return text === key ? fallback : text;
    };
}

/** The readable change log between two configuration snapshots (ADR-0040), grouped by section. */
export function ReleaseChanges({
    changes,
    empty,
}: {
    changes: ReleaseChange[];
    empty: string;
}) {
    const t = useT();
    const label = useLabel();
    const { dir } = useLocale();

    if (changes.length === 0) {
        return <p className="text-weak">{empty}</p>;
    }

    return (
        <ul className="grid gap-2" data-test="release-changes">
            {changes.map((c, i) => (
                <li
                    key={`${c.section}-${c.item ?? ''}-${i}`}
                    className="rounded-lg border border-stroke-weak p-3"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={CHANGE_VARIANT[c.change]}>
                            {t(
                                `rules.releases.change.${c.change}` as TranslationKey,
                            )}
                        </Badge>
                        <span className="text-label text-strong">
                            {label(
                                `rules.releases.section.${c.section}`,
                                c.section,
                            )}
                        </span>
                        {c.item !== null && (
                            <bdi className="text-caption text-weak" dir="ltr">
                                {c.item}
                            </bdi>
                        )}
                    </div>
                    {c.fields.length > 0 && (
                        <dl className="mt-2 grid gap-1 text-caption">
                            {c.fields.map((f) => (
                                <div
                                    key={f.field}
                                    className="grid gap-1 sm:grid-cols-[10rem_1fr]"
                                >
                                    <dt className="text-weak">
                                        {label(
                                            `rules.releases.field.${f.field}`,
                                            f.field,
                                        )}
                                    </dt>
                                    <dd className="m-0 min-w-0 break-words">
                                        {f.before !== null && (
                                            <del className="text-danger-text">
                                                {f.before}
                                            </del>
                                        )}
                                        {f.before !== null &&
                                            f.after !== null && (
                                                <span aria-hidden>
                                                    {dir === 'rtl'
                                                        ? ' ← '
                                                        : ' → '}
                                                </span>
                                            )}
                                        {f.after !== null && (
                                            <ins className="text-success-text no-underline">
                                                {f.after}
                                            </ins>
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** «3 plans · 1 reminders» from a per-section count. */
export function ChangeSummary({
    summary,
}: {
    summary: Record<string, number>;
}) {
    const label = useLabel();
    const entries = Object.entries(summary);
    if (entries.length === 0) {
        return <span className="text-weak">—</span>;
    }

    return (
        <span>
            {entries
                .map(
                    ([section, count]) =>
                        `${label(`rules.releases.section.${section}`, section)} (${count})`,
                )
                .join(' · ')}
        </span>
    );
}
