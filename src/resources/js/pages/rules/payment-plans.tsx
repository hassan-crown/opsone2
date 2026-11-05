import { Head } from '@inertiajs/react';
import { useState } from 'react';
import PaymentPlanController from '@/actions/App/Http/Controllers/Rules/PaymentPlanController';
import Heading from '@/components/heading';
import {
    CheckboxField,
    SelectField,
    TextField,
} from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { StickyActions } from '@/components/opsone/sticky-actions';
import { errorOf, RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { plans as plansRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

type Basis = 'percent' | 'amount' | 'rest';

type Line = {
    key: string;
    name: { ar: string; en: string | null };
    basis: Basis;
    value: string | null;
    event: string;
    stage_key: string | null;
    offset_days: number;
    grace_days: number;
};

type Plan = {
    id: number;
    key: string;
    name: string;
    name_i18n: { ar: string | null; en: string | null };
    is_default: boolean;
    archived: boolean;
    lock_version: number;
    lines: Line[];
};

type Template = { key: string; name: string; lines: Line[] };

type Props = {
    plans: Plan[];
    templates: Template[];
    triggers: string[];
    stages: { key: string; name: string }[];
    gateReferences: string[];
    limits: { lines: number; plans: number; offset: number; grace: number };
    canEdit: boolean;
};

type Draft = {
    id: number | null;
    lock_version: number | null;
    key: string;
    name: { ar: string; en: string };
    is_default: boolean;
    lines: Line[];
};

/** Percent text («33.33») to basis points, or null when it is not a valid share. */
function toBp(text: string | null): number | null {
    const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec((text ?? '').trim());

    return match
        ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
        : null;
}

function blankLine(index: number): Line {
    return {
        key: index === 0 ? 'first' : `due_${index + 1}`,
        name: { ar: '', en: null },
        basis: 'percent',
        value: '',
        event: 'agreement',
        stage_key: null,
        offset_days: 0,
        grace_days: 0,
    };
}

/**
 * Settings › payment plans (ADR-0037): a plan's payments with their share, trigger, offset and grace. The live total
 * must make 100% (or end with «rest»); the server re-checks every rule, including the flow's gate references.
 */
export default function PaymentPlans({
    plans,
    templates,
    triggers,
    stages,
    gateReferences,
    limits,
    canEdit,
}: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [draft, setDraft] = useState<Draft | null>(null);

    const edit = (plan: Plan) =>
        setDraft({
            id: plan.id,
            lock_version: plan.lock_version,
            key: plan.key,
            name: {
                ar: plan.name_i18n.ar ?? '',
                en: plan.name_i18n.en ?? '',
            },
            is_default: plan.is_default,
            lines: plan.lines.map((l) => ({ ...l })),
        });
    const startFrom = (template: Template | null) =>
        setDraft({
            id: null,
            lock_version: null,
            key: template?.key ?? '',
            name: { ar: template?.name ?? '', en: '' },
            is_default: plans.every((p) => p.archived),
            lines: template
                ? template.lines.map((l) => ({ ...l }))
                : [blankLine(0)],
        });

    const save = () => {
        if (!draft) {
            return;
        }
        const data = {
            key: draft.key,
            name: { ar: draft.name.ar, en: draft.name.en || null },
            is_default: draft.is_default,
            lock_version: draft.lock_version,
            lines: draft.lines.map((l) => ({
                ...l,
                value: l.basis === 'rest' ? null : l.value,
                stage_key: l.event === 'stage.started' ? l.stage_key : null,
            })),
        };
        const target =
            draft.id === null
                ? PaymentPlanController.store()
                : PaymentPlanController.update(draft.id);
        write.send(target.method as 'post' | 'put', target.url, data, () =>
            setDraft(null),
        );
    };

    return (
        <>
            <Head title={t('rules.plans.title')} />
            <PageHeader
                title={t('rules.plans.title')}
                description={t('rules.plans.description')}
            />
            <RulesNav current="plans" canEdit={canEdit} />

            {gateReferences.length > 0 && (
                <p className="mb-4 text-caption text-weak">
                    {t('rules.plans.gates', {
                        refs: gateReferences.join('، '),
                    })}
                </p>
            )}

            {plans.length === 0 && (
                <p className="mb-4 text-weak" data-test="plans-empty">
                    {t('rules.plans.empty')}
                </p>
            )}

            <ul className="mb-6 grid max-w-4xl gap-3" data-test="plans-list">
                {plans.map((plan) => (
                    <li
                        key={plan.id}
                        className="rounded-lg border border-stroke-weak p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <strong>{plan.name}</strong>
                                <span
                                    className="text-caption text-weak"
                                    dir="ltr"
                                >
                                    {plan.key}
                                </span>
                                {plan.is_default && (
                                    <Badge variant="brand">
                                        {t('rules.plans.default')}
                                    </Badge>
                                )}
                                {plan.archived && (
                                    <Badge>{t('rules.archived')}</Badge>
                                )}
                            </div>
                            {canEdit && !plan.archived && (
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => edit(plan)}
                                    >
                                        {t('rules.edit')}
                                    </Button>
                                    {!plan.is_default && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            title={t(
                                                'rules.plans.archive_confirm',
                                            )}
                                            onClick={() => {
                                                const target =
                                                    PaymentPlanController.archive(
                                                        plan.id,
                                                    );
                                                write.send(
                                                    'post',
                                                    target.url,
                                                    {},
                                                );
                                            }}
                                        >
                                            {t('rules.archive')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                        <ol className="mt-2 list-inside list-decimal text-weak">
                            {plan.lines.map((line) => (
                                <li key={line.key}>
                                    {line.name.ar} —{' '}
                                    {line.basis === 'rest'
                                        ? t('finance.basis.rest')
                                        : line.basis === 'percent'
                                          ? `${line.value}%`
                                          : line.value}{' '}
                                    · {triggerLabel(t, line, stages)}
                                </li>
                            ))}
                        </ol>
                    </li>
                ))}
            </ul>

            {canEdit && draft === null && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        onClick={() => startFrom(null)}
                        disabled={
                            plans.filter((p) => !p.archived).length >=
                            limits.plans
                        }
                        data-test="new-plan"
                    >
                        {t('rules.plans.new')}
                    </Button>
                    <span className="text-weak">
                        {t('rules.plans.from_template')}:
                    </span>
                    {templates.map((tpl) => (
                        <Button
                            key={tpl.key}
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => startFrom(tpl)}
                        >
                            {tpl.name}
                        </Button>
                    ))}
                </div>
            )}

            {draft !== null && (
                <PlanEditor
                    draft={draft}
                    setDraft={setDraft}
                    triggers={triggers}
                    stages={stages}
                    limits={limits}
                    errors={write.errors}
                    busy={write.busy}
                    onSave={save}
                    onCancel={() => {
                        setDraft(null);
                        write.setErrors({});
                    }}
                />
            )}
        </>
    );
}

function triggerLabel(
    t: ReturnType<typeof useT>,
    line: Line,
    stages: { key: string; name: string }[],
): string {
    const base =
        line.event === 'stage.started'
            ? t('esign.pdf.due.stage.started', {
                  stage:
                      stages.find((s) => s.key === line.stage_key)?.name ??
                      line.stage_key ??
                      '',
              })
            : t(`finance.event.${line.event}` as TranslationKey);

    return line.offset_days > 0
        ? t('finance.plans.offset', { when: base, days: line.offset_days })
        : base;
}

function PlanEditor({
    draft,
    setDraft,
    triggers,
    stages,
    limits,
    errors,
    busy,
    onSave,
    onCancel,
}: {
    draft: Draft;
    setDraft: (d: Draft) => void;
    triggers: string[];
    stages: { key: string; name: string }[];
    limits: Props['limits'];
    errors: Record<string, string>;
    busy: boolean;
    onSave: () => void;
    onCancel: () => void;
}) {
    const t = useT();
    const update = (index: number, patch: Partial<Line>) =>
        setDraft({
            ...draft,
            lines: draft.lines.map((l, i) =>
                i === index ? { ...l, ...patch } : l,
            ),
        });

    const percent = draft.lines.reduce(
        (sum, l) => sum + (l.basis === 'percent' ? (toBp(l.value) ?? 0) : 0),
        0,
    );
    const hasRest = draft.lines.some((l) => l.basis === 'rest');
    const hasAmount = draft.lines.some((l) => l.basis === 'amount');
    const ok = hasRest ? percent < 10000 : !hasAmount && percent === 10000;
    const totalText = (percent / 100).toString();

    return (
        <section
            className="max-w-4xl space-y-6 rounded-lg border border-stroke-weak p-4"
            data-test="plan-editor"
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <TextField
                    label={t('rules.key')}
                    hint={t('rules.key_hint')}
                    dir="ltr"
                    value={draft.key}
                    required
                    maxLength={30}
                    error={errors.key}
                    onChange={(e) =>
                        setDraft({ ...draft, key: e.target.value })
                    }
                />
                <TextField
                    label={t('rules.name_ar')}
                    value={draft.name.ar}
                    required
                    error={errors['name.ar']}
                    onChange={(e) =>
                        setDraft({
                            ...draft,
                            name: { ...draft.name, ar: e.target.value },
                        })
                    }
                />
                <TextField
                    label={t('rules.name_en')}
                    dir="ltr"
                    value={draft.name.en}
                    onChange={(e) =>
                        setDraft({
                            ...draft,
                            name: { ...draft.name, en: e.target.value },
                        })
                    }
                />
            </div>
            <CheckboxField
                label={t('rules.plans.make_default')}
                checked={draft.is_default}
                onChange={(e) =>
                    setDraft({ ...draft, is_default: e.target.checked })
                }
            />

            <Heading variant="small" title={t('rules.plans.lines')} />
            {draft.lines.map((line, i) => (
                <fieldset
                    key={i}
                    className="grid gap-3 rounded-md border border-stroke-weak p-3 sm:grid-cols-4"
                    data-test="plan-line"
                >
                    <legend className="px-1 text-label">
                        {t('rules.plans.line', { n: i + 1 })}
                    </legend>
                    <TextField
                        label={t('rules.key')}
                        dir="ltr"
                        value={line.key}
                        error={errors[`lines.${i}.key`]}
                        onChange={(e) => update(i, { key: e.target.value })}
                    />
                    <TextField
                        label={t('rules.name_ar')}
                        value={line.name.ar}
                        required
                        error={errors[`lines.${i}.name.ar`]}
                        onChange={(e) =>
                            update(i, {
                                name: { ...line.name, ar: e.target.value },
                            })
                        }
                    />
                    <SelectField
                        label={t('rules.plans.basis')}
                        value={line.basis}
                        onChange={(e) =>
                            update(i, { basis: e.target.value as Basis })
                        }
                        options={(['percent', 'amount', 'rest'] as const).map(
                            (b) => ({
                                value: b,
                                label: t(
                                    `finance.basis.${b}` as TranslationKey,
                                ),
                            }),
                        )}
                    />
                    <TextField
                        label={
                            line.basis === 'amount'
                                ? t('rules.plans.value_amount')
                                : t('rules.plans.value_percent')
                        }
                        dir="ltr"
                        inputMode="decimal"
                        value={line.value ?? ''}
                        disabled={line.basis === 'rest'}
                        error={errors[`lines.${i}.value`]}
                        onChange={(e) => update(i, { value: e.target.value })}
                    />
                    <SelectField
                        label={t('rules.plans.trigger')}
                        value={line.event}
                        error={errors[`lines.${i}.event`]}
                        onChange={(e) =>
                            update(i, {
                                event: e.target.value,
                                stage_key:
                                    e.target.value === 'stage.started'
                                        ? (stages[0]?.key ?? null)
                                        : null,
                            })
                        }
                        options={triggers.map((ev) => ({
                            value: ev,
                            label: t(`finance.event.${ev}` as TranslationKey),
                        }))}
                    />
                    {line.event === 'stage.started' ? (
                        <SelectField
                            label={t('rules.plans.stage')}
                            value={line.stage_key ?? ''}
                            error={errors[`lines.${i}.stage_key`]}
                            onChange={(e) =>
                                update(i, { stage_key: e.target.value })
                            }
                            options={stages.map((s) => ({
                                value: s.key,
                                label: s.name,
                            }))}
                        />
                    ) : (
                        <div />
                    )}
                    <TextField
                        label={t('rules.plans.offset')}
                        type="number"
                        dir="ltr"
                        min={0}
                        max={limits.offset}
                        value={String(line.offset_days)}
                        error={errors[`lines.${i}.offset_days`]}
                        onChange={(e) =>
                            update(i, {
                                offset_days: Number(e.target.value) || 0,
                            })
                        }
                    />
                    <TextField
                        label={t('rules.plans.grace')}
                        type="number"
                        dir="ltr"
                        min={0}
                        max={limits.grace}
                        value={String(line.grace_days)}
                        error={errors[`lines.${i}.grace_days`]}
                        onChange={(e) =>
                            update(i, {
                                grace_days: Number(e.target.value) || 0,
                            })
                        }
                    />
                    <div className="flex items-end sm:col-span-4">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={draft.lines.length === 1}
                            onClick={() =>
                                setDraft({
                                    ...draft,
                                    lines: draft.lines.filter(
                                        (_, j) => j !== i,
                                    ),
                                })
                            }
                        >
                            {t('rules.remove')}
                        </Button>
                    </div>
                </fieldset>
            ))}
            <Button
                type="button"
                variant="secondary"
                disabled={draft.lines.length >= limits.lines}
                onClick={() =>
                    setDraft({
                        ...draft,
                        lines: [...draft.lines, blankLine(draft.lines.length)],
                    })
                }
            >
                {t('rules.plans.add_line')}
            </Button>

            <p
                className={ok ? 'text-success-text' : 'text-danger-text'}
                role="status"
                data-test="plan-total"
            >
                {ok
                    ? hasRest
                        ? t('rules.plans.total_rest', { total: totalText })
                        : t('rules.plans.total_ok')
                    : t('rules.plans.total_bad', { total: totalText })}
            </p>
            {errorOf(errors, 'lines') && (
                <Alert variant="danger" role="alert">
                    <AlertBody>{errorOf(errors, 'lines')}</AlertBody>
                </Alert>
            )}
            <p className="text-caption text-weak">
                {t('rules.plans.applies_note')}
            </p>
            <StickyActions className="mt-0">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    {t('rules.cancel')}
                </Button>
                <Button
                    type="button"
                    onClick={onSave}
                    loading={busy}
                    data-test="save-plan"
                >
                    {t('rules.save')}
                </Button>
            </StickyActions>
        </section>
    );
}

PaymentPlans.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.plans.title', href: plansRoute() },
    ],
};
