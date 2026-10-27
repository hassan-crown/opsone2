import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type FilterSelect = {
    name: string;
    label: string;
    options: { value: string; label: string }[];
};

/** GET filters: the server re-applies the user's scope, so a filter can only narrow the list. */
export function FilterBar({
    url,
    initial,
    searchLabel,
    selects,
    extra,
}: {
    url: string;
    initial: Record<string, string>;
    searchLabel: TranslationKey;
    selects: FilterSelect[];
    /** Values that stay in the query but have no control here (e.g. the status tab). */
    extra?: Record<string, string>;
}) {
    const t = useT();
    const [values, setValues] = useState(initial);

    const apply = (next: Record<string, string>) =>
        router.get(
            url,
            Object.fromEntries(
                Object.entries({ ...extra, ...next }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply(values);
    };

    const clear = () => {
        const empty = Object.fromEntries(
            Object.keys(values).map((k) => [k, '']),
        );
        setValues(empty);
        apply(empty);
    };

    return (
        <form
            onSubmit={submit}
            className="mb-4 grid gap-3 rounded-lg border border-stroke-weak bg-card p-4 sm:grid-cols-2 lg:grid-cols-[2fr_repeat(3,1fr)_auto]"
            role="search"
            data-test="filter-bar"
        >
            <Field label={t(searchLabel)}>
                {(control) => (
                    <div className="relative">
                        <Icon
                            icon={Search}
                            className="pointer-events-none absolute inset-s-3 top-1/2 -translate-y-1/2 text-weak"
                        />
                        <Input
                            {...control}
                            name="q"
                            value={values.q ?? ''}
                            onChange={(e) =>
                                setValues({ ...values, q: e.target.value })
                            }
                            className="ps-10"
                        />
                    </div>
                )}
            </Field>
            {selects.map((select) => (
                <Field key={select.name} label={select.label}>
                    {(control) => (
                        <select
                            {...control}
                            name={select.name}
                            value={values[select.name] ?? ''}
                            onChange={(e) =>
                                setValues({
                                    ...values,
                                    [select.name]: e.target.value,
                                })
                            }
                            className="field-control w-full cursor-pointer text-control"
                        >
                            {select.options.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    )}
                </Field>
            ))}
            <div className="flex items-end gap-2">
                <Button type="submit">{t('customers.filter.apply')}</Button>
                <Button type="button" variant="ghost" onClick={clear}>
                    {t('customers.filter.clear')}
                </Button>
            </div>
        </form>
    );
}
