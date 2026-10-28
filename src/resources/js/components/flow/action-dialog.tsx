import { SelectField, TextField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { INPUT_EDITORS } from './action-editors';
import { locate, parseTarget, pick, targetValue } from './definition';
import { TargetSelect } from './target-select';
import type { ActionDef, Definition, FlowIssue, FlowOptions } from './types';

/** First message per field of one action, from FlowCheck issues (`stages.S.actions.A.<field>`). */
export function actionErrors(
    issues: FlowIssue[],
    stage: number,
    action: number,
): (field: string) => string | undefined {
    const byField = new Map<string, string>();
    for (const issue of issues) {
        const at = locate(issue.path);
        if (at.stage === stage && at.action === action && at.field !== null) {
            byField.set(at.field, byField.get(at.field) ?? issue.message);
        }
    }

    return (field) => byField.get(field);
}

/**
 * `FlowAction_*`: the settings of one action — what is common to all (name, required or optional, condition,
 * assignment, order) and the editor of its own inputs. Changes apply to the working copy as they are made.
 */
export function ActionDialog({
    open,
    onClose,
    def,
    stageIndex,
    actionIndex,
    options,
    issues,
    readOnly,
    onChange,
    onMoveToStage,
}: {
    open: boolean;
    onClose: () => void;
    def: Definition;
    stageIndex: number;
    actionIndex: number;
    options: FlowOptions;
    issues: FlowIssue[];
    readOnly: boolean;
    onChange: (def: Definition) => void;
    onMoveToStage: (toStage: number) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const stage = def.stages[stageIndex];
    const action = stage?.actions[actionIndex];
    if (!stage || !action) {
        return null;
    }

    const err = actionErrors(issues, stageIndex, actionIndex);
    const patch = (change: Partial<ActionDef>) => {
        const next = structuredClone(def);
        next.stages[stageIndex].actions[actionIndex] = { ...action, ...change };
        onChange(next);
    };
    const Editor = INPUT_EDITORS[action.type];
    const entry = options.catalogue.find((c) => c.type === action.type);
    const others = def.stages.flatMap((s) =>
        s.actions
            .filter((a) => a.key !== action.key)
            .map((a) => ({
                value: a.key,
                label: pick(a.name, locale) || a.key,
            })),
    );

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent size="lg" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {entry ? pick(entry.label, locale) : action.type}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            `flow.action.${action.type}.completes` as TranslationKey,
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div
                    className="grid max-h-[65vh] gap-4 overflow-y-auto pe-1"
                    data-test="flow-action-form"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={t('flow.field.name_ar')}
                            value={action.name.ar}
                            disabled={readOnly}
                            error={err('name')}
                            maxLength={80}
                            onChange={(e) =>
                                patch({
                                    name: {
                                        ...action.name,
                                        ar: e.target.value,
                                    },
                                })
                            }
                        />
                        <TextField
                            label={t('flow.field.name_en')}
                            value={action.name.en ?? ''}
                            disabled={readOnly}
                            dir="ltr"
                            maxLength={80}
                            optional
                            onChange={(e) =>
                                patch({
                                    name: {
                                        ...action.name,
                                        en: e.target.value,
                                    },
                                })
                            }
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label={t('flow.field.requirement')}
                            value={action.requirement}
                            disabled={readOnly}
                            error={err('requirement')}
                            onChange={(e) =>
                                patch({
                                    requirement: e.target
                                        .value as ActionDef['requirement'],
                                })
                            }
                            options={[
                                {
                                    value: 'required',
                                    label: t('flow.requirement.required'),
                                },
                                {
                                    value: 'optional',
                                    label: t('flow.requirement.optional'),
                                },
                            ]}
                        />
                        <SelectField
                            label={t('flow.field.applies_when')}
                            value={action.applies_when}
                            disabled={readOnly}
                            error={err('applies_when')}
                            onChange={(e) =>
                                patch({
                                    applies_when: e.target
                                        .value as ActionDef['applies_when'],
                                })
                            }
                            options={[
                                {
                                    value: 'always',
                                    label: t('flow.applies.always'),
                                },
                                {
                                    value: 'needs_measurement',
                                    label: t('flow.applies.needs_measurement'),
                                },
                                {
                                    value: 'needs_design',
                                    label: t('flow.applies.needs_design'),
                                },
                            ]}
                        />
                    </div>

                    <fieldset className="grid gap-4 rounded-md border border-stroke-weak p-4">
                        <legend className="px-1 text-label text-strong">
                            {t('flow.field.settings')}
                        </legend>
                        <Editor
                            inputs={action.inputs}
                            disabled={readOnly}
                            error={(f) => err(`inputs.${f}`)}
                            onChange={(inputs) => patch({ inputs })}
                        />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <TargetSelect
                            label={t('flow.field.assign')}
                            value={targetValue(action.assign)}
                            options={options}
                            disabled={readOnly}
                            error={err('assign')}
                            onChange={(v) => patch({ assign: parseTarget(v) })}
                        />
                        <SelectField
                            label={t('flow.field.after')}
                            hint={t('flow.field.after_hint')}
                            value={action.after ?? ''}
                            disabled={readOnly}
                            error={err('after')}
                            optional
                            onChange={(e) =>
                                patch({
                                    after:
                                        e.target.value === ''
                                            ? null
                                            : e.target.value,
                                })
                            }
                            options={[
                                {
                                    value: '',
                                    label: t('flow.field.after_none'),
                                },
                                ...others,
                            ]}
                        />
                    </div>
                    {!readOnly && def.stages.length > 1 && (
                        <SelectField
                            label={t('flow.field.stage')}
                            value={String(stageIndex)}
                            onChange={(e) =>
                                onMoveToStage(Number(e.target.value))
                            }
                            options={def.stages.map((s, i) => ({
                                value: String(i),
                                label:
                                    pick(s.name, locale) ||
                                    t('flow.stage.untitled'),
                            }))}
                        />
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        onClick={onClose}
                        data-test="flow-action-done"
                    >
                        {t('flow.action.done')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
