import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import BranchTransferController from '@/actions/App/Http/Controllers/Orders/BranchTransferController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import { SelectField, TextareaField } from '@/components/opsone/field';
import { notify } from '@/components/opsone/toast';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { ConflictAlert } from './conflict';
import type { OrderDetail, Option } from './types';

type Impact = {
    from_branch: { id: number; name: string };
    to_branch: { id: number; name: string };
    assignee: {
        current: { id: number; name: string } | null;
        keeps_access: boolean;
        requires_new: boolean;
    };
    open_lines: number;
    appointments: { available: boolean; upcoming: number | null };
    reserved_stock: {
        available: boolean;
        applicable?: boolean;
        reserved_lines?: number | null;
    };
};

/**
 * OrderBranch (COM-12, P-19): pick the target branch, review what changes, give a reason. The preview carries the
 * lock_version it was computed against; applying with it makes a change made meanwhile surface as a conflict.
 */
export function BranchTransferDialog({
    open,
    onOpenChange,
    order,
    branches,
    members,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    order: OrderDetail;
    branches: Option[];
    members: Option[];
}) {
    const t = useT();
    const [target, setTarget] = useState('');
    const [impact, setImpact] = useState<Impact | null>(null);
    const [lockVersion, setLockVersion] = useState(order.lock_version);
    const [assignee, setAssignee] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [stale, setStale] = useState(false);

    useEffect(() => {
        if (!open) {
            setTarget('');
            setImpact(null);
            setAssignee('');
            setReason('');
            setErrors({});
            setMessage(null);
            setStale(false);
        }
    }, [open]);

    const choose = async (branch: string) => {
        setTarget(branch);
        setImpact(null);
        setMessage(null);
        setErrors({});
        if (branch === '') {
            return;
        }
        const result = await api<{ impact: Impact; lock_version: number }>(
            'GET',
            BranchTransferController.previewOrder.url(order.id, {
                query: { branch_id: branch },
            }),
        );
        if (result.ok && result.data) {
            setImpact(result.data.impact);
            setLockVersion(result.data.lock_version);
        } else {
            setMessage(result.message ?? t('orders.transfer.preview_failed'));
        }
    };

    const apply = async () => {
        if (busy) {
            return;
        }
        setBusy(true);
        setErrors({});
        setMessage(null);
        const result = await api<{ order: OrderDetail | null }>(
            'POST',
            BranchTransferController.applyOrder.url(order.id),
            {
                branch_id: Number(target),
                reason,
                lock_version: lockVersion,
                assignee_id: assignee === '' ? undefined : Number(assignee),
            },
        );
        setBusy(false);

        if (result.ok) {
            notify('success', { title: t('orders.transfer.done') });
            onOpenChange(false);
            // The actor may not see the order in the new branch any more.
            if (result.data?.order) {
                router.reload();
            } else {
                router.visit(OrderController.index.url());
            }

            return;
        }
        if (result.stale) {
            setStale(true);

            return;
        }
        setErrors(result.errors);
        if (Object.keys(result.errors).length === 0) {
            setMessage(result.message ?? t('orders.transfer.failed'));
        }
    };

    const first = (key: string) => errors[key]?.[0];
    const needsAssignee = impact?.assignee.requires_new === true;
    const ready =
        impact !== null &&
        reason.trim().length >= 3 &&
        (!needsAssignee || assignee !== '');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent size="md" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>
                        {t('orders.transfer.title', { number: order.number })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('orders.transfer.desc', {
                            branch: order.branch.name,
                        })}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4" data-test="transfer-form">
                    {stale && (
                        <ConflictAlert onReload={() => onOpenChange(false)} />
                    )}
                    {message && (
                        <Alert variant="danger" role="alert">
                            <AlertBody>{message}</AlertBody>
                        </Alert>
                    )}
                    <SelectField
                        label={t('orders.transfer.new_branch')}
                        value={target}
                        onChange={(e) => void choose(e.target.value)}
                        options={[
                            { value: '', label: t('orders.transfer.choose') },
                            ...branches.map((b) => ({
                                value: String(b.id),
                                label: b.name,
                            })),
                        ]}
                        error={first('branch_id')}
                        required
                    />
                    {impact && (
                        <section
                            aria-label={t('orders.transfer.impact')}
                            className="rounded-lg bg-surface p-4 ring-1 ring-stroke-weak"
                            data-test="transfer-impact"
                        >
                            <h3 className="mb-2 font-semibold text-strong">
                                {t('orders.transfer.impact')}
                            </h3>
                            <ul className="grid list-disc gap-1 ps-5 text-body">
                                <li>
                                    {t('orders.transfer.impact_lines', {
                                        n: impact.open_lines,
                                    })}
                                </li>
                                <li>
                                    {impact.assignee.current === null
                                        ? t('orders.transfer.assignee_none')
                                        : impact.assignee.keeps_access
                                          ? t(
                                                'orders.transfer.assignee_keeps',
                                                {
                                                    name: impact.assignee
                                                        .current.name,
                                                },
                                            )
                                          : t(
                                                'orders.transfer.assignee_loses',
                                                {
                                                    name: impact.assignee
                                                        .current.name,
                                                },
                                            )}
                                </li>
                                <li>
                                    {impact.appointments.available
                                        ? t('orders.transfer.appointments', {
                                              n:
                                                  impact.appointments
                                                      .upcoming ?? 0,
                                          })
                                        : t(
                                              'orders.transfer.appointments_later',
                                          )}
                                </li>
                                {impact.reserved_stock.applicable !== false && (
                                    <li>
                                        {impact.reserved_stock.available
                                            ? t('orders.transfer.stock', {
                                                  n:
                                                      impact.reserved_stock
                                                          .reserved_lines ?? 0,
                                              })
                                            : t('orders.transfer.stock_later')}
                                    </li>
                                )}
                                <li>{t('orders.transfer.unchanged')}</li>
                            </ul>
                        </section>
                    )}
                    {needsAssignee && (
                        <SelectField
                            label={t('orders.transfer.new_assignee')}
                            hint={t('orders.transfer.new_assignee_hint')}
                            value={assignee}
                            onChange={(e) => setAssignee(e.target.value)}
                            options={[
                                {
                                    value: '',
                                    label: t('orders.transfer.choose'),
                                },
                                ...members.map((m) => ({
                                    value: String(m.id),
                                    label: m.name,
                                })),
                            ]}
                            error={first('assignee_id')}
                            required
                        />
                    )}
                    <TextareaField
                        label={t('orders.transfer.reason')}
                        hint={t('orders.transfer.reason_hint')}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={1000}
                        error={first('reason')}
                        required
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('orders.transfer.cancel')}
                        </Button>
                        <Button
                            type="button"
                            loading={busy}
                            disabled={!ready}
                            onClick={() => void apply()}
                            data-test="transfer-apply"
                        >
                            {t('orders.transfer.apply')}
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    );
}
