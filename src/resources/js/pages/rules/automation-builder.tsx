import { Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Rules/AutomationRuleController';
import Heading from '@/components/heading';
import {
    CheckboxField,
    SelectField,
    SwitchField,
    TextField,
} from '@/components/opsone/field';
import type { SelectOption } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import {
    actionLabel,
    fieldLabel,
    opLabel,
    OUTCOME_VARIANT,
    reasonLabel,
    triggerLabel,
} from '@/components/rules/automation';
import type {
    Condition,
    Conditions,
    RuleAction,
    Target,
    Text,
} from '@/components/rules/automation';
import { RulesNav, errorOf, useRulesWrite } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { home } from '@/routes/settings';

type Param = { name: string; min: number; max: number; default: number };

type Catalogue = {
    triggers: {
        key: string;
        group: string;
        source: string;
        param: Param | null;
        fields: string[];
    }[];
    fields: { key: string; type: string; options: SelectOption[] }[];
    operators: Record<string, string[]>;
    actions: RuleAction['type'][];
    templates: string[];
    variables: string[];
    roles: SelectOption[];
    members: SelectOption[];
};

type Draft = {
    key: string;
    name: Text;
    trigger: string;
    params: Record<string, number>;
    conditions: Conditions;
    actions: RuleAction[];
    enabled: boolean;
    lock_version?: number;
};

type Sample = {
    event: { occurred_at: string; subject: string; order_id: number | null };
    outcome: string;
    reason: string | null;
    conditions: { field: string; op: string; passed: boolean }[];
    actions: {
        type: string;
        would: boolean;
        reason: string | null;
        summary: Summary;
    }[];
};

type Props = {
    rule: { id: number; key: string; killed: boolean } | null;
    draft: Draft | null;
    catalogue: Catalogue;
    canEdit: boolean;
};

const MAX_CONDITIONS = 10;
const MAX_ACTIONS = 5;

function blankAction(
    type: RuleAction['type'],
    templates: string[],
): RuleAction {
    switch (type) {
        case 'send_message':
            return { type, template: templates[0] ?? '', channel: 'auto' };
        case 'create_task':
            return {
                type,
                title: { ar: '', en: null },
                sla_days: 1,
                to: { type: 'responsible' },
            };
        case 'notify':
            return {
                type,
                to: { type: 'responsible' },
                message: { ar: '', en: null },
            };
        case 'assign':
            return { type, to: { type: 'role', role: 'sales' } };
        case 'add_note':
            return { type, body: { ar: '', en: null } };
    }
}

/**
 * Settings › automation › rule (ADR-0042): the builder «عند [حدث] وإذا [شروط] فافعل [إجراءات]» from closed lists
 * only, and «try it» on the latest real events of the trigger (nothing is sent or saved).
 */
export default function AutomationBuilder({
    rule,
    draft: initial,
    catalogue,
    canEdit,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const write = useRulesWrite();
    const [draft, setDraft] = useState<Draft>(
        initial ?? {
            key: '',
            name: { ar: '', en: null },
            trigger: catalogue.triggers[0].key,
            params: {},
            conditions: { match: 'all', items: [] },
            actions: [blankAction('notify', catalogue.templates)],
            enabled: true,
        },
    );
    const [samples, setSamples] = useState<Sample[] | null>(null);
    const [tryError, setTryError] = useState<string | null>(null);
    const [trying, setTrying] = useState(false);

    const trigger =
        catalogue.triggers.find((x) => x.key === draft.trigger) ??
        catalogue.triggers[0];
    const fieldsOf = (key: string) =>
        catalogue.fields.find((f) => f.key === key);
    const editable = canEdit;

    const payload = () => ({
        ...draft,
        params: trigger.param
            ? {
                  [trigger.param.name]:
                      draft.params[trigger.param.name] ?? trigger.param.default,
              }
            : {},
    });

    const save = () => {
        const target =
            rule === null
                ? AutomationRuleController.store()
                : AutomationRuleController.update(rule.id);
        write.send(target.method as 'post' | 'put', target.url, payload());
    };

    const tryIt = async () => {
        setTrying(true);
        const result = await api<{ samples: Sample[] }>(
            'POST',
            AutomationRuleController.try().url,
            payload(),
        );
        setTrying(false);
        if (result.ok && result.data) {
            setSamples(result.data.samples);
            setTryError(null);
            write.setErrors({});
        } else {
            setSamples(null);
            write.setErrors(
                Object.fromEntries(
                    Object.entries(result.errors).map(([k, v]) => [k, v[0]]),
                ),
            );
            setTryError(result.message ?? t('automation.try.failed'));
        }
    };

    const setCondition = (i: number, next: Partial<Condition>) =>
        setDraft({
            ...draft,
            conditions: {
                ...draft.conditions,
                items: draft.conditions.items.map((c, j) =>
                    j === i ? { ...c, ...next } : c,
                ),
            },
        });

    const setAction = (i: number, next: RuleAction) =>
        setDraft({
            ...draft,
            actions: draft.actions.map((a, j) => (j === i ? next : a)),
        });

    const groups = [...new Set(catalogue.triggers.map((x) => x.group))];

    return (
        <>
            <Head title={t('automation.builder.title')} />
            <PageHeader
                title={
                    rule === null
                        ? t('automation.builder.new')
                        : t('automation.builder.title')
                }
                description={t('automation.builder.description')}
                back={
                    <Link
                        href={AutomationRuleController.index()}
                        className="text-link"
                    >
                        {t('automation.back')}
                    </Link>
                }
            />
            <RulesNav current="automation" canEdit={canEdit} />

            <fieldset
                disabled={!editable}
                className="m-0 grid max-w-4xl min-w-0 gap-6 border-0 p-0"
                data-test="rule-builder"
            >
                <div className="grid gap-4 sm:grid-cols-3">
                    <TextField
                        label={t('automation.builder.name')}
                        value={draft.name.ar ?? ''}
                        error={write.errors['name.ar']}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                name: { ...draft.name, ar: e.target.value },
                            })
                        }
                    />
                    <TextField
                        label={t('automation.builder.name_en')}
                        dir="ltr"
                        value={draft.name.en ?? ''}
                        onChange={(e) =>
                            setDraft({
                                ...draft,
                                name: { ...draft.name, en: e.target.value },
                            })
                        }
                    />
                    <TextField
                        label={t('rules.key')}
                        hint={t('rules.key_hint')}
                        dir="ltr"
                        value={draft.key}
                        error={write.errors.key}
                        disabled={rule !== null}
                        onChange={(e) =>
                            setDraft({ ...draft, key: e.target.value })
                        }
                    />
                </div>

                <section
                    className="grid gap-3 rounded-lg border border-stroke-weak p-4"
                    data-test="when"
                >
                    <Heading
                        variant="small"
                        title={t('automation.when')}
                        description={t('automation.builder.when_hint')}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label={t('automation.builder.trigger')}
                            value={draft.trigger}
                            error={write.errors.trigger}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    trigger: e.target.value,
                                    params: {},
                                    conditions: { match: 'all', items: [] },
                                })
                            }
                            options={groups.flatMap((g) =>
                                catalogue.triggers
                                    .filter((x) => x.group === g)
                                    .map((x) => ({
                                        value: x.key,
                                        label: `${t(`automation.group.${g}` as TranslationKey)} — ${triggerLabel(t, x.key)}`,
                                    })),
                            )}
                            data-test="trigger"
                        />
                        {trigger.param && (
                            <TextField
                                label={t(
                                    `automation.param.${trigger.param.name}` as TranslationKey,
                                )}
                                hint={t('automation.builder.param_range', {
                                    min: trigger.param.min,
                                    max: trigger.param.max,
                                })}
                                type="number"
                                dir="ltr"
                                min={trigger.param.min}
                                max={trigger.param.max}
                                value={String(
                                    draft.params[trigger.param.name] ??
                                        trigger.param.default,
                                )}
                                error={errorOf(write.errors, 'params')}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        params: {
                                            [trigger.param!.name]:
                                                Number(e.target.value) || 0,
                                        },
                                    })
                                }
                            />
                        )}
                    </div>
                    <p className="text-caption text-weak">
                        {t(
                            `automation.trigger_hint.${draft.trigger}` as TranslationKey,
                        )}
                    </p>
                </section>

                <section
                    className="grid gap-3 rounded-lg border border-stroke-weak p-4"
                    data-test="if"
                >
                    <Heading
                        variant="small"
                        title={t('automation.if')}
                        description={t('automation.builder.if_hint')}
                    />
                    {draft.conditions.items.length > 1 && (
                        <SelectField
                            label={t('automation.builder.match')}
                            value={draft.conditions.match}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    conditions: {
                                        ...draft.conditions,
                                        match: e.target.value as 'all' | 'any',
                                    },
                                })
                            }
                            options={[
                                {
                                    value: 'all',
                                    label: t('automation.match.all'),
                                },
                                {
                                    value: 'any',
                                    label: t('automation.match.any'),
                                },
                            ]}
                            className="max-w-xs"
                        />
                    )}
                    {draft.conditions.items.length === 0 && (
                        <p className="text-weak">
                            {t('automation.conditions.always')}
                        </p>
                    )}
                    {draft.conditions.items.map((c, i) => {
                        const field = fieldsOf(c.field);
                        const ops = field
                            ? (catalogue.operators[field.type] ?? [])
                            : [];

                        return (
                            <div
                                key={i}
                                className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                                data-test={`condition-${i}`}
                            >
                                <SelectField
                                    label={t('automation.builder.field')}
                                    value={c.field}
                                    onChange={(e) => {
                                        const f = fieldsOf(e.target.value);
                                        const op =
                                            catalogue.operators[
                                                f?.type ?? ''
                                            ]?.[0] ?? 'eq';
                                        setCondition(i, {
                                            field: e.target.value,
                                            op,
                                            value: defaultValue(f, op),
                                        });
                                    }}
                                    options={trigger.fields.map((k) => ({
                                        value: k,
                                        label: fieldLabel(t, k),
                                    }))}
                                />
                                <SelectField
                                    label={t('automation.builder.operator')}
                                    value={c.op}
                                    error={
                                        write.errors[`conditions.items.${i}.op`]
                                    }
                                    onChange={(e) =>
                                        setCondition(i, {
                                            op: e.target.value,
                                            value: defaultValue(
                                                field,
                                                e.target.value,
                                            ),
                                        })
                                    }
                                    options={ops.map((o) => ({
                                        value: o,
                                        label: opLabel(t, o),
                                    }))}
                                />
                                <ConditionValueInput
                                    field={field}
                                    condition={c}
                                    error={
                                        write.errors[
                                            `conditions.items.${i}.value`
                                        ]
                                    }
                                    onChange={(value) =>
                                        setCondition(i, { value })
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    aria-label={t('automation.builder.remove')}
                                    onClick={() =>
                                        setDraft({
                                            ...draft,
                                            conditions: {
                                                ...draft.conditions,
                                                items: draft.conditions.items.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            },
                                        })
                                    }
                                >
                                    <Icon icon={Trash2} />
                                </Button>
                            </div>
                        );
                    })}
                    {draft.conditions.items.length < MAX_CONDITIONS &&
                        trigger.fields.length > 0 && (
                            <div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => {
                                        const f = fieldsOf(trigger.fields[0]);
                                        const op =
                                            catalogue.operators[
                                                f?.type ?? ''
                                            ]?.[0] ?? 'eq';
                                        setDraft({
                                            ...draft,
                                            conditions: {
                                                ...draft.conditions,
                                                items: [
                                                    ...draft.conditions.items,
                                                    {
                                                        field: trigger
                                                            .fields[0],
                                                        op,
                                                        value: defaultValue(
                                                            f,
                                                            op,
                                                        ),
                                                    },
                                                ],
                                            },
                                        });
                                    }}
                                    data-test="add-condition"
                                >
                                    <Icon icon={Plus} />
                                    {t('automation.builder.add_condition')}
                                </Button>
                            </div>
                        )}
                </section>

                <section
                    className="grid gap-3 rounded-lg border border-stroke-weak p-4"
                    data-test="then"
                >
                    <Heading
                        variant="small"
                        title={t('automation.then')}
                        description={t('automation.builder.then_hint', {
                            vars: catalogue.variables
                                .map((v) => `{${v}}`)
                                .join(' '),
                        })}
                    />
                    {errorOf(write.errors, 'actions') && (
                        <p className="text-danger-text">
                            {errorOf(write.errors, 'actions')}
                        </p>
                    )}
                    {draft.actions.map((a, i) => (
                        <div
                            key={i}
                            className="grid gap-3 rounded-md bg-neutral-bg p-3"
                            data-test={`action-${i}`}
                        >
                            <div className="flex items-end gap-3">
                                <SelectField
                                    label={t('automation.builder.action', {
                                        n: i + 1,
                                    })}
                                    value={a.type}
                                    onChange={(e) =>
                                        setAction(
                                            i,
                                            blankAction(
                                                e.target
                                                    .value as RuleAction['type'],
                                                catalogue.templates,
                                            ),
                                        )
                                    }
                                    options={catalogue.actions.map((x) => ({
                                        value: x,
                                        label: actionLabel(t, x),
                                    }))}
                                    className="flex-1"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    aria-label={t('automation.builder.remove')}
                                    disabled={draft.actions.length === 1}
                                    onClick={() =>
                                        setDraft({
                                            ...draft,
                                            actions: draft.actions.filter(
                                                (_, j) => j !== i,
                                            ),
                                        })
                                    }
                                >
                                    <Icon icon={Trash2} />
                                </Button>
                            </div>
                            <ActionFields
                                action={a}
                                catalogue={catalogue}
                                errors={write.errors}
                                path={`actions.${i}`}
                                onChange={(next) => setAction(i, next)}
                            />
                        </div>
                    ))}
                    {draft.actions.length < MAX_ACTIONS && (
                        <div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    setDraft({
                                        ...draft,
                                        actions: [
                                            ...draft.actions,
                                            blankAction(
                                                'add_note',
                                                catalogue.templates,
                                            ),
                                        ],
                                    })
                                }
                                data-test="add-action"
                            >
                                <Icon icon={Plus} />
                                {t('automation.builder.add_action')}
                            </Button>
                        </div>
                    )}
                </section>

                <SwitchField
                    label={t('automation.builder.enabled')}
                    hint={t('automation.builder.enabled_hint')}
                    checked={draft.enabled}
                    onCheckedChange={(enabled) =>
                        setDraft({ ...draft, enabled })
                    }
                />
            </fieldset>

            <div className="mt-6 flex max-w-4xl flex-wrap gap-2">
                {canEdit && (
                    <Button
                        type="button"
                        onClick={save}
                        loading={write.busy}
                        data-test="save-rule"
                    >
                        {t('automation.builder.save')}
                    </Button>
                )}
                <Button
                    type="button"
                    variant="secondary"
                    onClick={tryIt}
                    loading={trying}
                    data-test="try-rule"
                >
                    {t('automation.try.run')}
                </Button>
            </div>
            <p className="mt-2 max-w-4xl text-caption text-weak">
                {t('automation.builder.draft_note')}
            </p>

            {tryError && <p className="mt-4 text-danger-text">{tryError}</p>}
            {samples !== null && (
                <section
                    className="mt-6 grid max-w-4xl gap-3"
                    data-test="try-result"
                >
                    <Heading
                        variant="small"
                        title={t('automation.try.title')}
                        description={t('automation.try.hint')}
                    />
                    {samples.length === 0 && (
                        <p className="text-weak">{t('automation.try.none')}</p>
                    )}
                    {samples.map((s, i) => (
                        <article
                            key={i}
                            className="rounded-lg border border-stroke-weak p-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={
                                        OUTCOME_VARIANT[s.outcome] ?? 'neutral'
                                    }
                                >
                                    {t(
                                        `automation.outcome.${s.outcome}` as TranslationKey,
                                    )}
                                </Badge>
                                <span className="font-medium">
                                    {s.event.subject}
                                </span>
                                <span className="text-caption text-weak">
                                    {formatDateTime(
                                        s.event.occurred_at,
                                        locale,
                                    )}
                                </span>
                                {s.reason && (
                                    <span className="text-caption text-weak">
                                        {reasonLabel(t, s.reason)}
                                    </span>
                                )}
                            </div>
                            {s.conditions.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {s.conditions.map((c, j) => (
                                        <li key={j}>
                                            <Badge
                                                variant={
                                                    c.passed
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {c.passed ? '✓' : '✗'}{' '}
                                                {fieldLabel(t, c.field)}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {s.actions.length > 0 && (
                                <ul className="mt-3 grid gap-2">
                                    {s.actions.map((a, j) => (
                                        <li
                                            key={j}
                                            className="rounded-md bg-neutral-bg p-2"
                                        >
                                            <strong>
                                                {actionLabel(t, a.type)}
                                            </strong>
                                            {': '}
                                            {a.would
                                                ? summaryText(t, a.summary)
                                                : reasonLabel(t, a.reason)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                    ))}
                </section>
            )}
        </>
    );
}

/** The server's description of what an action would do (strings and lists of names only). */
type Summary = {
    to?: string | string[] | null;
    channel?: string;
    title?: string;
    message?: string;
    body?: string;
    text?: string;
    due_at?: string;
};

function summaryText(t: ReturnType<typeof useT>, summary: Summary): string {
    const parts: string[] = [];
    if (summary.to) {
        parts.push(
            `${t('automation.try.to')} ${Array.isArray(summary.to) ? summary.to.join('، ') : summary.to}`,
        );
    }
    if (summary.channel) {
        parts.push(
            t(`automation.channel.${summary.channel}` as TranslationKey),
        );
    }
    for (const key of ['title', 'message', 'body', 'text'] as const) {
        if (summary[key]) {
            parts.push(`«${summary[key]}»`);
        }
    }
    if (summary.due_at) {
        parts.push(`${t('automation.try.due')} ${summary.due_at}`);
    }

    return parts.join(' — ');
}

function defaultValue(
    field: Catalogue['fields'][number] | undefined,
    op: string,
): Condition['value'] {
    if (!field || field.type === 'bool') {
        return null;
    }
    if (op === 'between') {
        return [0, 0];
    }
    if (op === 'in' || op === 'not_in') {
        return [];
    }
    if (field.options.length > 0) {
        return field.options[0].value;
    }

    return field.type === 'text' ? '' : 0;
}

/** Money is entered in riyals and stored in halalas; lists are checkboxes; booleans need no value. */
function ConditionValueInput({
    field,
    condition,
    error,
    onChange,
}: {
    field: Catalogue['fields'][number] | undefined;
    condition: Condition;
    error?: string;
    onChange: (value: Condition['value']) => void;
}) {
    const t = useT();
    if (!field || field.type === 'bool') {
        return <div />;
    }
    const label = t('automation.builder.value');
    const money = field.type === 'money';
    const show = (v: string | number) =>
        money ? String(Number(v) / 100) : String(v);
    const store = (v: string) =>
        money ? Math.round(Number(v) * 100) : Number(v);

    if (condition.op === 'between') {
        const pair = Array.isArray(condition.value) ? condition.value : [0, 0];

        return (
            <div className="grid grid-cols-2 gap-2">
                {[0, 1].map((k) => (
                    <TextField
                        key={k}
                        label={
                            k === 0
                                ? t('automation.builder.from')
                                : t('automation.builder.to')
                        }
                        type="number"
                        dir="ltr"
                        value={show(pair[k] ?? 0)}
                        error={k === 0 ? error : undefined}
                        onChange={(e) => {
                            const next = [...pair];
                            next[k] = store(e.target.value);
                            onChange(next);
                        }}
                    />
                ))}
            </div>
        );
    }
    if (condition.op === 'in' || condition.op === 'not_in') {
        const list = Array.isArray(condition.value)
            ? condition.value.map(String)
            : [];

        return (
            <fieldset className="m-0 min-w-0 border-0 p-0">
                <legend className="mb-1 text-label">{label}</legend>
                <div className="flex flex-wrap gap-x-4">
                    {field.options.map((o) => (
                        <CheckboxField
                            key={o.value}
                            label={o.label}
                            checked={list.includes(o.value)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...list, o.value]
                                        : list.filter((x) => x !== o.value),
                                )
                            }
                        />
                    ))}
                </div>
                {error && <p className="text-danger-text">{error}</p>}
            </fieldset>
        );
    }
    if (field.options.length > 0) {
        return (
            <SelectField
                label={label}
                value={String(condition.value ?? '')}
                error={error}
                onChange={(e) => onChange(e.target.value)}
                options={field.options}
            />
        );
    }
    if (field.type === 'text') {
        return (
            <TextField
                label={label}
                dir="ltr"
                value={String(condition.value ?? '')}
                error={error}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    return (
        <TextField
            label={money ? t('automation.builder.value_money') : label}
            type="number"
            dir="ltr"
            value={show(
                typeof condition.value === 'number' ? condition.value : 0,
            )}
            error={error}
            onChange={(e) => onChange(store(e.target.value))}
        />
    );
}

function TargetFields({
    to,
    catalogue,
    allowResponsible = true,
    error,
    onChange,
}: {
    to: Target;
    catalogue: Catalogue;
    allowResponsible?: boolean;
    error?: string;
    onChange: (to: Target) => void;
}) {
    const t = useT();
    const types = allowResponsible
        ? (['responsible', 'member', 'role'] as const)
        : (['member', 'role'] as const);

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <SelectField
                label={t('automation.builder.to_type')}
                value={to.type}
                error={error}
                onChange={(e) => {
                    const type = e.target.value as Target['type'];
                    onChange(
                        type === 'member'
                            ? {
                                  type,
                                  id: Number(catalogue.members[0]?.value ?? 0),
                              }
                            : type === 'role'
                              ? {
                                    type,
                                    role: catalogue.roles[0]?.value ?? '',
                                }
                              : { type },
                    );
                }}
                options={types.map((x) => ({
                    value: x,
                    label: t(`automation.target.${x}` as TranslationKey),
                }))}
            />
            {to.type === 'member' && (
                <SelectField
                    label={t('automation.target.member')}
                    value={String(to.id)}
                    onChange={(e) =>
                        onChange({ type: 'member', id: Number(e.target.value) })
                    }
                    options={catalogue.members}
                />
            )}
            {to.type === 'role' && (
                <SelectField
                    label={t('automation.target.role')}
                    value={to.role}
                    onChange={(e) =>
                        onChange({ type: 'role', role: e.target.value })
                    }
                    options={catalogue.roles}
                />
            )}
        </div>
    );
}

function TextPair({
    label,
    value,
    error,
    onChange,
}: {
    label: string;
    value: Text;
    error?: string;
    onChange: (value: Text) => void;
}) {
    const t = useT();

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <TextField
                label={label}
                value={value.ar ?? ''}
                error={error}
                onChange={(e) => onChange({ ...value, ar: e.target.value })}
            />
            <TextField
                label={`${label} (${t('automation.builder.english')})`}
                dir="ltr"
                value={value.en ?? ''}
                onChange={(e) =>
                    onChange({ ...value, en: e.target.value || null })
                }
            />
        </div>
    );
}

function ActionFields({
    action,
    catalogue,
    errors,
    path,
    onChange,
}: {
    action: RuleAction;
    catalogue: Catalogue;
    errors: Record<string, string>;
    path: string;
    onChange: (action: RuleAction) => void;
}) {
    const t = useT();

    switch (action.type) {
        case 'send_message':
            return (
                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        label={t('automation.builder.template')}
                        hint={t('automation.builder.template_hint')}
                        value={action.template}
                        error={errors[`${path}.template`]}
                        onChange={(e) =>
                            onChange({ ...action, template: e.target.value })
                        }
                        options={catalogue.templates.map((x) => ({
                            value: x,
                            label: t(
                                `rules.messages.event.customer.${x}` as TranslationKey,
                            ),
                        }))}
                    />
                    <SelectField
                        label={t('automation.builder.channel')}
                        value={action.channel}
                        onChange={(e) =>
                            onChange({
                                ...action,
                                channel: e.target.value as 'auto' | 'mail',
                            })
                        }
                        options={[
                            {
                                value: 'auto',
                                label: t('automation.channel.auto'),
                            },
                            {
                                value: 'mail',
                                label: t('automation.channel.mail'),
                            },
                        ]}
                    />
                </div>
            );
        case 'create_task':
            return (
                <div className="grid gap-3">
                    <TextPair
                        label={t('automation.builder.task_title')}
                        value={action.title}
                        error={errorOf(errors, `${path}.title`)}
                        onChange={(title) => onChange({ ...action, title })}
                    />
                    <TextField
                        label={t('automation.builder.sla_days')}
                        hint={t('automation.builder.sla_hint')}
                        type="number"
                        dir="ltr"
                        min={0}
                        max={30}
                        value={String(action.sla_days)}
                        error={errors[`${path}.sla_days`]}
                        onChange={(e) =>
                            onChange({
                                ...action,
                                sla_days: Number(e.target.value) || 0,
                            })
                        }
                        className="max-w-xs"
                    />
                    <TargetFields
                        to={action.to}
                        catalogue={catalogue}
                        error={errorOf(errors, `${path}.to`)}
                        onChange={(to) => onChange({ ...action, to })}
                    />
                </div>
            );
        case 'notify':
            return (
                <div className="grid gap-3">
                    <TargetFields
                        to={action.to}
                        catalogue={catalogue}
                        error={errorOf(errors, `${path}.to`)}
                        onChange={(to) => onChange({ ...action, to })}
                    />
                    <TextPair
                        label={t('automation.builder.message')}
                        value={action.message}
                        error={errorOf(errors, `${path}.message`)}
                        onChange={(message) => onChange({ ...action, message })}
                    />
                </div>
            );
        case 'assign':
            return (
                <TargetFields
                    to={action.to}
                    catalogue={catalogue}
                    allowResponsible={false}
                    error={errorOf(errors, `${path}.to`)}
                    onChange={(to) => onChange({ ...action, to })}
                />
            );
        case 'add_note':
            return (
                <TextPair
                    label={t('automation.builder.note')}
                    value={action.body}
                    error={errorOf(errors, `${path}.body`)}
                    onChange={(body) => onChange({ ...action, body })}
                />
            );
    }
}

AutomationBuilder.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'automation.title', href: AutomationRuleController.index() },
    ],
};
