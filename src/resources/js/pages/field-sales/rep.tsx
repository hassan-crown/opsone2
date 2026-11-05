import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/Calendar/AppointmentController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import FieldTeamController from '@/actions/App/Http/Controllers/FieldSales/FieldTeamController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import TaskController from '@/actions/App/Http/Controllers/Tasks/TaskController';
import { SwitchField, TextField } from '@/components/opsone/field';
import { Amount } from '@/components/opsone/amount';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Permission = {
    permission: string;
    label: string;
    ceiling: boolean;
    enabled: boolean;
    can_enable: boolean;
};

type OrderRow = {
    id: number;
    number: string;
    status: string;
    customer: string;
    stage: string | null;
    stages_done: number;
    stages_total: number;
    due_left_minor: number | null;
    delivery: 'none' | 'partial' | 'complete';
    installation: string | null;
    open_remarks: number;
};

type Props = {
    rep: { id: number; name: string };
    leads: {
        id: number;
        reference: string | null;
        name: string;
        status: string;
        due_at: string | null;
        overdue: boolean;
    }[];
    visits: {
        id: number;
        kind: string;
        status: string;
        at: string;
        today: boolean;
        customer: string;
        order: string;
    }[];
    overdue_tasks: { id: number; order: string; due_at: string | null }[];
    orders: OrderRow[];
    permissions: Permission[];
    discount: {
        limit_bp: number | null;
        ceiling_bp: number;
        own_bp: number | null;
    };
};

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <Card className="gap-3">
            <h2 className="m-0 text-title text-strong">{title}</h2>
            {children}
        </Card>
    );
}

