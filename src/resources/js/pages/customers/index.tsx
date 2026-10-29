import { Head, Link } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import { FilterBar } from '@/components/customers/filter-bar';
import { Pager } from '@/components/customers/pager';
import type { CustomerRow, Meta, Option } from '@/components/customers/types';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useT } from '@/lib/i18n';
import {
    BlockedAction,
    useLicenseBlock,
} from '@/components/opsone/blocked-action';

type Props = {
    customers: CustomerRow[];
    meta: Meta;
    filters: { q: string; kind: string; assignee: string; branch: string };
    members: Option[];
    branches: Option[];
    canCreate: boolean;
};

export default function CustomersIndex({
    customers,
    meta,
    filters,
    members,
    branches,
    canCreate,
}: Props) {
    const t = useT();
    const blocked = useLicenseBlock('customers_create');
    const url = CustomerController.index.url();
    const filtered = Object.values(filters).some((v) => v !== '');

    return (
        <>
            <Head title={t('customers.title')} />
            <PageHeader
                title={t('customers.title')}
                description={t('customers.description')}
                actions={
                    blocked ? (
                        <BlockedAction
                            label={t('customers.add')}
                            reason={blocked}
                            testId="add-customer"
                        />
                    ) : (
                        canCreate && (
                            <Button asChild data-test="add-customer">
                                <Link href={CustomerController.create()}>
                                    <Icon icon={Plus} />
                                    {t('customers.add')}
                                </Link>
                            </Button>
                        )
                    )
                }
            />
            <FilterBar
                url={url}
                initial={filters}
                searchLabel="customers.search"
                selects={[
                    {
                        name: 'kind',
                        label: t('customers.filter.kind'),
                        options: [
                            { value: '', label: t('customers.filter.all') },
                            {
                                value: 'person',
                                label: t('customers.kind.person'),
                            },
                            {
                                value: 'organization',
                                label: t('customers.kind.organization'),
                            },
                        ],
                    },
                    {
                        name: 'assignee',
                        label: t('customers.filter.assignee'),
                        options: [
                            { value: '', label: t('customers.filter.all') },
                            {
                                value: 'none',
                                label: t('customers.filter.unassigned'),
                            },
                            ...members.map((m) => ({
                                value: String(m.id),
                                label: m.name,
                            })),
                        ],
                    },
                    {
                        name: 'branch',
                        label: t('customers.filter.branch'),
                        options: [
                            { value: '', label: t('customers.filter.all') },
                            ...branches.map((b) => ({
                                value: String(b.id),
                                label: b.name,
                            })),
                        ],
                    },
                ]}
            />
            <Card className="gap-0 p-0 md:p-0">
                {customers.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={
                            filtered
                                ? t('customers.no_results')
                                : t('customers.empty')
                        }
                        description={
                            filtered ? undefined : t('customers.empty_desc')
                        }
                    />
                ) : (
                    <>
                        <TableWrap>
                            <Table caption={t('customers.caption')}>
                                <thead>
                                    <tr>
                                        <Th>{t('customers.col.customer')}</Th>
                                        <Th>{t('customers.col.mobile')}</Th>
                                        <Th>{t('customers.col.email')}</Th>
                                        <Th>{t('customers.col.branch')}</Th>
                                        <Th>{t('customers.col.assignee')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customers.map((c) => (
                                        <tr key={c.id} data-test="customer-row">
                                            <Td>
                                                <Link
                                                    href={CustomerController.show(
                                                        c.id,
                                                    )}
                                                    className="font-semibold text-link hover:underline"
                                                >
                                                    {c.name}
                                                </Link>{' '}
                                                {c.kind === 'organization' && (
                                                    <Badge variant="info">
                                                        {t(
                                                            'customers.kind.organization',
                                                        )}
                                                    </Badge>
                                                )}
                                            </Td>
                                            <Td
                                                dir="ltr"
                                                className="text-start"
                                            >
                                                {c.mobile}
                                            </Td>
                                            <Td
                                                dir="ltr"
                                                className="text-start"
                                            >
                                                {c.email ?? '—'}
                                            </Td>
                                            <Td>{c.branch ?? '—'}</Td>
                                            <Td>{c.assignee ?? '—'}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                        <Pager meta={meta} url={url} query={filters} />
                    </>
                )}
            </Card>
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'customers.nav', href: CustomerController.index() }],
};
