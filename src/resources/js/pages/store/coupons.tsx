import { Head, Link, router } from '@inertiajs/react';
import { Plus, TicketPercent } from 'lucide-react';
import { Amount } from '@/components/opsone/amount';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { StoreNav } from '@/components/store/store-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Coupon = {
    id: number;
    code: string;
    title: string;
    kind: 'percent' | 'amount';
    value: number;
    starts_at: string;
    ends_at: string | null;
    status: 'scheduled' | 'active' | 'paused' | 'ended';
    uses: number;
    max_uses: number | null;
    max_uses_per_customer: number | null;
    min_subtotal_minor: number | null;
    scoped: boolean;
};

const VARIANT = {
    scheduled: 'info',
    active: 'success',
    paused: 'warning',
    ended: 'neutral',
} as const;

/** Coupons (SAL-06, P-91): ready-goods coupons with their state, caps and usage. */
export default function Coupons({
    coupons,
    licensed,
}: {
    coupons: Coupon[];
    licensed: boolean;
}) {
    const t = useT();
    const { locale } = useLocale();

    return (
        <>
            <Head title={t('coupons.title')} />
            <PageHeader
                title={t('coupons.title')}
                description={t('coupons.description')}
                actions={
                    licensed && (
                        <Button asChild>
                            <Link href="/store-admin/coupons/new">
                                <Icon icon={Plus} />
                                {t('coupons.new')}
                            </Link>
                        </Button>
                    )
                }
            />
            <StoreNav active="coupons" />
            {!licensed && (
                <p
                    role="alert"
                    className="mb-6 rounded-md bg-warning-bg px-4 py-3 text-warning-text"
                >
                    {t('coupons.unlicensed')}
                </p>
            )}
            {coupons.length === 0 ? (
                <EmptyState icon={TicketPercent} title={t('coupons.empty')} />
            ) : (
                <ul className="grid gap-3">
                    {coupons.map((c) => (
                        <li
                            key={c.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
                        >
                            <div className="grid gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span
                                        dir="ltr"
                                        className="font-mono font-semibold"
                                    >
                                        {c.code}
                                    </span>
                                    <Badge variant={VARIANT[c.status]}>
                                        {t(
                                            `coupons.status.${c.status}` as TranslationKey,
                                        )}
                                    </Badge>
                                </div>
                                <span className="text-body">{c.title}</span>
                                <span className="text-caption text-weak">
                                    {c.kind === 'percent' ? (
                                        t('coupons.percent_off', {
                                            value: c.value / 100,
                                        })
                                    ) : (
                                        <Amount value={c.value / 100} />
                                    )}
                                    {' · '}
                                    {t('coupons.uses', {
                                        uses: c.uses,
                                        max: c.max_uses ?? '∞',
                                    })}
                                    {c.max_uses_per_customer !== null && (
                                        <>
                                            {' '}
                                            ·{' '}
                                            {t('coupons.per_customer', {
                                                max: c.max_uses_per_customer,
                                            })}
                                        </>
                                    )}
                                    {c.scoped && <> · {t('coupons.scoped')}</>}
                                </span>
                                <span className="text-caption text-weak">
                                    {formatDateTime(c.starts_at, locale)}
                                    {c.ends_at && (
                                        <>
                                            {' '}
                                            —{' '}
                                            {formatDateTime(c.ends_at, locale)}
                                        </>
                                    )}
                                </span>
                            </div>
                            {licensed && c.status !== 'ended' && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/store-admin/coupons/${c.id}/pause`,
                                            { paused: c.status !== 'paused' },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {c.status === 'paused'
                                        ? t('coupons.resume')
                                        : t('coupons.pause')}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
