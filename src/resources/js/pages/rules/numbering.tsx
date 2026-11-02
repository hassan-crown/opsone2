import { Head } from '@inertiajs/react';
import { useState } from 'react';
import NumberFormatController from '@/actions/App/Http/Controllers/Rules/NumberFormatController';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { numbering as numberingRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

type Format = {
    sequence: string;
    prefix: string;
    year_mode: 'gregorian' | 'hijri' | 'none';
    per_branch: boolean;
    pad: number;
    start_at: number | null;
    branchable: boolean;
    example: string;
};

type Props = { formats: Format[]; fixed: string[]; canEdit: boolean };

/** Settings › numbering (ADR-0037): each sequence's format; tax invoices stay fixed (ZATCA). */
export default function Numbering({ formats, fixed, canEdit }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('rules.numbering.title')} />
            <PageHeader
                title={t('rules.numbering.title')}
                description={t('rules.numbering.description')}
            />
            <RulesNav current="numbering" canEdit={canEdit} />
            <p className="mb-4 text-caption text-weak">
                {t('rules.numbering.fixed', { prefixes: fixed.join(', ') })}
            </p>
            <ul className="grid max-w-4xl gap-3" data-test="number-formats">
                {formats.map((f) => (
                    <FormatRow key={f.sequence} format={f} canEdit={canEdit} />
                ))}
            </ul>
        </>
    );
}

function FormatRow({ format, canEdit }: { format: Format; canEdit: boolean }) {
    const t = useT();
    const write = useRulesWrite();
    const [form, setForm] = useState(format);

    return (
        <li className="rounded-lg border border-stroke-weak p-4">
            <fieldset
                disabled={!canEdit}
                className="m-0 grid min-w-0 gap-3 border-0 p-0 sm:grid-cols-4"
            >
                <legend className="mb-2 flex flex-wrap gap-2 text-label">
                    {t(
                        `rules.numbering.seq.${format.sequence}` as TranslationKey,
                    )}
                    <span className="text-weak">
                        {t('rules.numbering.example', { number: '' })}
                        <bdi dir="ltr">{format.example}</bdi>
                    </span>
                </legend>
                <TextField
                    label={t('rules.numbering.prefix')}
                    dir="ltr"
                    maxLength={8}
                    value={form.prefix}
                    error={write.errors.prefix}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            prefix: e.target.value.toUpperCase(),
                        })
                    }
                />
                <SelectField
                    label={t('rules.numbering.year')}
                    value={form.year_mode}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            year_mode: e.target.value as Format['year_mode'],
                        })
                    }
                    options={(['gregorian', 'hijri', 'none'] as const).map(
                        (m) => ({
                            value: m,
                            label: t(
                                `rules.numbering.year.${m}` as TranslationKey,
                            ),
                        }),
                    )}
                />
                <TextField
                    label={t('rules.numbering.pad')}
                    type="number"
                    dir="ltr"
                    min={3}
                    max={8}
                    value={String(form.pad)}
                    error={write.errors.pad}
                    onChange={(e) =>
                        setForm({ ...form, pad: Number(e.target.value) })
                    }
                />
                <TextField
                    label={t('rules.numbering.start_at')}
                    hint={t('rules.numbering.start_hint')}
                    type="number"
                    dir="ltr"
                    min={1}
                    value={form.start_at === null ? '' : String(form.start_at)}
                    error={write.errors.start_at}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            start_at:
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                        })
                    }
                />
                {format.branchable && (
                    <CheckboxField
                        className="sm:col-span-3"
                        label={t('rules.numbering.per_branch')}
                        checked={form.per_branch}
                        error={write.errors.per_branch}
                        onChange={(e) =>
                            setForm({ ...form, per_branch: e.target.checked })
                        }
                    />
                )}
                {canEdit && (
                    <div className="flex items-end">
                        <Button
                            type="button"
                            size="sm"
                            loading={write.busy}
                            onClick={() =>
                                write.send(
                                    'put',
                                    NumberFormatController.update().url,
                                    {
                                        sequence: form.sequence,
                                        prefix: form.prefix,
                                        year_mode: form.year_mode,
                                        per_branch: form.per_branch,
                                        pad: form.pad,
                                        start_at: form.start_at,
                                    },
                                )
                            }
                        >
                            {t('rules.save')}
                        </Button>
                    </div>
                )}
            </fieldset>
        </li>
    );
}

Numbering.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.numbering.title', href: numberingRoute() },
    ],
};
