import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScrollText } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import { EmptyState } from '@/components/opsone/empty-state';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Table, TableWrap, Td, Th } from '@/components/org/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime, formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

type Filters = {
    area: string | null;
    actor: number | null;
    from: string | null;
    to: string | null;
    q: string;
};

type EventRow = {
    id: number;
    action: string;
    area: string;
    actor: string | null;
    source: string;
    subject: string | null;
    subject_type: string | null;
    has_reason: boolean;
    occurred_at: string;
};

type Values = Record<string, unknown>;

type EventDetail = EventRow & {
    subject_id: number | null;
    before: Values | null;
    after: Values | null;
    reason: string | null;
    request_id: string | null;
    user_agent: string | null;
    ip_ref: string | null;
    branches: string[];
};

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    filters: Filters;
    events: Page<EventRow>;
    areas: string[];
    actors: { id: number; name: string }[];
    selected: EventDetail | null;
};

const visitOptions = { preserveState: true, preserveScroll: true } as const;

function useLabels() {
    const t = useT();

    return {
        action: (action: string) =>
            t(`audit.action.${action}` as TranslationKey),
        area: (area: string) => t(`audit.area.${area}` as TranslationKey),
        source: (source: string) =>
            t(`audit.source.${source}` as TranslationKey),
        actor: (row: EventRow) => row.actor ?? t('audit.system_actor'),
    };
}

function AuditFilters({
    filters,
    areas,
    actors,
}: Omit<Props, 'events' | 'selected'>) {
    const t = useT();
    const labels = useLabels();
    const [draft, setDraft] = useState(filters);

    const visit = (next: Filters) =>
        router.get(AuditLogController.index.url(), clean(next), {
            ...visitOptions,
            replace: true,
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(draft);
    };

    const reset = () => {
        const empty: Filters = {
            area: null,
            actor: null,
            from: null,
            to: null,
            q: '',
        };
        setDraft(empty);
        visit(empty);
    };

    return (
        <form
            onSubmit={submit}
            role="search"
            aria-label={t('audit.filters')}
            className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))] lg:items-end"
        >
            <TextField
                label={t('audit.search')}
                name="q"
                type="search"
                value={draft.q}
                onChange={(e) => setDraft({ ...draft, q: e.target.value })}
            />
            <SelectField
                label={t('audit.filter_area')}
                value={draft.area ?? ''}
                onChange={(e) =>
                    setDraft({ ...draft, area: e.target.value || null })
                }
                options={[
                    { value: '', label: t('audit.filter_all') },
                    ...areas.map((area) => ({
                        value: area,
                        label: labels.area(area),
                    })),
                ]}
            />
            <SelectField
                label={t('audit.filter_actor')}
                value={draft.actor ? String(draft.actor) : ''}
                onChange={(e) =>
                    setDraft({
                        ...draft,
                        actor: e.target.value ? Number(e.target.value) : null,
                    })
                }
                options={[
                    { value: '', label: t('audit.filter_all') },
                    ...actors.map((a) => ({
                        value: String(a.id),
                        label: a.name,
                    })),
                ]}
            />
            <TextField
                label={t('audit.filter_from')}
                name="from"
                type="date"
                value={draft.from ?? ''}
                onChange={(e) =>
                    setDraft({ ...draft, from: e.target.value || null })
                }
            />
            <TextField
                label={t('audit.filter_to')}
                name="to"
                type="date"
                value={draft.to ?? ''}
                onChange={(e) =>
                    setDraft({ ...draft, to: e.target.value || null })
                }
            />
            <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                <Button type="submit" variant="secondary">
                    {t('audit.apply')}
                </Button>
                <Button type="button" variant="ghost" onClick={reset}>
                    {t('audit.reset')}
                </Button>
            </div>
        </form>
    );
}

function clean(filters: Filters): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, v]) => v !== null && v !== ''),
    ) as Record<string, string | number>;
}

