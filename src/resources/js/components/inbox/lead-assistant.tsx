import { router } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import LeadAssistController from '@/actions/App/Http/Controllers/Inbox/LeadAssistController';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { InboxLeadPanel, LeadFieldKey } from '@/types/inbox';

const FIELD_KEYS: LeadFieldKey[] = [
    'name',
    'email',
    'product',
    'district',
    'budget',
    'preferred_time',
];

type Props = {
    conversationId: number;
    panel: InboxLeadPanel;
    /** Copies text into the composer; nothing is sent from here. */
    onUse: (text: string, append: boolean) => void;
};

/**
 * AI-7a: a new lead's suggested welcome reply, the qualification details staff may accept into the lead, and the
 * measurement slots the server computed from the calendar. Every write is a button press by this staff member.
 */
export function LeadAssistant({ conversationId, panel, onUse }: Props) {
    const t = useT();
    const [busy, setBusy] = useState(false);
    if (!panel.available) {
        return null;
    }
    const s = panel.suggestion;

    const suggest = () => {
        setBusy(true);
        router.post(
            LeadAssistController.suggest.url(conversationId),
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div
            className="flex flex-col gap-3 rounded-lg border border-stroke-weak p-3"
            data-test="lead-assistant"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="inline-flex items-center gap-1 text-label text-strong">
                    <Icon icon={Sparkles} size="sm" />
                    {t('inbox.ai.lead.title')}
                </h2>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={suggest}
                    data-test="lead-suggest"
                >
                    {busy && <Spinner />}
                    {s
                        ? t('inbox.ai.lead.regenerate')
                        : t('inbox.ai.lead.suggest')}
                </Button>
            </div>

            {s && (
                <div className="flex flex-col gap-2">
                    <p className="text-body whitespace-pre-wrap text-strong">
                        {s.reply}
                    </p>
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => onUse(s.reply, false)}
                            data-test="lead-use-reply"
                        >
                            {t('inbox.ai.lead.use_reply')}
                        </Button>
                    </div>
                    <LeadFieldsForm
                        key={s.id}
                        conversationId={conversationId}
                        suggestionId={s.id}
                        fields={s.fields}
                        canAccept={panel.can_accept}
                    />
                </div>
            )}

            <div className="flex flex-col gap-2" data-test="lead-slots">
                <h3 className="text-label text-strong">
                    {t('inbox.ai.lead.slots')}
                </h3>
                {panel.slots.length === 0 ? (
                    <p className="text-caption text-weak">
                        {t('inbox.ai.lead.no_slots')}
                    </p>
                ) : (
                    <>
                        <ul className="text-body">
                            {panel.slots.map((slot) => (
                                <li key={slot.starts_at}>
                                    <bdi>{slot.label}</bdi>
                                </li>
                            ))}
                        </ul>
                        {panel.slots_text && (
                            <div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        onUse(panel.slots_text ?? '', true)
                                    }
                                    data-test="lead-use-slots"
                                >
                                    {t('inbox.ai.lead.use_slots')}
                                </Button>
                            </div>
                        )}
                        <SlotBooking
                            conversationId={conversationId}
                            slots={panel.slots}
                            orders={panel.orders}
                        />
                    </>
                )}
            </div>
            <p className="text-caption text-weak">{t('inbox.ai.lead.hint')}</p>
        </div>
    );
}

function LeadFieldsForm({
    conversationId,
    suggestionId,
    fields,
    canAccept,
}: {
    conversationId: number;
    suggestionId: number;
    fields: Partial<Record<LeadFieldKey, string | null>>;
    canAccept: boolean;
}) {
    const t = useT();
    const [values, setValues] = useState<Record<LeadFieldKey, string>>(
        () =>
            Object.fromEntries(
                FIELD_KEYS.map((k) => [k, fields[k] ?? '']),
            ) as Record<LeadFieldKey, string>,
    );
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex flex-col gap-2" data-test="lead-fields">
            <h3 className="text-label text-strong">
                {t('inbox.ai.lead.fields')}
            </h3>
            {FIELD_KEYS.map((k) => (
                <TextField
                    key={k}
                    label={t(`inbox.ai.lead.field.${k}` as TranslationKey)}
                    name={k}
                    value={values[k]}
                    onChange={(e) =>
                        setValues((v) => ({ ...v, [k]: e.target.value }))
                    }
                    optional
                />
            ))}
            <div className="flex flex-wrap gap-2">
                {canAccept && (
                    <Button
                        type="button"
                        size="sm"
                        disabled={busy}
                        data-test="lead-accept"
                        onClick={() => {
                            setBusy(true);
                            router.post(
                                LeadAssistController.accept.url({
                                    conversation: conversationId,
                                    suggestion: suggestionId,
                                }),
                                values,
                                {
                                    preserveScroll: true,
                                    onFinish: () => setBusy(false),
                                },
                            );
                        }}
                    >
                        {busy && <Spinner />}
                        {t('inbox.ai.lead.accept')}
                    </Button>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            LeadAssistController.reject.url({
                                conversation: conversationId,
                                suggestion: suggestionId,
                            }),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {t('inbox.ai.lead.reject')}
                </Button>
            </div>
        </div>
    );
}

function SlotBooking({
    conversationId,
    slots,
    orders,
}: {
    conversationId: number;
    slots: { starts_at: string; member_id: number; label: string }[];
    orders: { id: number; number: string }[];
}) {
    const t = useT();
    const [slot, setSlot] = useState(slots[0]?.starts_at ?? '');
    const [order, setOrder] = useState(orders[0] ? String(orders[0].id) : '');
    const [address, setAddress] = useState('');
    const [busy, setBusy] = useState(false);

    if (orders.length === 0) {
        return (
            <p className="text-caption text-weak">
                {t('inbox.ai.lead.book_needs_order')}
            </p>
        );
    }
    const chosen = slots.find((x) => x.starts_at === slot);

    return (
        <div className="flex flex-col gap-2" data-test="lead-book">
            <SelectField
                label={t('inbox.ai.lead.slots')}
                name="slot"
                value={slot}
                onChange={(e) => setSlot(e.target.value)}
                options={slots.map((x) => ({
                    value: x.starts_at,
                    label: x.label,
                }))}
            />
            <SelectField
                label={t('inbox.ai.lead.book_order')}
                name="order_id"
                value={order}
                onChange={(e) => setOrder(e.target.value)}
                options={orders.map((o) => ({
                    value: String(o.id),
                    label: o.number,
                }))}
            />
            <TextField
                label={t('inbox.ai.lead.book_address')}
                name="address"
                value={address}
                onChange={(e) => setAddress(e.target.value)}
            />
            <div>
                <Button
                    type="button"
                    size="sm"
                    disabled={busy || !chosen || address.trim() === ''}
                    data-test="lead-book-submit"
                    onClick={() => {
                        if (!chosen) {
                            return;
                        }
                        setBusy(true);
                        router.post(
                            LeadAssistController.book.url(conversationId),
                            {
                                order_id: Number(order),
                                starts_at: chosen.starts_at,
                                member_id: chosen.member_id,
                                address,
                            },
                            {
                                preserveScroll: true,
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    {busy && <Spinner />}
                    {t('inbox.ai.lead.book')}
                </Button>
            </div>
        </div>
    );
}
