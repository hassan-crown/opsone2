import { Head, Link, router } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';
import CustomerController from '@/actions/App/Http/Controllers/Customers/CustomerController';
import InterestController from '@/actions/App/Http/Controllers/Customers/InterestController';
import { FilterBar } from '@/components/customers/filter-bar';
import { Pager } from '@/components/customers/pager';
import type {
    InterestCan,
    InterestRow,
    InterestStatus,
    Meta,
    Option,
} from '@/components/customers/types';
import { EmptyState } from '@/components/opsone/empty-state';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Props = {
    interests: (InterestRow & { can: InterestCan })[];
    meta: Meta;
    counts: Record<InterestStatus, number>;
    filters: {
        q: string;
        status: string;
        channel: string;
        assignee: string;
        branch: string;
    };
    members: Option[];
    branches: Option[];
    canCreate: boolean;
};

const TONE: Record<InterestStatus, 'info' | 'neutral' | 'success'> = {
    new: 'info',
    following: 'neutral',
    converted: 'success',
    closed: 'neutral',
};

const CHANNELS = [
    'phone',
    'whatsapp',
    'email',
    'walk_in',
    'website',
    'other',
] as const;

export default function InterestsIndex({
    interests,
    meta,
    counts,
    filters,
    members,
    branches,
    canCreate,
}: Props) {
    const t = useT();
    const { locale } = useLocale();
    const url = InterestController.index.url();
    const filtered =
        Object.entries(filters).some(([k, v]) => k !== 'status' && v !== '') ||
        filters.status !== '';
    const { status, ...rest } = filters;
    const tabs: { value: string; label: string; count?: number }[] = [
        { value: '', label: t('interests.tab.all') },
        ...(
            ['new', 'following', 'converted', 'closed'] as InterestStatus[]
        ).map((s) => ({
            value: s,
            label: t(`interests.status.${s}` as TranslationKey),
            count: counts[s],
        })),
    ];
    const goTab = (value: string) =>
        router.get(
            url,
            Object.fromEntries(
                Object.entries({ ...rest, status: value }).filter(
                    ([, v]) => v !== '',
                ),
            ),
            {
                preserveState: true,
            },
        );

    return (
        <>
            <Head title={t('interests.title')} />
            <PageHeader
                title={t('interests.title')}
                description={t('interests.description')}
                actions={
                    <>
                        <Button asChild variant="secondary">
                            <Link href={CustomerController.index()}>
                                {t('customers.title')}
                            </Link>
                        </Button>
                        {canCreate && (
                            <Button asChild data-test="add-interest">
                                <Link href={InterestController.create()}>
                                    <Icon icon={Plus} />
                                    {t('interests.add')}
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />
            <div
                role="tablist"
                aria-label={t('interests.title')}
                className="mb-4 flex flex-wrap gap-2"
            >
                {tabs.map((tab) => (
                    <Button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={status === tab.value}
                        variant={status === tab.value ? 'default' : 'secondary'}
                        size="sm"
                        onClick={() => goTab(tab.value)}
                        data-test={`tab-${tab.value || 'all'}`}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span dir="ltr"> {tab.count}</span>
                        )}
                    </Button>
                ))}
            </div>
            <FilterBar
                url={url}
                initial={rest}
                extra={{ status }}
                searchLabel="interests.search"
                selects={[
                    {
                        name: 'channel',
                        label: t('interests.col.channel'),
                        options: [
                            { value: '', label: t('customers.filter.all') },
                            ...CHANNELS.map((c) => ({
                                value: c,
                                label: t(
                                    `interests.channel.${c}` as TranslationKey,
                                ),
                            })),
                        ],
                    },
                    {
                        name: 'assignee',
                        label: t('interests.col.assignee'),
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
                {interests.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title={
                            filtered
                                ? t('interests.no_results')
                                : t('interests.empty')
                        }
                        description={
                            filtered ? undefined : t('interests.empty_desc')
                        }
                    />
                ) : (
                    <>
                        <TableWrap>
                            <Table caption={t('interests.caption')}>
                                <thead>
                                    <tr>
                                        <Th>{t('interests.col.reference')}</Th>
                                        <Th>{t('interests.col.name')}</Th>
                                        <Th>{t('interests.col.need')}</Th>
                                        <Th>{t('interests.col.channel')}</Th>
                                        <Th>{t('interests.col.assignee')}</Th>
                                        <Th>{t('interests.col.status')}</Th>
                                        <Th>{t('interests.col.created')}</Th>
                                        <Th>{t('interests.col.actions')}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {interests.map((i) => (
                                        <tr key={i.id} data-test="interest-row">
                                            <Td
                                                dir="ltr"
                                                className="text-start"
                                            >
                                                <Link
                                                    href={InterestController.show(
                                                        i.id,
                                                    )}
                                                    className="text-link hover:underline"
                                                >
                                                    {i.reference}
                                                </Link>
                                            </Td>
                                            <Td className="font-semibold">
                                                {i.name}
                                            </Td>
                                            <Td className="max-w-xs truncate">
                                                {i.need}
                                            </Td>
                                            <Td>
                                                {t(
                                                    `interests.channel.${i.channel}` as TranslationKey,
                                                )}
                                            </Td>
                                            <Td>
                                                {i.assignee ??
                                                    t('interests.no_owner')}
                                            </Td>
                                            <Td>
                                                <Badge variant={TONE[i.status]}>
                                                    {t(
                                                        `interests.status.${i.status}` as TranslationKey,
                                                    )}
                                                </Badge>
                                            </Td>
                                            <Td>
                                                {i.created_at
                                                    ? formatDate(
                                                          i.created_at,
                                                          locale,
                                                          {
                                                              dateStyle:
                                                                  'medium',
                                                          },
                                                      )
                                                    : '—'}
                                            </Td>
                                            <Td>
                                                {i.can.follow && (
                                                    <Button
                                                        type="button"
                                                        variant="secondary"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                InterestController.follow.url(
                                                                    i.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'interests.action.follow',
                                                        )}
                                                    </Button>
                                                )}
                                                {!i.can.follow &&
                                                    i.can.convert && (
                                                        <Button
                                                            asChild
                                                            variant="secondary"
                                                            size="sm"
                                                        >
                                                            <Link
                                                                href={InterestController.show(
                                                                    i.id,
                                                                )}
                                                            >
                                                                {t(
                                                                    'interests.action.convert',
                                                                )}
                                                            </Link>
                                                        </Button>
                                                    )}
                                                {i.status === 'converted' &&
                                                    i.converted_customer_id && (
                                                        <Button
                                                            asChild
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            <Link
                                                                href={CustomerController.show(
                                                                    i.converted_customer_id,
                                                                )}
                                                            >
                                                                {t(
                                                                    'interests.action.open_customer',
                                                                )}
                                                            </Link>
                                                        </Button>
                                                    )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </TableWrap>
                        <Pager
                            meta={meta}
                            url={url}
                            query={Object.fromEntries(
                                Object.entries(filters).filter(
                                    ([, v]) => v !== '',
                                ),
                            )}
                        />
                    </>
                )}
            </Card>
        </>
    );
}

InterestsIndex.layout = {
    breadcrumbs: [{ title: 'interests.nav', href: InterestController.index() }],
};
