import { Head } from '@inertiajs/react';
import { useState } from 'react';
import MessageTemplateController from '@/actions/App/Http/Controllers/Rules/MessageTemplateController';
import Heading from '@/components/heading';
import { TextareaField, TextField } from '@/components/opsone/field';
import { PageHeader } from '@/components/opsone/page-header';
import { StickyActions } from '@/components/opsone/sticky-actions';
import { RulesNav, useRulesWrite } from '@/components/rules/rules-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { messages as messagesRoute } from '@/routes/rules';
import { home } from '@/routes/settings';

type Text = {
    channel: 'sms' | 'mail' | 'in_app';
    locale: 'ar' | 'en';
    default_subject: string | null;
    default_body: string;
    subject: string | null;
    body: string | null;
};

type Event = {
    event: string;
    audience: 'customer' | 'staff';
    variables: string[];
    texts: Text[];
};

type Props = {
    events: Event[];
    whatsapp: { event: string; name: string | null; default: string }[];
    canEdit: boolean;
};

/**
 * Settings › messages (ADR-0037): per event, channel and language the facility's wording (or the built-in text), the
 * allowed variables and a preview with sample values; and the approved WhatsApp template names.
 */
export default function Messages({ events, whatsapp, canEdit }: Props) {
    const t = useT();
    const write = useRulesWrite();
    const [open, setOpen] = useState<string | null>(null);
    const [names, setNames] = useState<Record<string, string>>(() =>
        Object.fromEntries(whatsapp.map((w) => [w.event, w.name ?? ''])),
    );

    return (
        <>
            <Head title={t('rules.messages.title')} />
            <PageHeader
                title={t('rules.messages.title')}
                description={t('rules.messages.description')}
            />
            <RulesNav current="messages" canEdit={canEdit} />

            {(['customer', 'staff'] as const).map((audience) => (
                <section key={audience} className="mb-8 max-w-4xl">
                    <Heading
                        variant="small"
                        title={t(
                            `rules.messages.${audience}` as TranslationKey,
                        )}
                    />
                    <ul className="grid gap-2">
                        {events
                            .filter((e) => e.audience === audience)
                            .map((e) => (
                                <li
                                    key={e.event}
                                    className="rounded-lg border border-stroke-weak p-3"
                                >
                                    <button
                                        type="button"
                                        className="flex w-full flex-wrap items-center justify-between gap-2 text-start"
                                        aria-expanded={open === e.event}
                                        onClick={() =>
                                            setOpen(
                                                open === e.event
                                                    ? null
                                                    : e.event,
                                            )
                                        }
                                    >
                                        <span>
                                            {t(
                                                `rules.messages.event.${e.event}` as TranslationKey,
                                            )}
                                        </span>
                                        {e.texts.some(
                                            (x) => x.body !== null,
                                        ) && (
                                            <Badge variant="brand">
                                                {t('rules.messages.custom')}
                                            </Badge>
                                        )}
                                    </button>
                                    {open === e.event && (
                                        <div className="mt-3 space-y-4">
                                            <p className="text-caption text-weak">
                                                {t('rules.variables')}:{' '}
                                                <span dir="ltr">
                                                    {e.variables
                                                        .map((v) => `{${v}}`)
                                                        .join(' ')}
                                                </span>
                                            </p>
                                            {e.texts.map((text) => (
                                                <TextEditor
                                                    key={`${text.channel}-${text.locale}`}
                                                    event={e.event}
                                                    text={text}
                                                    canEdit={canEdit}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </li>
                            ))}
                    </ul>
                </section>
            ))}

            <section className="max-w-3xl space-y-4" data-test="whatsapp-names">
                <Heading
                    variant="small"
                    title={t('rules.messages.whatsapp')}
                    description={t('rules.messages.whatsapp_hint')}
                />
                <fieldset
                    disabled={!canEdit}
                    className="m-0 grid min-w-0 gap-3 border-0 p-0 sm:grid-cols-2"
                >
                    {whatsapp.map((w) => (
                        <TextField
                            key={w.event}
                            label={t(
                                `rules.messages.whatsapp_event.${w.event}` as TranslationKey,
                            )}
                            dir="ltr"
                            placeholder={w.default}
                            value={names[w.event] ?? ''}
                            error={write.errors[`templates.${w.event}`]}
                            onChange={(e) =>
                                setNames({
                                    ...names,
                                    [w.event]: e.target.value,
                                })
                            }
                        />
                    ))}
                </fieldset>
                {canEdit && (
                    <StickyActions>
                        <Button
                            type="button"
                            loading={write.busy}
                            onClick={() =>
                                write.send(
                                    'put',
                                    MessageTemplateController.whatsapp().url,
                                    { templates: names },
                                )
                            }
                        >
                            {t('rules.save')}
                        </Button>
                    </StickyActions>
                )}
            </section>
        </>
    );
}

function TextEditor({
    event,
    text,
    canEdit,
}: {
    event: string;
    text: Text;
    canEdit: boolean;
}) {
    const t = useT();
    const write = useRulesWrite();
    const [subject, setSubject] = useState(
        text.subject ?? text.default_subject ?? '',
    );
    const [body, setBody] = useState(text.body ?? text.default_body);
    const [preview, setPreview] = useState<string | null>(null);
    const dir = text.locale === 'ar' ? 'rtl' : 'ltr';
    const hasSubject = text.default_subject !== null;

    const runPreview = async () => {
        const result = await api<{ subject: string | null; body: string }>(
            'POST',
            MessageTemplateController.preview().url,
            {
                event,
                locale: text.locale,
                subject: hasSubject ? subject : null,
                body,
            },
        );
        setPreview(
            result.ok && result.data
                ? [result.data.subject, result.data.body]
                      .filter(Boolean)
                      .join(' — ')
                : (Object.values(result.errors)[0]?.[0] ?? result.message),
        );
    };

    return (
        <fieldset
            disabled={!canEdit}
            className="m-0 min-w-0 space-y-2 rounded-md border border-stroke-weak p-3"
            data-test="message-text"
        >
            <legend className="px-1 text-label">
                {t(`rules.messages.channel.${text.channel}` as TranslationKey)}{' '}
                · {t(`rules.messages.locale.${text.locale}` as TranslationKey)}
                {text.body !== null && ` · ${t('rules.messages.custom')}`}
            </legend>
            {hasSubject && (
                <TextField
                    label={t('rules.messages.subject')}
                    dir={dir}
                    value={subject}
                    error={write.errors.subject}
                    onChange={(e) => setSubject(e.target.value)}
                />
            )}
            <TextareaField
                label={t('rules.messages.body')}
                dir={dir}
                rows={3}
                value={body}
                error={write.errors.body}
                onChange={(e) => setBody(e.target.value)}
            />
            {preview !== null && (
                <p
                    className="rounded bg-neutral-bg p-2"
                    dir={dir}
                    data-test="message-preview"
                >
                    {preview}
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={runPreview}
                >
                    {t('rules.preview')}
                </Button>
                {canEdit && (
                    <Button
                        type="button"
                        size="sm"
                        loading={write.busy}
                        onClick={() =>
                            write.send(
                                'put',
                                MessageTemplateController.update().url,
                                {
                                    event,
                                    channel: text.channel,
                                    locale: text.locale,
                                    subject: hasSubject ? subject : null,
                                    body,
                                },
                            )
                        }
                    >
                        {t('rules.save')}
                    </Button>
                )}
                {canEdit && text.body !== null && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            write.send(
                                'delete',
                                MessageTemplateController.reset().url,
                                {
                                    event,
                                    channel: text.channel,
                                    locale: text.locale,
                                },
                            )
                        }
                    >
                        {t('rules.messages.reset')}
                    </Button>
                )}
            </div>
        </fieldset>
    );
}

Messages.layout = {
    breadcrumbs: [
        { title: 'account.nav.home', href: home() },
        { title: 'rules.messages.title', href: messagesRoute() },
    ],
};
