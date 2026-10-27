import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Icon } from '@/components/opsone/icon';
import { BranchFormDialog } from '@/components/org/branch-form';
import type { BranchRow } from '@/components/org/branch-form';
import { Progress } from '@/components/org/progress';
import { StepFrame } from '@/components/org/step-frame';
import type { Seats } from '@/components/org/format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

type Props = {
    branches: (BranchRow & { name: string; status: string })[];
    regions: { id: number; name: string }[];
    usage: Seats;
    canCreate: boolean;
};

export default function SetupStructure({
    branches,
    regions,
    usage,
    canCreate,
}: Props) {
    const t = useT();
    const [editing, setEditing] = useState<BranchRow | null>(null);
    const [open, setOpen] = useState(false);

    const show = (branch: BranchRow | null) => {
        setEditing(branch);
        setOpen(true);
    };

    return (
        <>
            <Head title={t('org.setup.step_structure')} />
            <StepFrame
                step="structure"
                description={t('org.setup.structure_intro')}
                where="org.setup.where_structure"
            >
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-subtitle text-strong">
                            {t('org.branches.title')}
                        </h2>
                        {canCreate && (
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                onClick={() => show(null)}
                                data-test="wizard-add-branch"
                            >
                                <Icon icon={Plus} size="sm" />
                                {t('org.branches.add')}
                            </Button>
                        )}
                    </div>
                    <ul className="m-0 flex list-none flex-col divide-y divide-stroke-weak rounded-md border border-stroke-weak p-0">
                        {branches.map((branch) => (
                            <li
                                key={branch.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                            >
                                <span className="font-semibold text-strong">
                                    {branch.name}
                                </span>
                                <span className="flex items-center gap-2">
                                    {branch.status === 'disabled' && (
                                        <Badge variant="neutral">
                                            {t('org.status.disabled')}
                                        </Badge>
                                    )}
                                    {branch.status === 'active' && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => show(branch)}
                                        >
                                            {t('org.branches.edit')}
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-col gap-2">
                        <p className="text-caption text-weak">
                            {usage.limit === null
                                ? t('org.branches.usage_unlimited', {
                                      used: usage.used,
                                  })
                                : t('org.branches.usage', {
                                      used: usage.used,
                                      limit: usage.limit,
                                  })}
                        </p>
                        {usage.limit !== null && (
                            <Progress
                                label={t('org.branches.usage', {
                                    used: usage.used,
                                    limit: usage.limit,
                                })}
                                value={usage.used}
                                max={usage.limit}
                            />
                        )}
                    </div>
                    <p className="text-caption text-weak">
                        {t('org.setup.departments_later')}
                    </p>
                </div>
            </StepFrame>
            <BranchFormDialog
                open={open}
                onOpenChange={setOpen}
                branch={editing}
                regions={regions}
                from="wizard"
            />
        </>
    );
}
