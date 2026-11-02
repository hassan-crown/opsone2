<?php

namespace App\Messages;

use App\Whatsapp\WhatsAppEvent;

/**
 * The closed list of messages a facility may reword (ADR-0037): each event names its built-in text (translation
 * keys), the channels it is sent on and the only variables its text may use. Customer texts (SMS, and the mail copy
 * of finance notices) and staff in-app notices; WhatsApp itself sends Meta-approved templates, so only its SMS
 * fallback is editable here. Amounts appear only in messages whose recipients may see them (they already do).
 */
final class MessageCatalogue
{
    public const CHANNELS = ['sms', 'mail', 'in_app'];

    public const LOCALES = ['ar', 'en'];

    /**
     * @return array<string, array{channels: list<string>, audience: string, body: string, subject: string|null, variables: list<string>}>
     */
    public static function events(): array
    {
        $events = [
            'finance.due_soon' => self::finance('due_soon', ['order', 'amount', 'due']),
            'finance.due_today' => self::finance('due_today', ['order', 'amount', 'due']),
            'finance.due_overdue' => self::finance('due_overdue', ['order', 'amount', 'due']),
            'finance.receipt' => self::finance('receipt', ['order', 'amount', 'number']),
            'finance.proof_correction' => self::finance('proof_correction', ['order', 'reason']),
            'finance.proof_amount' => self::finance('proof_amount', ['order', 'claimed', 'received']),
        ];
        foreach (WhatsAppEvent::cases() as $event) {
            $events['customer.'.$event->value] = [
                'channels' => ['sms'], 'audience' => 'customer', 'body' => $event->textKey(), 'subject' => null,
                'variables' => array_values(array_unique(['name', 'facility', ...$event->params()])),
            ];
        }

        return $events + [
            'task.assigned' => self::staff('task.assigned', ['order', 'task']),
            'work.reminder' => self::staff('work.reminder', ['order', 'task', 'due']),
            'work.escalated' => self::staff('work.escalated', ['order', 'task', 'due', 'assignee']),
            'finance.receipt_recorded' => self::staff('finance.receipt_recorded', ['order', 'number', 'amount']),
            'finance.due_escalated' => self::staff('finance.due_escalated', ['order', 'amount', 'due', 'date']),
        ];
    }

    /**
     * @param  list<string>  $vars
     * @return array{channels: list<string>, audience: string, body: string, subject: string|null, variables: list<string>}
     */
    private static function finance(string $key, array $vars): array
    {
        return [
            'channels' => ['sms', 'mail'], 'audience' => 'customer', 'body' => "finance.message.{$key}", 'subject' => 'finance.message.subject',
            'variables' => [...$vars, 'facility'],
        ];
    }

    /**
     * @param  list<string>  $vars
     * @return array{channels: list<string>, audience: string, body: string, subject: string|null, variables: list<string>}
     */
    private static function staff(string $type, array $vars): array
    {
        $slug = str_replace('.', '_', $type);

        return ['channels' => ['in_app'], 'audience' => 'staff', 'body' => "notifications.type.{$slug}.body", 'subject' => "notifications.type.{$slug}.title", 'variables' => $vars];
    }

    /**
     * @return array{channels: list<string>, audience: string, body: string, subject: string|null, variables: list<string>}|null
     */
    public static function get(string $event): ?array
    {
        return self::events()[$event] ?? null;
    }

    /** The catalogue event of a finance customer message key (`finance.message.due_soon` → `finance.due_soon`). */
    public static function forFinanceKey(string $key): string
    {
        return str_replace('finance.message.', 'finance.', $key);
    }

    /**
     * Sample values for the preview.
     *
     * @return array<string, string>
     */
    public static function samples(string $locale): array
    {
        $ar = $locale === 'ar';

        return [
            'order' => 'ORD-2026-00042', 'amount' => '12,500.00', 'due' => $ar ? 'الدفعة الأولى' : 'First payment',
            'number' => 'RC-2026-000017', 'reason' => $ar ? 'الصورة غير واضحة' : 'The image is unclear',
            'claimed' => '5,000.00', 'received' => '4,500.00', 'facility' => $ar ? 'معرض المطابخ الحديثة' : 'Modern Kitchens',
            'name' => $ar ? 'سارة' : 'Sara', 'when' => '2026-11-05 10:00', 'link' => 'https://example.test/l/abc',
            'task' => $ar ? 'زيارة القياس' : 'Measurement visit', 'date' => '2026-11-01', 'assignee' => $ar ? 'خالد' : 'Khaled',
        ];
    }
}
