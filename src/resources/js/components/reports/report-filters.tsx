import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { BranchOption, ReportFilters } from '@/types/reports';

export type ReportFiltersProps = {
    /** Page URL the filters are applied to (GET, state preserved). */
    url: string;
    filters: ReportFilters;
    branches: BranchOption[];
};

/** Period + branch filter bar; stacks on phones. Apply = GET with the chosen range and branches. */
export function ReportFiltersBar({
    url,
    filters,
    branches,
}: ReportFiltersProps) {
    const t = useT();
    const { errors } = usePage().props;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [ids, setIds] = useState<number[]>(filters.branch_ids);

    const toggle = (id: number, on: boolean) =>
        setIds((current) =>
            on ? [...current, id] : current.filter((x) => x !== id),
        );

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            url,
            { from, to, ...(ids.length > 0 ? { branch_ids: ids } : {}) },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <Card className="mb-6">
            <form
                onSubmit={apply}
                className="flex flex-col gap-4"
                data-test="report-filters"
            >
                <div className="grid gap-4 sm:grid-cols-2 lg:max-w-xl">
                    <TextField
                        type="date"
                        label={t('reports.filters.from')}
                        value={from}
                        max={to || undefined}
                        error={errors.from}
                        onChange={(e) => setFrom(e.target.value)}
                    />
                    <TextField
                        type="date"
                        label={t('reports.filters.to')}
                        value={to}
                        min={from || undefined}
                        error={errors.to}
                        onChange={(e) => setTo(e.target.value)}
                    />
                </div>
                {branches.length > 1 && (
                    <fieldset className="m-0 min-w-0 border-0 p-0">
                        <legend className="mb-1 p-0 text-label text-strong">
                            {t('reports.filters.branches')}
                        </legend>
                        <div className="flex flex-wrap gap-x-6">
                            {branches.map((b) => (
                                <CheckboxField
                                    key={b.id}
                                    label={b.name}
                                    checked={ids.includes(b.id)}
                                    onChange={(e) =>
                                        toggle(b.id, e.target.checked)
                                    }
                                />
                            ))}
                        </div>
                        {ids.length === 0 && (
                            <p className="text-caption text-weak">
                                {t('reports.filters.all_branches')}
                            </p>
                        )}
                        {errors.branch_ids && (
                            <p className="text-caption text-danger-text">
                                {errors.branch_ids}
                            </p>
                        )}
                    </fieldset>
                )}
                <div>
                    <Button type="submit" className="max-sm:w-full">
                        {t('reports.filters.apply')}
                    </Button>
                </div>
            </form>
        </Card>
    );
}