function EventList({
    events,
    onOpen,
}: {
    events: EventRow[];
    onOpen: (id: number) => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const labels = useLabels();

    return (
        <Card className="gap-0 p-0 md:p-0">
            {/* Small screens: one card per event. */}
            <ul
                className="divide-y divide-stroke-weak md:hidden"
                aria-label={t('audit.caption')}
            >
                {events.map((row) => (
                    <li key={row.id}>
                        <button
                            type="button"
                            onClick={() => onOpen(row.id)}
                            className="flex w-full flex-col items-start gap-1 px-4 py-3 text-start hover:bg-hover"
                            data-test="audit-card"
                        >
                            <span className="flex w-full items-center justify-between gap-2">
                                <span className="font-semibold text-strong">
                                    {labels.action(row.action)}
                                </span>
                                <Badge variant="neutral">
                                    {labels.area(row.area)}
                                </Badge>
                            </span>
                            <span className="text-body text-weak">
                                {labels.actor(row)}
                                {row.subject && ` · ${row.subject}`}
                            </span>
                            <span className="text-caption text-weak">
                                {formatDateTime(row.occurred_at, locale)}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
            <TableWrap className="hidden md:block">
                <Table caption={t('audit.caption')}>
                    <thead>
                        <tr>
                            <Th>{t('audit.col_time')}</Th>
                            <Th>{t('audit.col_action')}</Th>
                            <Th>{t('audit.col_subject')}</Th>
                            <Th>{t('audit.col_actor')}</Th>
                            <Th className="text-end">
                                <span className="sr-only">
                                    {t('audit.view')}
                                </span>
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {events.map((row) => (
                            <tr key={row.id} data-test="audit-row">
                                <Td className="whitespace-nowrap text-weak">
                                    {formatDateTime(row.occurred_at, locale)}
                                </Td>
                                <Td>
                                    <span className="flex flex-col gap-1">
                                        <span className="font-semibold">
                                            {labels.action(row.action)}
                                        </span>
                                        <span>
                                            <Badge variant="neutral">
                                                {labels.area(row.area)}
                                            </Badge>
                                        </span>
                                    </span>
                                </Td>
                                <Td>{row.subject ?? '—'}</Td>
                                <Td>
                                    <span className="flex flex-col">
                                        <span>{labels.actor(row)}</span>
                                        <span className="text-caption text-weak">
                                            {labels.source(row.source)}
                                        </span>
                                    </span>
                                </Td>
                                <Td className="text-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onOpen(row.id)}
                                    >
                                        {t('audit.view')}
                                    </Button>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </TableWrap>
        </Card>
    );
}

function Pager({ page, filters }: { page: Page<EventRow>; filters: Filters }) {
    const t = useT();
    const { locale } = useLocale();

    if (page.last_page <= 1) {
        return null;
    }

    const go = (target: number) =>
        router.get(
            AuditLogController.index.url(),
            { ...clean(filters), page: target },
            { preserveState: true },
        );
    const Prev = locale === 'ar' ? ChevronRight : ChevronLeft;
    const Next = locale === 'ar' ? ChevronLeft : ChevronRight;

    return (
        <nav
            aria-label={t('audit.pagination')}
            className="mt-4 flex flex-wrap items-center justify-between gap-3"
        >
            <span className="text-body text-weak tabular-nums">
                {t('audit.page_of', {
                    page: formatNumber(page.current_page),
                    pages: formatNumber(page.last_page),
                    total: formatNumber(page.total),
                })}
            </span>
            <span className="flex gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={page.prev_page_url === null}
                    onClick={() => go(page.current_page - 1)}
                >
                    <Icon icon={Prev} />
                    {t('audit.previous')}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    disabled={page.next_page_url === null}
                    onClick={() => go(page.current_page + 1)}
                >
                    {t('audit.next')}
                    <Icon icon={Next} />
                </Button>
            </span>
        </nav>
    );
}

function display(value: unknown): string {
    if (value === undefined) {
        return '—';
    }
    if (value === null) {
        return '∅';
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
}

function Changes({
    before,
    after,
}: {
    before: Values | null;
    after: Values | null;
}) {
    const t = useT();
    const keys = Array.from(
        new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]),
    );

    if (keys.length === 0) {
        return <p className="text-body text-weak">{t('audit.no_changes')}</p>;
    }

    return (
        <TableWrap>
            <Table caption={t('audit.changes')} className="min-w-0">
                <thead>
                    <tr>
                        <Th>{t('audit.col_field')}</Th>
                        <Th>{t('audit.col_before')}</Th>
                        <Th>{t('audit.col_after')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {keys.map((key) => (
                        <tr key={key}>
                            <Td className="text-caption" dir="ltr">
                                {key}
                            </Td>
                            <Td className="text-caption break-all" dir="ltr">
                                {display(before?.[key])}
                            </Td>
                            <Td className="text-caption break-all" dir="ltr">
                                {display(after?.[key])}
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </TableWrap>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-caption text-weak">{label}</dt>
            <dd className="text-body text-strong">{children}</dd>
        </div>
    );
}

function EventDrawer({
    event,
    onClose,
}: {
    event: EventDetail | null;
    onClose: () => void;
}) {
    const t = useT();
    const { locale } = useLocale();
    const labels = useLabels();

    return (
        <Sheet
            open={event !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                className="w-full overflow-y-auto sm:max-w-lg"
                data-test="audit-drawer"
            >
                {event && (
                    <>
                        <SheetHeader>
                            <SheetTitle>
                                {labels.action(event.action)}
                            </SheetTitle>
                            <SheetDescription>
                                {formatDateTime(event.occurred_at, locale)}
                            </SheetDescription>
                        </SheetHeader>
                        <div className="flex flex-col gap-5 px-4 pb-6">
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Fact label={t('audit.col_actor')}>
                                    {labels.actor(event)} ·{' '}
                                    {labels.source(event.source)}
                                </Fact>
                                <Fact label={t('audit.col_subject')}>
                                    {event.subject ?? '—'}
                                    {event.subject_type && (
                                        <span
                                            className="block text-caption text-weak"
                                            dir="ltr"
                                        >
                                            {event.subject_type} #
                                            {event.subject_id}
                                        </span>
                                    )}
                                </Fact>
                                {event.branches.length > 0 && (
                                    <Fact label={t('audit.branches')}>
                                        {event.branches.join('، ')}
                                    </Fact>
                                )}
                                <Fact label={t('audit.filter_area')}>
                                    {labels.area(event.area)}
                                </Fact>
                            </dl>
                            {event.reason && (
                                <section className="grid gap-1">
                                    <h3 className="text-label text-strong">
                                        {t('audit.reason')}
                                    </h3>
                                    <p className="text-body whitespace-pre-line text-strong">
                                        {event.reason}
                                    </p>
                                </section>
                            )}
                            <section className="grid gap-2">
                                <h3 className="text-label text-strong">
                                    {t('audit.changes')}
                                </h3>
                                <Changes
                                    before={event.before}
                                    after={event.after}
                                />
                            </section>
                            <section className="grid gap-2">
                                <h3 className="text-label text-strong">
                                    {t('audit.technical')}
                                </h3>
                                <dl className="grid gap-3">
                                    <Fact label={t('audit.request_id')}>
                                        <span dir="ltr" className="break-all">
                                            {event.request_id ?? '—'}
                                        </span>
                                    </Fact>
                                    <Fact label={t('audit.ip_ref')}>
                                        <span dir="ltr">
                                            {event.ip_ref ?? '—'}
                                        </span>
                                    </Fact>
                                    <Fact label={t('audit.user_agent')}>
                                        <span dir="ltr" className="break-all">
                                            {event.user_agent ?? '—'}
                                        </span>
                                    </Fact>
                                </dl>
                                <p className="text-caption text-weak">
                                    {t('audit.immutable_note')}
                                </p>
                            </section>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

export default function AuditLog(props: Props) {
    const t = useT();
    const query = { ...clean(props.filters), page: props.events.current_page };

    const open = (id: number) =>
        router.get(
            AuditLogController.index.url(),
            { ...query, event: id },
            {
                ...visitOptions,
                only: ['selected'],
            },
        );
    const close = () =>
        router.get(AuditLogController.index.url(), query, {
            ...visitOptions,
            only: ['selected'],
        });

    return (
        <>
            <Head title={t('audit.title')} />
            <PageHeader
                title={t('audit.title')}
                description={t('audit.description')}
            />
            <AuditFilters
                filters={props.filters}
                areas={props.areas}
                actors={props.actors}
            />
            {props.events.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={ScrollText}
                        title={t('audit.empty')}
                        description={t('audit.empty_desc')}
                    />
                </Card>
            ) : (
                <>
                    <EventList events={props.events.data} onOpen={open} />
                    <Pager page={props.events} filters={props.filters} />
                </>
            )}
            <EventDrawer event={props.selected} onClose={close} />
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [{ title: 'audit.title', href: AuditLogController.index() }],
};