/** The supervisor's controls: the rep's field permissions (inside the owner's ceiling) and discount limit. */
function Controls({
    rep,
    permissions,
    discount,
}: Pick<Props, 'rep' | 'permissions' | 'discount'>) {
    const t = useT();
    const [percent, setPercent] = useState(
        String((discount.limit_bp ?? 0) / 100),
    );
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const max = Math.min(
        discount.ceiling_bp,
        discount.own_bp ?? discount.ceiling_bp,
    );

    return (
        <Section title={t('field_sales.team.permissions')}>
            <p className="m-0 text-caption text-weak">
                {t('field_sales.team.permissions_hint')}
            </p>
            <div
                className="grid gap-x-6 md:grid-cols-2"
                data-test="rep-permissions"
            >
                {permissions.map((p) => (
                    <SwitchField
                        key={p.permission}
                        label={p.label}
                        hint={
                            !p.ceiling
                                ? t('field_sales.team.outside_ceiling')
                                : !p.enabled && !p.can_enable
                                  ? t('field_sales.team.cannot_enable')
                                  : p.permission
                        }
                        checked={p.enabled}
                        disabled={!p.ceiling || (!p.enabled && !p.can_enable)}
                        onCheckedChange={(on) =>
                            router.post(
                                FieldTeamController.permission.url(rep.id),
                                { permission: p.permission, enabled: on },
                                { preserveScroll: true },
                            )
                        }
                    />
                ))}
            </div>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    setBusy(true);
                    router.post(
                        FieldTeamController.discount.url(rep.id),
                        { limit_bp: Math.round(Number(percent || 0) * 100) },
                        {
                            preserveScroll: true,
                            onError: (errors) => setError(errors.limit_bp),
                            onSuccess: () => setError(undefined),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                <TextField
                    className="w-56"
                    label={t('field_sales.team.discount')}
                    hint={t('field_sales.team.discount_hint', {
                        max: max / 100,
                    })}
                    type="number"
                    dir="ltr"
                    min={0}
                    max={max / 100}
                    step="0.5"
                    value={percent}
                    error={error}
                    onChange={(e) => setPercent(e.target.value)}
                    data-test="rep-discount"
                />
                <Button type="submit" variant="secondary" loading={busy}>
                    {t('field_sales.team.save_discount')}
                </Button>
            </form>
        </Section>
    );
}

/**
 * One rep under the supervisor (ADR-0041): permissions and discount, open leads, visits, late tasks, and the rep's
 * orders to the end — each row links to the existing order, appointment, task or lead screen.
 */
export default function FieldRep(props: Props) {
    const t = useT();
    const { rep, leads, visits, overdue_tasks: tasks, orders } = props;

    return (
        <>
            <Head title={rep.name} />
            <PageHeader
                title={rep.name}
                description={t('field_sales.team.rep_description')}
                actions={
                    <Button asChild variant="secondary">
                        <Link href={FieldTeamController.index.url()}>
                            {t('field_sales.team.back')}
                        </Link>
                    </Button>
                }
            />
            <div className="grid gap-6">
                <Controls {...props} />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section
                        title={t('field_sales.team.open_leads', {
                            count: leads.length,
                        })}
                    >
                        {leads.length === 0 ? (
                            <p className="m-0 text-caption text-weak">
                                {t('field_sales.no_leads')}
                            </p>
                        ) : (
                            <ul className="m-0 flex list-none flex-col gap-2 p-0">
                                {leads.map((l) => (
                                    <li
                                        key={l.id}
                                        className="flex flex-wrap items-center gap-2"
                                        data-test="rep-lead"
                                    >
                                        <Link
                                            href={InterestController.show.url(
                                                l.id,
                                            )}
                                            className="text-link"
                                        >
                                            {l.name}
                                        </Link>
                                        <Badge variant="info">
                                            {t(
                                                `interests.status.${l.status}` as TranslationKey,
                                            )}
                                        </Badge>
                                        {l.due_at && (
                                            <Badge
                                                variant={
                                                    l.overdue
                                                        ? 'danger'
                                                        : 'neutral'
                                                }
                                            >
                                                <bdi dir="ltr">
                                                    {l.overdue
                                                        ? t(
                                                              'field_sales.overdue',
                                                              { at: l.due_at },
                                                          )
                                                        : t('field_sales.due', {
                                                              at: l.due_at,
                                                          })}
                                                </bdi>
                                            </Badge>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section
                        title={t('field_sales.team.visits', {
                            count: visits.length,
                        })}
                    >
                        {visits.length === 0 ? (
                            <p className="m-0 text-caption text-weak">
                                {t('field_sales.team.no_visits')}
                            </p>
                        ) : (
                            <ul className="m-0 flex list-none flex-col gap-2 p-0">
                                {visits.map((v) => (
                                    <li
                                        key={v.id}
                                        className="flex flex-wrap items-center gap-2"
                                        data-test="rep-visit"
                                    >
                                        <bdi
                                            dir="ltr"
                                            className="text-label text-strong"
                                        >
                                            {v.at}
                                        </bdi>
                                        {v.today && (
                                            <Badge variant="brand">
                                                {t('field.today')}
                                            </Badge>
                                        )}
                                        <Badge variant="neutral">
                                            {t(
                                                `appointments.kind.${v.kind}` as TranslationKey,
                                            )}
                                        </Badge>
                                        <Link
                                            href={AppointmentController.show.url(
                                                v.id,
                                            )}
                                            className="text-link"
                                        >
                                            {v.customer} · {v.order}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                </div>

                {tasks.length > 0 && (
                    <Section
                        title={t('field_sales.team.overdue_tasks', {
                            count: tasks.length,
                        })}
                    >
                        <ul className="m-0 flex list-none flex-col gap-2 p-0">
                            {tasks.map((task) => (
                                <li
                                    key={task.id}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <Link
                                        href={TaskController.show.url(task.id)}
                                        className="text-link"
                                    >
                                        {task.order}
                                    </Link>
                                    <Badge variant="danger">
                                        <bdi dir="ltr">
                                            {t('field_sales.overdue', {
                                                at: task.due_at ?? '',
                                            })}
                                        </bdi>
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Card className="gap-0 p-0 md:p-0">
                    <h2 className="m-0 px-4 pt-4 text-title text-strong">
                        {t('field_sales.team.orders', { count: orders.length })}
                    </h2>
                    <TableWrap>
                        <Table
                            caption={t('field_sales.team.orders', {
                                count: orders.length,
                            })}
                        >
                            <thead>
                                <tr>
                                    <Th>{t('field_sales.team.col.order')}</Th>
                                    <Th>{t('field_sales.team.col.stage')}</Th>
                                    <Th>{t('field_sales.team.col.due')}</Th>
                                    <Th>
                                        {t('field_sales.team.col.delivery')}
                                    </Th>
                                    <Th>
                                        {t('field_sales.team.col.installation')}
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.map((o) => (
                                    <tr key={o.id} data-test="rep-order">
                                        <Td>
                                            <Link
                                                href={OrderController.show.url(
                                                    o.id,
                                                )}
                                                className="font-semibold text-link"
                                            >
                                                <bdi dir="ltr">{o.number}</bdi>
                                            </Link>
                                            <div className="text-caption text-weak">
                                                {o.customer}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div>
                                                {o.stage ??
                                                    t(
                                                        `orders.status.${o.status}` as TranslationKey,
                                                    )}
                                            </div>
                                            <div className="text-caption text-weak">
                                                {t('field_sales.team.stages', {
                                                    done: o.stages_done,
                                                    total: o.stages_total,
                                                })}
                                            </div>
                                        </Td>
                                        <Td>
                                            {o.due_left_minor === null ? (
                                                <span className="text-weak">
                                                    —
                                                </span>
                                            ) : (
                                                <Amount
                                                    value={
                                                        o.due_left_minor / 100
                                                    }
                                                />
                                            )}
                                        </Td>
                                        <Td>
                                            {t(
                                                `field_sales.team.delivery.${o.delivery}` as TranslationKey,
                                            )}
                                        </Td>
                                        <Td>
                                            {o.installation
                                                ? t(
                                                      `installation.status.${o.installation}` as TranslationKey,
                                                  )
                                                : '—'}
                                            {o.open_remarks > 0 && (
                                                <Link
                                                    href={`${OrderController.show.url(o.id)}#installation`}
                                                    className="ms-2"
                                                >
                                                    <Badge variant="warning">
                                                        {t(
                                                            'field_sales.team.open_remarks',
                                                            {
                                                                count: o.open_remarks,
                                                            },
                                                        )}
                                                    </Badge>
                                                </Link>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            </div>
        </>
    );
}
