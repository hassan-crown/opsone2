import { Head, Link } from '@inertiajs/react';
import { BadgePercent, Settings2 } from 'lucide-react';
import CommissionController from '@/actions/App/Http/Controllers/Commissions/CommissionController';
import OrderSaleController from '@/actions/App/Http/Controllers/Commissions/OrderSaleController';
import { Minor, Rate, CorrectionMove } from '@/components/commissions/parts';
import type { Correction, MemberRow } from '@/components/commissions/types';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { sales as salesSettings } from '@/routes/settings';

type Props = {
    members: MemberRow[];
    corrections: Correction[];
    policy: { enabled: boolean; default_rate_bp: number };
    full_scope: boolean;
    can: { settings: boolean };
};

/**
 * FinCommissions (SAL-12..15): one row per member in the viewer's scope — rate, accrued, held (orders in settlement),
 * paid and available. A negative balance after a refund is carried, never deducted from a salary.
 */
export default function Commissions({
    members,
    corrections,
    policy,
    can,
}: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('commissions.title')} />
            <PageHeader
                title={t('commissions.title')}
                description={t('commissions.description')}
                actions={
                    can.settings && (
                        <Button asChild variant="secondary">
                            <Link href={salesSettings()}>
                                <Icon icon={Settings2} />
                                {t('commissions.policy')}
                            </Link>
                        </Button>
                    )
                }
            />
            {!policy.enabled && (
                <p className="mb-4 text-body text-weak" data-test="policy-off">
                    {t('commissions.policy_off')}
                </p>
            )}
            <Card>
                {members.length === 0 ? (
                    <EmptyState
                        icon={BadgePercent}
                        title={t('commissions.empty')}
                        description={t('commissions.empty_body')}
                    />
                ) : (
                    <TableWrap>
                        <Table
                            caption={t('commissions.members')}
                            data-test="commission-members"
                        >
                            <thead>
                                <tr>
                                    <Th>{t('commissions.col.member')}</Th>
                                    <Th>{t('commissions.col.rate')}</Th>
                                    <Th className="text-end">
                                        {t('commissions.col.accrued')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('commissions.col.held')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('commissions.col.paid')}
                                    </Th>
                                    <Th className="text-end">
                                        {t('commissions.col.available')}
                                    </Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((m) => (
                                    <tr key={m.id} data-test="member-row">
                                        <Td className="font-semibold">
                                            <Link
                                                href={CommissionController.member.url(
                                                    m.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {m.name}
                                            </Link>
                                        </Td>
                                        <Td>
                                            <Rate bp={m.rate.rate_bp} />
                                        </Td>
                                        <Td className="text-end">
                                            <Minor value={m.accrued_minor} />
                                        </Td>
                                        <Td className="text-end">
                                            <Minor value={m.held_minor} />
                                        </Td>
                                        <Td className="text-end">
                                            <Minor
                                                value={
                                                    m.paid_minor +
                                                    m.reserved_minor
                                                }
                                            />
                                        </Td>
                                        <Td className="text-end">
                                            {m.available_minor < 0 ? (
                                                <span className="inline-flex flex-col items-end">
                                                    <Minor
                                                        value={
                                                            m.available_minor
                                                        }
                                                        strong
                                                    />
                                                    <span className="text-caption text-weak">
                                                        {t(
                                                            'commissions.carried',
                                                        )}
                                                    </span>
                                                </span>
                                            ) : (
                                                <Minor
                                                    value={m.available_minor}
                                                    strong
                                                />
                                            )}
                                        </Td>
                                        <Td className="text-end">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant={
                                                    m.can_pay &&
                                                    m.available_minor > 0
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                <Link
                                                    href={CommissionController.member.url(
                                                        m.id,
                                                    )}
                                                >
                                                    {m.can_pay &&
                                                    m.available_minor > 0
                                                        ? t('commissions.pay')
                                                        : t(
                                                              'commissions.statement',
                                                          )}
                                                </Link>
                                            </Button>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
            <p className="mt-3 text-caption text-weak">
                {t('commissions.held_hint')}
            </p>

            {corrections.length > 0 && (
                <section
                    className="mt-8 space-y-3"
                    data-test="pending-corrections"
                >
                    <h2 className="text-heading text-strong">
                        {t('commissions.corrections.pending')}
                    </h2>
                    <ul className="grid gap-2">
                        {corrections.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-stroke-weak p-3"
                            >
                                <span className="flex flex-wrap items-center gap-2">
                                    <bdi dir="ltr" className="font-semibold">
                                        {c.order.number}
                                    </bdi>
                                    <Badge variant="warning">
                                        {t(
                                            `commissions.corrections.kind.${c.kind}` as TranslationKey,
                                        )}
                                    </Badge>
                                    <span className="text-weak">
                                        <CorrectionMove correction={c} />
                                    </span>
                                </span>
                                <Button asChild size="sm" variant="secondary">
                                    <Link
                                        href={OrderSaleController.show.url(
                                            c.order.id,
                                        )}
                                    >
                                        {t('commissions.corrections.open')}
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
