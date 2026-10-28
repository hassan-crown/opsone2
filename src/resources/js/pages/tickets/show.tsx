import { Form, Head } from '@inertiajs/react';
import { Lock, Paperclip } from 'lucide-react';
import { useState } from 'react';
import TicketController from '@/actions/App/Http/Controllers/Tickets/TicketController';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { BackLink } from '@/components/procurement/shared';
import { TicketStatusBadge } from '@/components/portal/ticket-parts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { StaffTicket } from '@/types/tickets';

type Props = { ticket: StaffTicket };

const accept = 'image/jpeg,image/png,image/webp,application/pdf';

/** TicketPage (SHR-08, P-59): the conversation, with internal notes visibly apart, and the M-21 actions. */
export default function TicketShow({ ticket }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const [visibility, setVisibility] = useState<'public' | 'internal'>(
        ticket.can.public_reply ? 'public' : 'internal',
    );
    const [panel, setPanel] = useState<'info' | 'resolve' | 'reopen' | null>(
        null,
    );

    return (
        <>
            <Head title={ticket.number} />
            <PageHeader
                back={
                    <BackLink
                        href={TicketController.index.url()}
                        label={t('tickets.staff.title')}
                    />
                }
                title={ticket.number}
                description={ticket.subject}
                actions={<TicketStatusBadge status={ticket.status} />}
            />

            <Card className="mb-4">
                <dl className="grid gap-3 text-body sm:grid-cols-2">
                    <div>
                        <dt className="text-caption text-weak">
                            {t('tickets.staff.customer')}
                        </dt>
                        <dd className="text-strong">{ticket.customer}</dd>
                    </div>
                    <div>
                        <dt className="text-caption text-weak">
                            {t('tickets.staff.order')}
                        </dt>
                        <dd className="text-strong">
                            {ticket.order ? (
                                <bdi dir="ltr">{ticket.order.number}</bdi>
                            ) : (
                                t('tickets.staff.no_order')
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption text-weak">
                            {t('tickets.field.type')}
                        </dt>
                        <dd className="text-strong">
                            {t(`tickets.type.${ticket.type}` as TranslationKey)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-caption text-weak">
                            {t('tickets.staff.col_assignee')}
                        </dt>
                        <dd className="text-strong">
                            {ticket.assignee?.name ??
                                t('tickets.staff.unassigned')}
                        </dd>
                    </div>
                </dl>
                <div className="flex flex-wrap items-end gap-3">
                    {ticket.can.take && (
                        <Form {...TicketController.take.form(ticket.id)}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('tickets.staff.take')}
                                </Button>
                            )}
                        </Form>
                    )}
                    {ticket.can.assign && ticket.assignable.length > 0 && (
                        <Form
                            {...TicketController.assign.form(ticket.id)}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <SelectField
                                        label={t('tickets.staff.assign')}
                                        name="assignee_id"
                                        defaultValue={String(
                                            ticket.assignee?.id ?? '',
                                        )}
                                        options={ticket.assignable.map((u) => ({
                                            value: String(u.id),
                                            label: u.name,
                                        }))}
                                        error={errors.assignee_id}
                                    />
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        disabled={processing}
                                    >
                                        {t('tickets.staff.assign_do')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </Card>

            <h2 className="text-heading mb-2 text-strong">
                {t('tickets.conversation')}
            </h2>
            <ol className="mb-6 flex flex-col gap-3" data-test="conversation">
                {ticket.messages.map((message) => {
                    const internal = message.visibility === 'internal';

                    return (
                        <li
                            key={message.id}
                            data-test={
                                internal ? 'note-internal' : 'note-public'
                            }
                            className={
                                internal
                                    ? 'rounded-lg border border-dashed border-stroke bg-warning-bg p-3'
                                    : message.author_kind === 'customer'
                                      ? 'me-6 rounded-lg border border-stroke-weak bg-card p-3'
                                      : 'ms-6 rounded-lg bg-brand-subtle p-3'
                            }
                        >
                            <p className="mb-1 flex flex-wrap items-center gap-2 text-caption text-weak">
                                <span className="text-label text-strong">
                                    {message.author}
                                </span>
                                <time dateTime={message.created_at}>
                                    {formatDateTime(message.created_at, locale)}
                                </time>
                                {internal ? (
                                    <Badge variant="warning">
                                        <Icon icon={Lock} size="sm" />
                                        {t('tickets.staff.internal_badge')}
                                    </Badge>
                                ) : (
                                    <Badge variant="neutral">
                                        {t('tickets.staff.public_badge')}
                                    </Badge>
                                )}
                            </p>
                            <p className="text-body whitespace-pre-wrap text-strong">
                                {message.body}
                            </p>
                            {message.files.length > 0 && (
                                <ul className="mt-2 flex flex-col gap-1">
                                    {message.files.map((file) => (
                                        <li key={file.url}>
                                            <a
                                                href={file.url}
                                                className="inline-flex items-center gap-1 text-label text-link hover:underline"
                                            >
                                                <Icon
                                                    icon={Paperclip}
                                                    size="sm"
                                                />
                                                <bdi>{file.name}</bdi>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    );
                })}
            </ol>

            <Card className="mb-4">
                <div
                    role="group"
                    aria-label={t('tickets.conversation')}
                    className="flex gap-2"
                >
                    {ticket.can.public_reply && (
                        <Button
                            type="button"
                            variant={
                                visibility === 'public' ? 'default' : 'outline'
                            }
                            aria-pressed={visibility === 'public'}
                            onClick={() => setVisibility('public')}
                        >
                            {t('tickets.staff.public_reply')}
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant={
                            visibility === 'internal' ? 'default' : 'outline'
                        }
                        aria-pressed={visibility === 'internal'}
                        onClick={() => setVisibility('internal')}
                    >
                        {t('tickets.staff.internal_note')}
                    </Button>
                </div>
                <Form
                    {...TicketController.reply.form(ticket.id)}
                    className="flex flex-col gap-3"
                    encType="multipart/form-data"
                    resetOnSuccess
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="visibility"
                                value={visibility}
                            />
                            <TextareaField
                                label={
                                    visibility === 'public'
                                        ? t('tickets.staff.public_reply')
                                        : t('tickets.staff.internal_note')
                                }
                                hint={
                                    visibility === 'public'
                                        ? t('tickets.staff.public_hint')
                                        : t('tickets.staff.internal_hint')
                                }
                                name="body"
                                required
                                maxLength={5000}
                                rows={4}
                                error={errors.body}
                            />
                            <TextField
                                label={t('tickets.field.files')}
                                name="files[]"
                                type="file"
                                multiple
                                optional
                                accept={accept}
                                error={errors.files ?? errors['files.0']}
                            />
                            {errors.transition && (
                                <p className="text-caption text-danger-text">
                                    {t('tickets.staff.refused')}
                                </p>
                            )}
                            <Button
                                type="submit"
                                className="self-start"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {visibility === 'public'
                                    ? t('tickets.staff.send_reply')
                                    : t('tickets.staff.add_note')}
                            </Button>
                        </>
                    )}
                </Form>
            </Card>

            <div className="flex flex-wrap gap-2">
                {ticket.can.request_info && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setPanel(panel === 'info' ? null : 'info')
                        }
                    >
                        {t('tickets.staff.request_info')}
                    </Button>
                )}
                {ticket.can.resolve && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setPanel(panel === 'resolve' ? null : 'resolve')
                        }
                    >
                        {t('tickets.staff.resolve')}
                    </Button>
                )}
                {ticket.can.reopen && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setPanel(panel === 'reopen' ? null : 'reopen')
                        }
                    >
                        {t('tickets.staff.reopen')}
                    </Button>
                )}
                {ticket.can.close && (
                    <Form {...TicketController.close.form(ticket.id)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                {t('tickets.staff.close')}
                            </Button>
                        )}
                    </Form>
                )}
            </div>

            {panel && (
                <Card className="mt-4">
                    <Form
                        {...(panel === 'info'
                            ? TicketController.requestInfo.form(ticket.id)
                            : panel === 'resolve'
                              ? TicketController.resolve.form(ticket.id)
                              : TicketController.reopen.form(ticket.id))}
                        className="flex flex-col gap-3"
                        encType="multipart/form-data"
                    >
                        {({ processing, errors }) => (
                            <>
                                <TextareaField
                                    label={t(
                                        panel === 'info'
                                            ? 'tickets.staff.request_info'
                                            : panel === 'resolve'
                                              ? 'tickets.staff.resolve_note'
                                              : 'tickets.staff.reopen_note',
                                    )}
                                    name="body"
                                    required
                                    maxLength={5000}
                                    rows={4}
                                    error={errors.body}
                                />
                                {errors.transition && (
                                    <p className="text-caption text-danger-text">
                                        {t('tickets.staff.refused')}
                                    </p>
                                )}
                                <Button
                                    type="submit"
                                    className="self-start"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t(
                                        panel === 'info'
                                            ? 'tickets.staff.request_info_do'
                                            : panel === 'resolve'
                                              ? 'tickets.staff.resolve_do'
                                              : 'tickets.staff.reopen_do',
                                    )}
                                </Button>
                            </>
                        )}
                    </Form>
                </Card>
            )}
        </>
    );
}
