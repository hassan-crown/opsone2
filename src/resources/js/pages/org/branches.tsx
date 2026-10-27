import { Head, router } from '@inertiajs/react';
import { GitBranch, Lock, Pencil, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/Org/BranchController';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { BranchFormDialog } from '@/components/org/branch-form';
import type { BranchRow } from '@/components/org/branch-form';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import type { Seats } from '@/components/org/format';
import { Progress } from '@/components/org/progress';
import { RowMenu } from '@/components/org/row-menu';
import { Alert, AlertBody, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { usePage } from '@inertiajs/react';

type Branch = BranchRow & {
    name: string;
    region: string | null;
    status: 'active' | 'disabled';
    members: number;
};

type Props = {
    branches: Branch[];
    regions: { id: number; name: string }[];
    usage: Seats;
    canCreate: boolean;
};

export default function Branches({
    branches,
    regions,
    usage,
    canCreate,
}: Props) {
    const t = useT();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [editing, setEditing] = useState<BranchRow | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [disabling, setDisabling] = useState<Branch | null>(null);
    const [busy, setBusy] = useState(false);

    const label =
        usage.limit === null
            ? t('org.branches.usage_unlimited', { used: usage.used })
            : t('org.branches.usage', { used: usage.used, limit: usage.limit });

    const openForm = (branch: BranchRow | null) => {
        setEditing(branch);
        setFormOpen(true);
    };

    const reactivate = (branch: Branch) =>
        router.post(BranchController.reactivate.url({ branch: branch.id }));

    const confirmDisable = () => {
        if (!disabling) {
            return;
        }
        router.post(
            BranchController.disable.url({ branch: disabling.id }),
            {},
            {
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setDisabling(null);
                },
            },
        );
    };

    return (
        <>
            <Head title={t('org.branches.title')} />
            <PageHeader
                title={t('org.branches.title')}
                description={t('org.branches.description')}
                actions={
                    canCreate && (
                        <Button
                            type="button"
                            onClick={() => openForm(null)}
                            data-test="add-branch"
                        >
                            <Icon icon={Plus} />
                            {t('org.branches.add')}
                        </Button>
                    )
                }
            />
            {(errors.license || errors.branch) && (
                <Alert
                    variant="danger"
                    className="mb-4"
                    data-test="branch-error"
                >
                    <AlertBody>
                        {errors.license && (
                            <AlertTitle>
                                {t('org.branches.limit_title')}
                            </AlertTitle>
                        )}
                        {errors.license ?? errors.branch}
                    </AlertBody>
                </Alert>
            )}
            <Card className="mb-4 gap-2 p-4 md:p-4" data-test="branch-usage">
                <span className="font-semibold text-strong">{label}</span>
                {usage.limit !== null && (
                    <Progress
                        label={label}
                        value={usage.used}
                        max={usage.limit}
                    />
                )}
            </Card>
            {branches.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={GitBranch}
                        title={t('org.branches.empty')}
                        description={t('org.branches.empty_desc')}
                    />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('org.branches.caption')}>
                            <thead>
                                <tr>
                                    <Th>{t('org.branches.col_branch')}</Th>
                                    <Th>{t('org.branches.col_code')}</Th>
                                    <Th>{t('org.branches.col_region')}</Th>
                                    <Th>{t('org.branches.col_members')}</Th>
                                    <Th>{t('org.branches.col_status')}</Th>
                                    <Th className="text-end">
                                        {t('org.branches.col_actions')}
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {branches.map((branch) => (
                                    <tr key={branch.id} data-test="branch-row">
                                        <Td className="font-semibold">
                                            {branch.name}
                                        </Td>
                                        <Td dir="ltr" className="text-start">
                                            {branch.code ?? '—'}
                                        </Td>
                                        <Td>{branch.region ?? '—'}</Td>
                                        <Td>{branch.members}</Td>
                                        <Td>
                                            <Badge
                                                variant={
                                                    branch.status === 'active'
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {t(
                                                    `org.status.${branch.status}` as TranslationKey,
                                                )}
                                            </Badge>
                                        </Td>
                                        <Td className="text-end">
                                            <RowMenu
                                                label={t(
                                                    'org.branches.row_actions',
                                                )}
                                                items={
                                                    branch.status === 'active'
                                                        ? [
                                                              {
                                                                  label: t(
                                                                      'org.branches.edit',
                                                                  ),
                                                                  icon: Pencil,
                                                                  onSelect:
                                                                      () =>
                                                                          openForm(
                                                                              branch,
                                                                          ),
                                                              },
                                                              {
                                                                  label: t(
                                                                      'org.branches.disable',
                                                                  ),
                                                                  icon: Lock,
                                                                  danger: true,
                                                                  separatorBefore: true,
                                                                  onSelect:
                                                                      () =>
                                                                          setDisabling(
                                                                              branch,
                                                                          ),
                                                              },
                                                          ]
                                                        : [
                                                              {
                                                                  label: t(
                                                                      'org.branches.reactivate',
                                                                  ),
                                                                  icon: RotateCcw,
                                                                  onSelect:
                                                                      () =>
                                                                          reactivate(
                                                                              branch,
                                                                          ),
                                                              },
                                                          ]
                                                }
                                            />
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <p className="mt-4 text-caption text-weak">
                {t('org.branches.departments_later')}
            </p>

            <BranchFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                branch={editing}
                regions={regions}
            />
            <Dialog
                open={disabling !== null}
                onOpenChange={(open) => !open && setDisabling(null)}
            >
                <DialogContent size="sm" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {t('org.branches.disable_title', {
                                name: disabling?.name ?? '',
                            })}
                        </DialogTitle>
                        <DialogDescription>
                            {t('org.branches.disable_desc')}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setDisabling(null)}
                        >
                            {t('org.branches.cancel')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            loading={busy}
                            onClick={confirmDisable}
                            data-test="branch-disable-confirm"
                        >
                            {t('org.branches.disable_confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Branches.layout = {
    breadcrumbs: [
        { title: 'org.nav.branches', href: BranchController.index() },
    ],
};
