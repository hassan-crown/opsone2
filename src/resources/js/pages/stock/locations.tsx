import { Head, router } from '@inertiajs/react';
import { MapPin, Plus } from 'lucide-react';
import { useState } from 'react';
import StockLocationController from '@/actions/App/Http/Controllers/Inventory/StockLocationController';
import { Shell } from '@/components/finance/dialogs';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { notify } from '@/components/opsone/toast';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { StockStatus, StockTabs, useStockCall } from '@/components/stock/parts';
import type { StockLocation } from '@/components/stock/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';

type Option = { id: number; name: string };
type Props = {
    locations: StockLocation[];
    branches: Option[];
    members: Option[];
    can: { create: boolean; facility_wide: boolean };
};

/**
 * StockLocations (FUL-01): code, bilingual name, optional branch, status and person in charge. A location without a
 * branch is facility-wide; only facility grants manage it.
 */
export default function StockLocations({ locations, branches, members, can }: Props) {
    const t = useT();
    const [editing, setEditing] = useState<StockLocation | 'new' | null>(null);

    return (
        <>
            <Head title={t('stock.locations.title')} />
            <PageHeader
                title={t('stock.locations.title')}
                description={t('stock.locations.description')}
                actions={
                    can.create && (
                        <Button onClick={() => setEditing('new')} data-test="new-location">
                            <Icon icon={Plus} />
                            {t('stock.locations.add')}
                        </Button>
                    )
                }
            />
            <StockTabs active="locations" />
            <Card className="p-0 md:p-0">
                {locations.length === 0 ? (
                    <EmptyState icon={MapPin} title={t('stock.locations.empty')} />
                ) : (
                    <TableWrap>
                        <Table caption={t('stock.locations.title')} data-test="locations">
                            <thead>
                                <tr>
                                    <Th>{t('stock.field.code')}</Th>
                                    <Th>{t('stock.field.name')}</Th>
                                    <Th>{t('stock.field.branch')}</Th>
                                    <Th>{t('stock.field.manager')}</Th>
                                    <Th>{t('stock.field.status')}</Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {locations.map((l) => (
                                    <tr key={l.id}>
                                        <Td>
                                            <bdi dir="ltr" className="font-semibold">{l.code}</bdi>
                                            {l.is_default && <Badge variant="brand" className="ms-2">{t('stock.locations.default')}</Badge>}
                                        </Td>
                                        <Td>{l.name}</Td>
                                        <Td>{l.branch?.name ?? t('stock.locations.facility_wide')}</Td>
                                        <Td>{l.manager?.name ?? '—'}</Td>
                                        <Td><StockStatus status={l.status} /></Td>
                                        <Td className="text-end">
                                            {l.can?.edit && (
                                                <Button size="sm" variant="ghost" onClick={() => setEditing(l)}>
                                                    {t('stock.edit')}
                                                </Button>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                )}
            </Card>
            {editing && (
                <LocationDialog
                    location={editing === 'new' ? null : editing}
                    branches={branches}
                    members={members}
                    facilityWide={can.facility_wide}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function LocationDialog({
    location,
    branches,
    members,
    facilityWide,
    onClose,
}: {
    location: StockLocation | null;
    branches: Option[];
    members: Option[];
    facilityWide: boolean;
    onClose: () => void;
}) {
    const t = useT();
    const call = useStockCall();
    const [form, setForm] = useState({
        code: location?.code ?? '',
        ar: location?.name_i18n.ar ?? '',
        en: location?.name_i18n.en ?? '',
        branch: location?.branch ? String(location.branch.id) : facilityWide ? '' : branches[0] ? String(branches[0].id) : '',
        manager: location?.manager ? String(location.manager.id) : '',
        status: location?.status ?? 'active',
    });
    const submit = async () => {
        const body = {
            code: form.code,
            name: { ar: form.ar, en: form.en },
            branch_id: form.branch ? Number(form.branch) : null,
            manager_id: form.manager ? Number(form.manager) : null,
            status: form.status,
            lock_version: location?.lock_version ?? null,
        };
        const done = location
            ? await call.run('PUT', StockLocationController.update.url(location.id), body)
            : await call.run('POST', StockLocationController.store.url(), body);
        if (done) {
            notify(t('stock.locations.saved'), 'success');
            onClose();
            router.reload();
        }
    };

    return (
        <Shell
            title={location ? t('stock.locations.edit') : t('stock.locations.add')}
            description={t('stock.locations.form_hint')}
            error={call.error}
            onClose={onClose}
            test="location-dialog"
            footer={<Button disabled={call.busy} onClick={() => void submit()}>{t('stock.save')}</Button>}
        >
            <TextField label={t('stock.field.code')} dir="ltr" required value={form.code} error={call.fieldError('code')}
                onChange={(e) => setForm({ ...form, code: e.target.value })} />
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField label={t('stock.field.name_ar')} dir="rtl" value={form.ar} error={call.fieldError('name.ar')}
                    onChange={(e) => setForm({ ...form, ar: e.target.value })} />
                <TextField label={t('stock.field.name_en')} dir="ltr" value={form.en} error={call.fieldError('name.en')}
                    onChange={(e) => setForm({ ...form, en: e.target.value })} />
            </div>
            <SelectField
                label={t('stock.field.branch')}
                value={form.branch}
                options={[...(facilityWide ? [{ value: '', label: t('stock.locations.facility_wide') }] : []), ...branches.map((b) => ({ value: String(b.id), label: b.name }))]}
                error={call.fieldError('branch_id')}
                onChange={(e) => setForm({ ...form, branch: e.target.value })}
            />
            <SelectField
                label={t('stock.field.manager')}
                value={form.manager}
                optional
                options={[{ value: '', label: '—' }, ...members.map((m) => ({ value: String(m.id), label: m.name }))]}
                onChange={(e) => setForm({ ...form, manager: e.target.value })}
            />
            {location && (
                <SelectField
                    label={t('stock.field.status')}
                    value={form.status}
                    options={['active', 'disabled'].map((s) => ({ value: s, label: t(s === 'active' ? 'stock.status.active_location' : 'stock.status.disabled') }))}
                    error={call.fieldError('status')}
                    onChange={(e) => setForm({ ...form, status: e.target.value as 'active' | 'disabled' })}
                />
            )}
        </Shell>
    );
}
