import { SelectField } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';
import { parseTarget, targetValue } from './definition';
import type { FlowOptions, Target } from './types';

/**
 * Who receives work or follows a stage: the order's responsible person, a member, a department inbox, any member of a
 * role, a person per branch (ADR-0038) and — for an action — the stage's responsible. A per-branch choice lists every
 * branch with its person; a branch left empty follows the order's responsible.
 */
export function TargetSelect({
    label,
    value,
    options,
    disabled,
    error,
    allowStage = false,
    onChange,
}: {
    label: string;
    value: Target;
    options: FlowOptions;
    disabled: boolean;
    error?: string;
    allowStage?: boolean;
    onChange: (value: Target) => void;
}) {
    const t = useT();
    const rows = value.branches ?? [];

    const setBranch = (branchId: number, userId: string) => {
        const others = rows.filter((r) => r.branch_id !== branchId);
        onChange({
            type: 'per_branch',
            branches:
                userId === ''
                    ? others
                    : [
                          ...others,
                          { branch_id: branchId, user_id: Number(userId) },
                      ],
        });
    };

    return (
        <div className="grid gap-2">
            <SelectField
                label={label}
                value={targetValue(value)}
                disabled={disabled}
                error={error}
                onChange={(e) => onChange(parseTarget(e.target.value, value))}
                options={[
                    {
                        value: 'order_assignee',
                        label: t('flow.target.order_assignee'),
                    },
                    ...(allowStage
                        ? [{ value: 'stage', label: t('flow.target.stage') }]
                        : []),
                    ...options.roles.map((r) => ({
                        value: `role:${r.id}`,
                        label: `${t('flow.target.role')}: ${r.name}`,
                    })),
                    { value: 'per_branch', label: t('flow.target.per_branch') },
                    ...options.departments.map((d) => ({
                        value: `department:${d.id}`,
                        label: `${t('flow.target.department')}: ${d.name}`,
                    })),
                    ...options.members.map((m) => ({
                        value: `member:${m.id}`,
                        label: `${t('flow.target.member')}: ${m.name}`,
                    })),
                ]}
            />
            {value.type === 'per_branch' && (
                <fieldset
                    className="grid gap-2 rounded-md border border-stroke-weak p-3"
                    data-test="per-branch"
                >
                    <legend className="px-1 text-caption text-weak">
                        {t('flow.target.per_branch_hint')}
                    </legend>
                    {options.branches.map((b) => (
                        <SelectField
                            key={b.id}
                            label={b.name}
                            value={String(
                                rows.find((r) => r.branch_id === b.id)
                                    ?.user_id ?? '',
                            )}
                            disabled={disabled}
                            onChange={(e) => setBranch(b.id, e.target.value)}
                            options={[
                                {
                                    value: '',
                                    label: t('flow.target.order_assignee'),
                                },
                                ...options.members.map((m) => ({
                                    value: String(m.id),
                                    label: m.name,
                                })),
                            ]}
                        />
                    ))}
                </fieldset>
            )}
        </div>
    );
}
