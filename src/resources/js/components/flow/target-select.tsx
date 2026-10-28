import { SelectField } from '@/components/opsone/field';
import { useT } from '@/lib/i18n';
import type { FlowOptions } from './types';

/** Who receives work or follows a stage: the order's responsible person, a member or a department inbox. */
export function TargetSelect({
    label,
    value,
    options,
    disabled,
    error,
    onChange,
}: {
    label: string;
    value: string;
    options: FlowOptions;
    disabled: boolean;
    error?: string;
    onChange: (value: string) => void;
}) {
    const t = useT();

    return (
        <SelectField
            label={label}
            value={value}
            disabled={disabled}
            error={error}
            onChange={(e) => onChange(e.target.value)}
            options={[
                {
                    value: 'order_assignee',
                    label: t('flow.target.order_assignee'),
                },
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
    );
}
