import { Head, router, useForm } from '@inertiajs/react';
import { Copy, Link2, Plus, Trash2 } from 'lucide-react';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { StoreNav } from '@/components/store/store-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type StoreLinkRow = {
    id: number;
    number: string;
    status:
        | 'draft'
        | 'active'
        | 'checking_out'
        | 'used'
        | 'expired'
        | 'cancelled';
    lapsed: boolean;
    expires_at: string;
    lock_version: number;
    lines: number;
    note: string | null;
    url: string | null;
    order: string | null;
};
type Variant = {
    id: number;
    name: string;
    price_minor: number;
    available: number;
};
type Props = {
    links: StoreLinkRow[];
    variants: Variant[];
    settings: { link_hours_default: number; link_days_max: number };
    open: boolean;
    issued: string | null;
};

const VARIANT = {
    draft: 'neutral',
    active: 'success',
    checking_out: 'info',
    used: 'brand',
    expired: 'neutral',
    cancelled: 'danger',
} as const;

/** LinksMobile (SAL-01/02, P-92/P-93): my basket links — issue, copy, cancel or replace. Mobile first. */
export default function Links({
    links,
    variants,
    settings,
    open,
    issued,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const form = useForm({
        lines: [{ variant_id: variants[0]?.id ?? 0, quantity: 1 }],
        hours: String(settings.link_hours_default),
        note: '',
    });

    return (
        <>
            <Head title={t('links.title')} />
            <PageHeader
                title={t('links.title')}
                description={t('links.description')}
            />
            <StoreNav active="links" />
            {issued && (
                <p
                    role="status"
                    className="mb-4 rounded-md bg-success-bg px-4 py-3 text-success-text"
                >
                    {t('links.issued', { number: issued })}
                </p>
            )}
            {!open && (
                <p
                    role="alert"
                    className="mb-4 rounded-md bg-warning-bg px-4 py-3 text-warning-text"
                >
                    {t('links.closed')}
                </p>
            )}

            {open && variants.length > 0 && (
                <form
                    className="mb-6 grid gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/store-admin/links', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <h2 className="text-subtitle">{t('links.new')}</h2>
                    {form.data.lines.map((line, i) => (
                        <div
                            key={i}
                            className="grid grid-cols-[1fr_6rem_auto] items-end gap-2"
                        >
                            <SelectField
                                label={t('links.item')}
                                value={String(line.variant_id)}
                                options={variants.map((v) => ({
                                    value: String(v.id),
                                    label: `${v.name} (${v.available})`,
                                    disabled: v.available < 1,
                                }))}
                                onChange={(e) =>
                                    form.setData(
                                        'lines',
                                        form.data.lines.map((l, j) =>
                                            j === i
                                                ? {
                                                      ...l,
                                                      variant_id: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : l,
                                        ),
                                    )
                                }
                            />
                            <TextField
                                label={t('links.quantity')}
                                type="number"
                                min={1}
                                value={String(line.quantity)}
                                onChange={(e) =>
                                    form.setData(
                                        'lines',
                                        form.data.lines.map((l, j) =>
                                            j === i
                                                ? {
                                                      ...l,
                                                      quantity: Number(
                                                          e.target.value,
                                                      ),
                                                  }
                                                : l,
                                        ),
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="outline"
                                aria-label={t('links.remove_line')}
                                disabled={form.data.lines.length === 1}
                                onClick={() =>
                                    form.setData(
                                        'lines',
                                        form.data.lines.filter(
                                            (_, j) => j !== i,
                                        ),
                                    )
                                }
                            >
                                <Icon icon={Trash2} />
                            </Button>
                        </div>
                    ))}
                    {form.errors.lines && (
                        <p className="text-caption text-danger-text">
                            {form.errors.lines}
                        </p>
                    )}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('lines', [
                                    ...form.data.lines,
                                    { variant_id: variants[0].id, quantity: 1 },
                                ])
                            }
                        >
                            <Icon icon={Plus} />
                            {t('links.add_line')}
                        </Button>
                    </div>
                    <TextField
                        label={t('links.hours')}
                        hint={t('links.hours_hint', {
                            max: settings.link_days_max * 24,
                        })}
                        type="number"
                        min={1}
                        max={settings.link_days_max * 24}
                        value={form.data.hours}
                        onChange={(e) => form.setData('hours', e.target.value)}
                        error={form.errors.hours}
                    />
                    <TextField
                        label={t('links.note')}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        optional
                    />
                    <p className="text-caption text-weak">
                        {t('links.no_reservation')}
                    </p>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {t('links.issue')}
                        </Button>
                    </div>
                </form>
            )}

            {links.length === 0 ? (
                <EmptyState icon={Link2} title={t('links.empty')} />
            ) : (
                <ul className="grid gap-3">
                    {links.map((l) => (
                        <li
                            key={l.id}
                            className="grid gap-2 rounded-lg border border-stroke-weak bg-surface p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span dir="ltr" className="font-mono">
                                    {l.number}
                                </span>
                                <Badge variant={VARIANT[l.status]}>
                                    {t(
                                        `links.status.${l.status}` as TranslationKey,
                                    )}
                                </Badge>
                            </div>
                            <span className="text-caption text-weak">
                                {t('links.lines', { count: l.lines })} ·{' '}
                                {t('links.until', {
                                    at: formatDateTime(l.expires_at, locale),
                                })}
                                {l.order && (
                                    <>
                                        {' '}
                                        ·{' '}
                                        {t('links.order', { number: l.order })}
                                    </>
                                )}
                            </span>
                            {l.note && (
                                <span className="text-caption">{l.note}</span>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {l.url && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            void navigator.clipboard?.writeText(
                                                l.url ?? '',
                                            )
                                        }
                                    >
                                        <Icon icon={Copy} />
                                        {t('links.copy')}
                                    </Button>
                                )}
                                {l.status === 'active' && (
                                    <>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/store-admin/links/${l.id}/replace`,
                                                    {
                                                        lock_version:
                                                            l.lock_version,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('links.replace')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/store-admin/links/${l.id}/cancel`,
                                                    {
                                                        lock_version:
                                                            l.lock_version,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('links.cancel')}
                                        </Button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-6 text-caption text-weak">
                {t('links.price_note')}
            </p>
        </>
    );
}
