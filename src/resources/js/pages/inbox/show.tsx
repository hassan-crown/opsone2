import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Paperclip, ShieldAlert, Sparkles } from 'lucide-react';
import { useState } from 'react';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import {
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { BackLink } from '@/components/procurement/shared';
import { Alert, AlertBody } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    ConversationDetail,
    ConversationMessageView,
} from '@/types/inbox';

type Props = { conversation: ConversationDetail; customerQuery: string };

const newKey = () => crypto.randomUUID();

/** One WhatsApp conversation (AI-3, P-108): the thread, the composer bound to the 24-hour window, assignment and customer link. */
export default function InboxShow({ conversation, customerQuery }: Props) {
    const t = useT();
    const { locale } = useLocale();
    const c = conversation;

    return (
        <>
            <Head title={c.name ?? c.wa_id} />
            <PageHeader
                back={
                    <BackLink
                        href={InboxController.index.url()}
                        label={t('inbox.title')}
                    />
                }
                title={c.name ?? c.wa_id}
                description={c.wa_id}
                actions={
                    <Badge variant={c.window_open ? 'success' : 'neutral'}>
                        {c.window_open
                            ? t('inbox.window.open_until', {
                                  time: c.window_closes_at
                                      ? formatDateTime(
                                            c.window_closes_at,
                                            locale,
                                        )
                                      : '',
                              })
                            : t('inbox.window.closed')}
                    </Badge>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <div className="flex flex-col gap-4">
                    {c.ai.flagged && (
                        <Alert
                            variant="warning"
                            role="alert"
                            data-test="ai-flagged"
                        >
                            <ShieldAlert />
                            <AlertBody>{t('inbox.ai.flagged')}</AlertBody>
                        </Alert>
                    )}
                    <ol
                        className="flex flex-col gap-3"
                        data-test="conversation-thread"
                    >
                        {c.messages.length === 0 && (
                            <li className="text-body text-weak">
                                {t('inbox.no_messages')}
                            </li>
                        )}
                        {c.messages.map((m) => (
                            <MessageBubble key={m.id} message={m} />
                        ))}
                    </ol>
                    <Composer conversation={c} />
                </div>

                <aside className="flex flex-col gap-4">
                    <CustomerCard
                        conversation={c}
                        customerQuery={customerQuery}
                    />
                    {c.can.assign && (
                        <Card>
                            <Form
                                {...InboxController.assign.form(c.id)}
                                className="flex flex-col gap-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <SelectField
                                            label={t('inbox.assign')}
                                            name="assignee_id"
                                            defaultValue={String(
                                                c.assignee?.id ?? '',
                                            )}
                                            placeholder={t('inbox.unassigned')}
                                            options={c.assignable.map((u) => ({
                                                value: String(u.id),
                                                label: u.name,
                                            }))}
                                            error={errors.assignee_id}
                                        />
                                        <Button
                                            type="submit"
                                            variant="secondary"
                                            className="self-start"
                                            disabled={processing}
                                        >
                                            {t('inbox.assign_do')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </Card>
                    )}
                </aside>
            </div>
        </>
    );
}

function MessageBubble({ message: m }: { message: ConversationMessageView }) {
    const t = useT();
    const { locale } = useLocale();
    const inbound = m.direction === 'in';

    return (
        <li
            data-test={inbound ? 'message-in' : 'message-out'}
            className={
                inbound
                    ? 'me-8 rounded-lg border border-stroke-weak bg-card p-3'
                    : 'ms-8 rounded-lg bg-brand-subtle p-3'
            }
        >
            <p className="mb-1 flex flex-wrap items-center gap-2 text-caption text-weak">
                {!inbound && m.sender && (
                    <span className="text-label text-strong">{m.sender}</span>
                )}
                <time dateTime={m.occurred_at}>
                    {formatDateTime(m.occurred_at, locale)}
                </time>
                {!inbound && (
                    <Badge
                        variant={m.status === 'failed' ? 'danger' : 'neutral'}
                    >
                        {t(`inbox.status.${m.status}` as TranslationKey)}
                    </Badge>
                )}
                {m.type === 'template' && (
                    <Badge variant="info">{t('inbox.template_badge')}</Badge>
                )}
                {inbound && m.ai_intent && (
                    <Badge variant="neutral" data-test="message-intent">
                        {t(`inbox.ai.intent.${m.ai_intent}` as TranslationKey)}
                    </Badge>
                )}
                {inbound && m.ai_suspicious && (
                    <Badge variant="danger">{t('inbox.ai.suspicious')}</Badge>
                )}
                {!inbound && m.from_suggestion && (
                    <Badge variant="info">
                        {t('inbox.ai.from_suggestion')}
                    </Badge>
                )}
            </p>
            {m.type === 'template' ? (
                <p className="text-body text-strong">
                    <bdi dir="ltr" className="font-mono">
                        {m.template}
                    </bdi>
                    {m.params && m.params.length > 0 && (
                        <span className="text-weak">
                            {' '}
                            — {m.params.join(' · ')}
                        </span>
                    )}
                </p>
            ) : (
                m.body && (
                    <p className="text-body whitespace-pre-wrap text-strong">
                        {m.body}
                    </p>
                )
            )}
            {m.type === 'unsupported' && (
                <p className="text-body text-weak">{t('inbox.unsupported')}</p>
            )}
            {m.file && (
                <a
                    href={m.file.url}
                    className="mt-1 inline-flex items-center gap-1 text-label text-link hover:underline"
                >
                    <Icon icon={Paperclip} size="sm" />
                    <bdi>{m.file.name}</bdi>
                </a>
            )}
            {m.media_status && m.media_status !== 'stored' && (
                <p className="mt-1 text-caption text-weak">
                    {t(`inbox.media.${m.media_status}` as TranslationKey)}
                </p>
            )}
            {m.status === 'failed' && m.error && (
                <p className="mt-1 text-caption text-danger-text">
                    {t('inbox.failed_code', { code: m.error })}
                </p>
            )}
        </li>
    );
}

function Composer({ conversation: c }: { conversation: ConversationDetail }) {
    const t = useT();
    const errors = usePage().props.errors as Record<string, string>;
    const [mode, setMode] = useState<'text' | 'template'>(
        c.window_open ? 'text' : 'template',
    );
    const [body, setBody] = useState('');
    const [suggestionId, setSuggestionId] = useState<number | null>(null);
    const [template, setTemplate] = useState(0);
    const [params, setParams] = useState<string[]>([]);
    const [key, setKey] = useState(newKey);
    const [busy, setBusy] = useState(false);

    if (c.customer?.opted_out) {
        return (
            <Card>
                <p className="text-body text-weak">{t('inbox.opted_out')}</p>
            </Card>
        );
    }
    if (!c.configured) {
        return (
            <Card>
                <p className="text-body text-weak">
                    {t('inbox.not_configured')}
                </p>
            </Card>
        );
    }
    if (!c.can.reply) {
        return null;
    }

    const effective = c.window_open ? mode : 'template';
    const selected = c.templates[template] ?? null;

    const send = () => {
        setBusy(true);
        router.post(
            InboxController.reply.url(c.id),
            effective === 'text'
                ? { kind: 'text', key, body, suggestion_id: suggestionId }
                : {
                      kind: 'template',
                      key,
                      template: selected?.name ?? '',
                      language: selected?.language ?? '',
                      params: params.slice(0, selected?.params ?? 0),
                  },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBody('');
                    setSuggestionId(null);
                    setParams([]);
                    setKey(newKey());
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Card data-test="composer">
            {c.window_open ? (
                <div
                    role="group"
                    aria-label={t('inbox.composer')}
                    className="flex gap-2"
                >
                    <Button
                        type="button"
                        variant={mode === 'text' ? 'default' : 'outline'}
                        aria-pressed={mode === 'text'}
                        onClick={() => setMode('text')}
                    >
                        {t('inbox.free_text')}
                    </Button>
                    <Button
                        type="button"
                        variant={mode === 'template' ? 'default' : 'outline'}
                        aria-pressed={mode === 'template'}
                        onClick={() => setMode('template')}
                    >
                        {t('inbox.use_template')}
                    </Button>
                </div>
            ) : (
                <p className="text-body text-weak" data-test="window-closed">
                    {t('inbox.window.closed_hint')}
                </p>
            )}

            {effective === 'text' && c.ai.available && (
                <SuggestionPanel
                    conversation={c}
                    onUse={(id, reply) => {
                        setBody(reply);
                        setSuggestionId(id);
                    }}
                />
            )}

            {effective === 'text' ? (
                <TextareaField
                    label={t('inbox.reply')}
                    name="body"
                    value={body}
                    onChange={(e) => setBody(e.target.value)}
                    maxLength={4096}
                    rows={4}
                    error={errors.body}
                />
            ) : c.templates.length === 0 ? (
                <p className="text-body text-weak">{t('inbox.no_templates')}</p>
            ) : (
                <>
                    <SelectField
                        label={t('inbox.template')}
                        name="template"
                        value={String(template)}
                        onChange={(e) => {
                            setTemplate(Number(e.target.value));
                            setParams([]);
                        }}
                        options={c.templates.map((tpl, i) => ({
                            value: String(i),
                            label: `${tpl.label} (${tpl.language})`,
                        }))}
                        error={errors.template}
                    />
                    {selected &&
                        Array.from({ length: selected.params }).map((_, i) => (
                            <TextField
                                key={`${template}-${i}`}
                                label={t('inbox.param', { n: i + 1 })}
                                name={`params[${i}]`}
                                value={params[i] ?? ''}
                                onChange={(e) =>
                                    setParams((list) => {
                                        const next = [...list];
                                        next[i] = e.target.value;

                                        return next;
                                    })
                                }
                                maxLength={1024}
                            />
                        ))}
                    {errors.params && (
                        <p className="text-caption text-danger-text">
                            {errors.params}
                        </p>
                    )}
                </>
            )}
            <Button
                type="button"
                className="self-start"
                disabled={
                    busy ||
                    (effective === 'text'
                        ? body.trim() === ''
                        : selected === null)
                }
                onClick={send}
                data-test="send-reply"
            >
                {busy && <Spinner />}
                {t('inbox.send')}
            </Button>
        </Card>
    );
}

/**
 * AI-4: the staff member's own suggested reply. «Use» only copies it into the composer; it is sent, like any reply,
 * by the send button through the normal reply path. Nothing is sent from here.
 */
function SuggestionPanel({
    conversation: c,
    onUse,
}: {
    conversation: ConversationDetail;
    onUse: (id: number, reply: string) => void;
}) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    const s = c.ai.suggestion;

    const suggest = () => {
        setBusy(true);
        router.post(
            InboxController.suggest.url(c.id),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div
            className="flex flex-col gap-2 rounded-lg border border-stroke-weak p-3"
            data-test="ai-suggestion"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="inline-flex items-center gap-1 text-label text-strong">
                    <Icon icon={Sparkles} size="sm" />
                    {t('inbox.ai.title')}
                </h2>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={suggest}
                    data-test="ai-suggest"
                >
                    {busy && <Spinner />}
                    {s ? t('inbox.ai.regenerate') : t('inbox.ai.suggest')}
                </Button>
            </div>
            {s && (
                <>
                    <p className="text-body whitespace-pre-wrap text-strong">
                        {s.reply}
                    </p>
                    {s.facts.length > 0 && (
                        <p className="text-caption text-weak">
                            {t('inbox.ai.facts')}:{' '}
                            {s.facts.map((f) => (
                                <bdi key={f.key} className="me-2">
                                    {String(f.value)}
                                </bdi>
                            ))}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => onUse(s.id, s.reply)}
                            data-test="ai-use"
                        >
                            {t('inbox.ai.use')}
                        </Button>
                        <Form
                            {...InboxController.rejectSuggestion.form({
                                conversation: c.id,
                                suggestion: s.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="ghost"
                                    disabled={processing}
                                >
                                    {t('inbox.ai.reject')}
                                </Button>
                            )}
                        </Form>
                    </div>
                </>
            )}
            <p className="text-caption text-weak">{t('inbox.ai.hint')}</p>
        </div>
    );
}

function CustomerCard({
    conversation: c,
    customerQuery,
}: {
    conversation: ConversationDetail;
    customerQuery: string;
}) {
    const t = useT();

    return (
        <Card>
            <h2 className="text-label text-strong">{t('inbox.customer')}</h2>
            {c.customer ? (
                <p className="text-body">
                    {c.customer.url ? (
                        <Link
                            href={c.customer.url}
                            className="text-link hover:underline"
                        >
                            {c.customer.name}
                        </Link>
                    ) : (
                        c.customer.name
                    )}
                </p>
            ) : (
                <p className="text-body text-weak">{t('inbox.unknown_hint')}</p>
            )}
            {c.can.link && (
                <>
                    <Form
                        {...InboxController.show.form(c.id)}
                        className="flex flex-col gap-2"
                    >
                        <TextField
                            label={t('inbox.find_customer')}
                            name="customer_q"
                            type="search"
                            defaultValue={customerQuery}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="self-start"
                        >
                            {t('inbox.search_do')}
                        </Button>
                    </Form>
                    {c.customer_results.length > 0 && (
                        <ul className="flex flex-col gap-2">
                            {c.customer_results.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span className="text-body">
                                        {r.name}{' '}
                                        {r.mobile && (
                                            <bdi
                                                dir="ltr"
                                                className="text-caption text-weak"
                                            >
                                                {r.mobile}
                                            </bdi>
                                        )}
                                    </span>
                                    <Form {...InboxController.link.form(c.id)}>
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="customer_id"
                                                    value={r.id}
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={processing}
                                                >
                                                    {t('inbox.link_do')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </Card>
    );
}
