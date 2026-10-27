import { Plus, Trash2 } from 'lucide-react';
import { Icon } from '@/components/opsone/icon';
import { SelectField } from '@/components/opsone/field';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export type RoleRow = { role_template_id: number | ''; scope: string };

/** One or more role assignments, each with its scope breadth (the server re-checks every grant). */
export function RoleRows({
    rows,
    onChange,
    roleOptions,
    scopes,
    errors,
    disabled,
}: {
    rows: RoleRow[];
    onChange: (rows: RoleRow[]) => void;
    roleOptions: { id: number; name: string }[];
    scopes: string[];
    errors: Record<string, string | undefined>;
    disabled?: boolean;
}) {
    const t = useT();
    const update = (index: number, patch: Partial<RoleRow>) =>
        onChange(
            rows.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    return (
        <div className="flex flex-col gap-4">
            {rows.map((row, index) => (
                <div
                    key={index}
                    className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
                    data-test="role-row"
                >
                    <SelectField
                        label={t('org.invite.role')}
                        value={String(row.role_template_id)}
                        disabled={disabled}
                        onChange={(event) =>
                            update(index, {
                                role_template_id: Number(event.target.value),
                            })
                        }
                        options={roleOptions.map((r) => ({
                            value: String(r.id),
                            label: r.name,
                        }))}
                        error={errors[`roles.${index}.role_template_id`]}
                        required
                    />
                    <SelectField
                        label={t('org.invite.scope')}
                        value={row.scope}
                        disabled={disabled}
                        onChange={(event) =>
                            update(index, { scope: event.target.value })
                        }
                        options={scopes.map((scope) => ({
                            value: scope === 'default' ? '' : scope,
                            label: t(`org.scope.${scope}` as TranslationKey),
                        }))}
                        error={errors[`roles.${index}.scope`]}
                    />
                    {rows.length > 1 && !disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={t('org.invite.remove_role')}
                            onClick={() =>
                                onChange(rows.filter((_, i) => i !== index))
                            }
                        >
                            <Icon icon={Trash2} />
                        </Button>
                    )}
                </div>
            ))}
            {errors.roles && (
                <p role="alert" className="text-caption text-danger-text">
                    {errors.roles}
                </p>
            )}
            {!disabled && rows.length < 4 && roleOptions.length > 0 && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="self-start"
                    onClick={() =>
                        onChange([
                            ...rows,
                            { role_template_id: roleOptions[0].id, scope: '' },
                        ])
                    }
                >
                    <Icon icon={Plus} size="sm" />
                    {t('org.invite.add_role')}
                </Button>
            )}
        </div>
    );
}
