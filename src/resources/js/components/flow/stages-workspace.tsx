import { ArrowDown, ArrowUp, Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CheckboxField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslateFn, TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { ActionDialog } from './action-dialog';
import { AddActionDialog } from './add-action-dialog';
import {
    locate,
    move,
    newAction,
    newStage,
    parseTarget,
    pick,
    targetValue,
} from './definition';
import { TargetSelect } from './target-select';
import type {
    ActionDef,
    CatalogueEntry,
    Definition,
    FlowIssue,
    FlowOptions,
    StageDef,
    Target,
} from './types';

function targetLabel(
    target: Target,
    options: FlowOptions,
    t: TranslateFn,
): string {
    if (target.type === 'member') {
        return (
            options.members.find((m) => m.id === target.id)?.name ??
            t('flow.target.member')
        );
    }
    if (target.type === 'department') {
        return (
            options.departments.find((d) => d.id === target.id)?.name ??
            t('flow.target.department')
        );
    }

    return t('flow.target.order_assignee');
}

/**
 * The stage list and the open stage with its actions (`FlowEdit`, `FlowStage1-5`, and the not-started part of an
 * order in `OrderEditUpcoming`). With `readOnly` nothing can be changed: that is how a published version is shown.
 */
export function StagesWorkspace({
    def,
    onChange,
    options,
    issues,
    readOnly,
    selected,
    onSelect,
    openAction,
    onOpenActionHandled,
    fixedFirst,
}: {
    def: Definition;
    onChange: (def: Definition) => void;
    options: FlowOptions;
    issues: FlowIssue[];
    readOnly: boolean;
    selected: number;
    onSelect: (index: number) => void;
    /** Action to open in the editor right away (from a FlowCheck link). */
    openAction?: number | null;
    onOpenActionHandled?: () => void;
    /** Shows the fixed «create order» start row (the flow editor only). */
    fixedFirst?: boolean;
}) {
    const t = useT();
    const { locale } = useLocale();
    const [editingAction, setEditingAction] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);

    const index = Math.min(selected, Math.max(def.stages.length - 1, 0));
    const stage: StageDef | undefined = def.stages[index];
    const actionIndex = editingAction ?? openAction ?? null;

    const stageIssues = (i: number) =>
        issues.filter((x) => locate(x.path).stage === i);
    const stageError = (field: string) =>
        issues.find((x) => {
            const at = locate(x.path);

            return (
                at.stage === index && at.action === null && at.field === field
            );
        })?.message;

    const mutate = (fn: (next: Definition) => void) => {
        const next = structuredClone(def);
        fn(next);
        onChange(next);
    };
    const patchStage = (change: Partial<StageDef>) =>
        mutate((next) => {
            next.stages[index] = { ...next.stages[index], ...change };
        });

    const addStage = () => {
        mutate((next) => next.stages.push(newStage(next)));
        onSelect(def.stages.length);
    };
    const removeStage = (i: number) => {
        mutate((next) => next.stages.splice(i, 1));
        onSelect(Math.max(0, i - 1));
    };
    const moveStage = (i: number, by: -1 | 1) => {
        mutate((next) => {
            next.stages = move(next.stages, i, by);
        });
        onSelect(i + by);
    };
    const addAction = (entry: CatalogueEntry) => {
        let created = 0;
        mutate((next) => {
            next.stages[index].actions.push(newAction(next, entry));
            created = next.stages[index].actions.length - 1;
        });
        setAdding(false);
        setEditingAction(created);
    };
    const removeAction = (a: number) =>
        mutate((next) => {
            const removed = next.stages[index].actions.splice(a, 1)[0];
            next.stages.forEach((s) =>
                s.actions.forEach((x) => {
                    if (x.after === removed.key) {
                        x.after = null;
                    }
                }),
            );
        });
    const moveAction = (a: number, by: -1 | 1) =>
        mutate((next) => {
            next.stages[index].actions = move(
                next.stages[index].actions,
                a,
                by,
            );
        });
    const moveActionToStage = (a: number, to: number) => {
        let at = 0;
        mutate((next) => {
            const [taken] = next.stages[index].actions.splice(a, 1);
            next.stages[to].actions.push(taken);
            at = next.stages[to].actions.length - 1;
        });
        onSelect(to);
        setEditingAction(at);
    };

    return (
        <div
            className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
            data-test="flow-workspace"
        >
            <Card
                className="content-start gap-2 p-3 md:p-3"
                data-test="flow-stage-list"
            >
                {fixedFirst && (
                    <div className="flex items-center gap-3 rounded-md bg-fill p-3 text-weak">
                        <Icon icon={Lock} size="sm" />
                        <div>
                            <p className="text-label text-strong">
                                {t('flow.stage.create_order')}
                            </p>
                            <p className="text-caption">
                                {t('flow.stage.fixed_start')}
                            </p>
                        </div>
                    </div>
                )}
                <ol className="m-0 grid list-none gap-1 p-0">
                    {def.stages.map((s, i) => {
                        const bad = stageIssues(i).length;

                        return (
                            <li
                                key={s.key}
                                className="flex items-stretch gap-1"
                            >
                                <button
                                    type="button"
                                    onClick={() => onSelect(i)}
                                    aria-current={
                                        i === index ? 'true' : undefined
                                    }
                                    data-test={`stage-${i + 1}`}
                                    className={cn(
                                        'flex min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-md border p-3 text-start hover:bg-hover',
                                        i === index
                                            ? 'border-brand bg-brand-subtle'
                                            : 'border-transparent',
                                    )}
                                >
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-fill text-label text-strong">
                                        {i + 1}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate text-label text-strong">
                                            {pick(s.name, locale) ||
                                                t('flow.stage.untitled')}
                                        </span>
                                        <span className="block text-caption text-weak">
                                            {t('flow.stage.actions_count', {
                                                n: s.actions.length,
                                            })}
                                        </span>
                                    </span>
                                    {bad > 0 && (
                                        <Badge
                                            variant="danger"
                                            className="ms-auto"
                                        >
                                            {bad}
                                        </Badge>
                                    )}
                                </button>
                                {!readOnly && (
                                    <div className="flex flex-col justify-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={t('flow.move_up')}
                                            disabled={i === 0}
                                            onClick={() => moveStage(i, -1)}
                                        >
                                            <Icon icon={ArrowUp} size="sm" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={t('flow.move_down')}
                                            disabled={
                                                i === def.stages.length - 1
                                            }
                                            onClick={() => moveStage(i, 1)}
                                        >
                                            <Icon icon={ArrowDown} size="sm" />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
                {!readOnly && (
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={addStage}
                        data-test="add-stage"
                    >
                        <Icon icon={Plus} />
                        {t('flow.stage.add')}
                    </Button>
                )}
            </Card>

            {stage === undefined ? (
                <Card>
                    <p className="text-weak">{t('flow.stage.none')}</p>
                </Card>
            ) : (
                <Card
                    className="min-w-0 content-start gap-5"
                    data-test="flow-stage-panel"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-section text-strong">
                            {pick(stage.name, locale) ||
                                t('flow.stage.untitled')}
                        </h2>
                        {!readOnly && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => removeStage(index)}
                                data-test="remove-stage"
                            >
                                <Icon icon={Trash2} size="sm" />
                                {t('flow.stage.remove')}
                            </Button>
                        )}
                    </div>

                    {readOnly ? (
                        <dl
                            className="grid gap-x-6 sm:grid-cols-2"
                            data-test="flow-stage-summary"
                        >
                            {(
                                [
                                    [
                                        'flow.field.name_internal',
                                        pick(stage.name, locale),
                                    ],
                                    [
                                        'flow.field.name_public',
                                        pick(
                                            stage.public_name ?? stage.name,
                                            locale,
                                        ),
                                    ],
                                    [
                                        'flow.field.follow_up',
                                        targetLabel(
                                            stage.follow_up,
                                            options,
                                            t,
                                        ),
                                    ],
                                    [
                                        'flow.field.target_days',
                                        stage.target_days === null
                                            ? t('flow.stage.no_target')
                                            : t('flow.stage.days', {
                                                  n: stage.target_days,
                                              }),
                                    ],
                                    [
                                        'flow.field.show_to_customer',
                                        stage.show_to_customer
                                            ? t('flow.yes')
                                            : t('flow.no'),
                                    ],
                                ] as [TranslationKey, string][]
                            ).map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-2 border-b border-stroke-weak py-2"
                                >
                                    <dt className="text-weak">{t(label)}</dt>
                                    <dd className="font-semibold text-strong">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    ) : (
                        <div className="grid gap-4" data-test="flow-stage-form">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label={t('flow.field.name_ar')}
                                    value={stage.name.ar}
                                    error={stageError('name')}
                                    maxLength={80}
                                    onChange={(e) =>
                                        patchStage({
                                            name: {
                                                ...stage.name,
                                                ar: e.target.value,
                                            },
                                        })
                                    }
                                />
                                <TextField
                                    label={t('flow.field.name_en')}
                                    value={stage.name.en ?? ''}
                                    dir="ltr"
                                    maxLength={80}
                                    optional
                                    onChange={(e) =>
                                        patchStage({
                                            name: {
                                                ...stage.name,
                                                en: e.target.value,
                                            },
                                        })
                                    }
                                />
                                <TextField
                                    label={t('flow.field.public_ar')}
                                    hint={t('flow.field.public_hint')}
                                    value={stage.public_name?.ar ?? ''}
                                    error={stageError('public_name')}
                                    maxLength={80}
                                    optional
                                    onChange={(e) =>
                                        patchStage({
                                            public_name:
                                                e.target.value === '' &&
                                                !stage.public_name?.en
                                                    ? null
                                                    : {
                                                          ar: e.target.value,
                                                          en:
                                                              stage.public_name
                                                                  ?.en ?? null,
                                                      },
                                        })
                                    }
                                />
                                <TextField
                                    label={t('flow.field.public_en')}
                                    value={stage.public_name?.en ?? ''}
                                    dir="ltr"
                                    maxLength={80}
                                    optional
                                    onChange={(e) =>
                                        patchStage({
                                            public_name: {
                                                ar: stage.public_name?.ar ?? '',
                                                en: e.target.value,
                                            },
                                        })
                                    }
                                />
                                <TargetSelect
                                    label={t('flow.field.follow_up')}
                                    value={targetValue(stage.follow_up)}
                                    options={options}
                                    disabled={false}
                                    error={stageError('follow_up')}
                                    onChange={(v) =>
                                        patchStage({
                                            follow_up: parseTarget(v),
                                        })
                                    }
                                />
                                <TextField
                                    label={t('flow.field.target_days')}
                                    hint={t('flow.field.target_days_hint')}
                                    type="number"
                                    min={1}
                                    max={365}
                                    dir="ltr"
                                    optional
                                    value={
                                        stage.target_days === null
                                            ? ''
                                            : String(stage.target_days)
                                    }
                                    error={stageError('target_days')}
                                    onChange={(e) =>
                                        patchStage({
                                            target_days:
                                                e.target.value === ''
                                                    ? null
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </div>
                            <CheckboxField
                                label={t('flow.field.show_to_customer')}
                                checked={stage.show_to_customer}
                                onChange={(e) =>
                                    patchStage({
                                        show_to_customer: e.target.checked,
                                    })
                                }
                            />
                        </div>
                    )}

                    <section
                        className="grid gap-3"
                        aria-labelledby="flow-actions-heading"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3
                                    id="flow-actions-heading"
                                    className="text-subtitle text-strong"
                                >
                                    {t('flow.actions.title')}
                                </h3>
                                <p className="text-caption text-weak">
                                    {t('flow.actions.hint')}
                                </p>
                            </div>
                            {!readOnly && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setAdding(true)}
                                    data-test="add-action"
                                >
                                    <Icon icon={Plus} />
                                    {t('flow.actions.add')}
                                </Button>
                            )}
                        </div>
                        {stageError('actions') && (
                            <p className="text-caption text-danger-text">
                                {stageError('actions')}
                            </p>
                        )}
                        {stage.actions.length === 0 ? (
                            <p className="text-weak">
                                {t('flow.actions.empty')}
                            </p>
                        ) : (
                            <ul
                                className="m-0 grid list-none gap-2 p-0"
                                data-test="flow-actions"
                            >
                                {stage.actions.map((a: ActionDef, i) => {
                                    const bad = issues.filter((x) => {
                                        const at = locate(x.path);

                                        return (
                                            at.stage === index &&
                                            at.action === i
                                        );
                                    });

                                    return (
                                        <li
                                            key={a.key}
                                            className={cn(
                                                'flex items-center gap-2 rounded-md border p-3',
                                                bad.length > 0
                                                    ? 'border-danger'
                                                    : 'border-stroke-weak',
                                            )}
                                            data-test={`action-${a.key}`}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-label text-strong">
                                                    {pick(a.name, locale) ||
                                                        a.key}
                                                </p>
                                                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-weak">
                                                    <span>
                                                        {t(
                                                            `flow.group.${options.catalogue.find((c) => c.type === a.type)?.group ?? 'info_measurement'}` as TranslationKey,
                                                        )}
                                                    </span>
                                                    <Badge
                                                        variant={
                                                            a.requirement ===
                                                            'required'
                                                                ? 'brand'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {t(
                                                            `flow.requirement.${a.requirement}` as TranslationKey,
                                                        )}
                                                    </Badge>
                                                    {a.applies_when !==
                                                        'always' && (
                                                        <Badge variant="info">
                                                            {t(
                                                                `flow.applies.${a.applies_when}` as TranslationKey,
                                                            )}
                                                        </Badge>
                                                    )}
                                                    <span>
                                                        {targetLabel(
                                                            a.assign,
                                                            options,
                                                            t,
                                                        )}
                                                    </span>
                                                </p>
                                                {bad.length > 0 && (
                                                    <p className="mt-1 text-caption text-danger-text">
                                                        {bad[0].message}
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                size="sm"
                                                onClick={() =>
                                                    setEditingAction(i)
                                                }
                                                data-test={`edit-${a.key}`}
                                            >
                                                <Icon
                                                    icon={
                                                        readOnly ? Lock : Pencil
                                                    }
                                                    size="sm"
                                                />
                                                {readOnly
                                                    ? t('flow.action.view')
                                                    : t('flow.action.edit')}
                                            </Button>
                                            {!readOnly && (
                                                <>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={t(
                                                            'flow.move_up',
                                                        )}
                                                        disabled={i === 0}
                                                        onClick={() =>
                                                            moveAction(i, -1)
                                                        }
                                                    >
                                                        <Icon
                                                            icon={ArrowUp}
                                                            size="sm"
                                                        />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={t(
                                                            'flow.move_down',
                                                        )}
                                                        disabled={
                                                            i ===
                                                            stage.actions
                                                                .length -
                                                                1
                                                        }
                                                        onClick={() =>
                                                            moveAction(i, 1)
                                                        }
                                                    >
                                                        <Icon
                                                            icon={ArrowDown}
                                                            size="sm"
                                                        />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={t(
                                                            'flow.action.remove',
                                                        )}
                                                        onClick={() =>
                                                            removeAction(i)
                                                        }
                                                    >
                                                        <Icon
                                                            icon={Trash2}
                                                            size="sm"
                                                        />
                                                    </Button>
                                                </>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                </Card>
            )}

            {actionIndex !== null && stage && (
                <ActionDialog
                    open
                    onClose={() => {
                        setEditingAction(null);
                        onOpenActionHandled?.();
                    }}
                    def={def}
                    stageIndex={index}
                    actionIndex={actionIndex}
                    options={options}
                    issues={issues}
                    readOnly={readOnly}
                    onChange={onChange}
                    onMoveToStage={(to) => moveActionToStage(actionIndex, to)}
                />
            )}
            <AddActionDialog
                open={adding}
                onClose={() => setAdding(false)}
                options={options}
                onPick={addAction}
            />
        </div>
    );
}
