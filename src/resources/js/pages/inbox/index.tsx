import { Head, Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import { FilterBar, Pager } from '@/components/catalog/shared';
import { EmptyState } from '@/components/opsone/empty-state';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { Page } from '@/types/catalog';
import type { ConversationRow } from '@/types/inbox';

type View = 'all' | 'mine' | 'unassigned' | 'unread';

type Props = {
    conversations: Page<ConversationRow>;
    filters: { view: View; q: string };
};

const VIEWS: View[] = ['all', 'mine', 'unassigned', 'unread'];

/** WhatsApp inbox (AI-3, P-108): conversations inside the user's scope, newest activity first. */
export default function InboxIndex({ conversations, filters }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const filtered = Boolean(filters.q || filters.view !== 'all');

    return (
        <>
            <Head title={t('inbox.title')} />
            <PageHeader
                title={t('inbox.title')}
                description={t('inbox.description')}
            />
            <FilterBar url={InboxController.index.url()} reset={filtered}>
                <div className="grid gap-1">
                    <Label htmlFor="f-q">{t('inbox.search')}</Label>
                    <Input
                        id="f-q"
                        name="q"
                        type="search"
                        defaultValue={filters.q}
                        className="w-56"
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="f-view">{t('inbox.view')}</Label>
                    <select
                        id="f-view"
                        name="view"
                        defaultValue={filters.view}
                        className="field-control w-48 text-control"
                    >
                        {VIEWS.map((view) => (
                            <option key={view} value={view}>
                                {t(`inbox.views.${view}` as TranslationKey)}
                            </option>
                        ))}
                    </select>
                </div>
            </FilterBar>

            {conversations.data.length === 0 ? (
                <Card>
                    <EmptyState icon={MessageCircle} title={t('inbox.empty')} />
                </Card>
            ) : (
                <Card className="gap-0 p-0 md:p-0">
                    <TableWrap>
                        <Table caption={t('inbox.title')}>
                            <thead>
                                <tr>
                                    <Th>{t('inbox.col_contact')}</Th>
                                    <Th>{t('inbox.col_branch')}</Th>
                                    <Th>{t('inbox.col_assignee')}</Th>
                                    <Th>{t('inbox.col_window')}</Th>
                                    <Th>{t('inbox.col_last')}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {conversations.data.map((c) => (
                                    <tr key={c.id} data-test="conversation-row">
                                        <Td>
                                            <Link
                                                href={InboxController.show.url(
                                                    c.id,
                                                )}
                                                className="font-semibold text-link hover:underline"
                                            >
                                                {c.name ?? (
                                                    <bdi dir="ltr">
                                                        {c.wa_id}
                                                    </bdi>
                                                )}
                                            </Link>
                                            <div className="flex flex-wrap items-center gap-2 text-caption text-weak">
                                                <bdi dir="ltr">{c.wa_id}</bdi>
                                                {!c.known && (
                                                    <Badge variant="neutral">
                                                        {t('inbox.unknown')}
                                                    </Badge>
                                                )}
                                                {c.unread > 0 && (
                                                    <Badge variant="info">
                                                        {t('inbox.unread', {
                                                            count: c.unread,
                                                        })}
                                                    </Badge>
                                                )}
                                            </div>
                                        </Td>
                                        <Td>{c.branch ?? '—'}</Td>
                                        <Td>
                                            {c.assignee?.name ??
                                                t('inbox.unassigned')}
                                        </Td>
                                        <Td>
                                            <Badge
                                                variant={
                                                    c.window_open
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {c.window_open
                                                    ? t('inbox.window.open')
                                                    : t('inbox.window.closed')}
                                            </Badge>
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            {c.last_message_at
                                                ? formatDateTime(
                                                      c.last_message_at,
                                                      locale,
                                                  )
                                                : '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </TableWrap>
                </Card>
            )}
            <Pager
                page={conversations}
                url={InboxController.index.url()}
                query={{
                    q: filters.q,
                    view: filters.view === 'all' ? null : filters.view,
                }}
            />
        </>
    );
}
