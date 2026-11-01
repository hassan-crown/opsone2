<?php

namespace App\Whatsapp;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Models\Customer;
use App\Models\CustomerMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meta's WhatsApp webhook: delivery statuses (sent / delivered / read / failed) and the customer's STOP reply.
 * The signature of the raw body (X-Hub-Signature-256, HMAC-SHA256 with the app secret) is checked before anything is
 * read; with no app secret configured every notification is refused. Statuses only move forward, so a replay or
 * a late delivery of an old status changes nothing.
 */
final class WhatsAppWebhook
{
    /** Words that mean «stop sending me WhatsApp messages» (lower-cased, trimmed). */
    private const STOP_WORDS = ['stop', 'unsubscribe', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء'];

    public function __construct(private readonly WhatsAppDelivery $delivery) {}

    public function validSignature(Request $request): bool
    {
        $secret = (string) config('whatsapp.cloud.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');
        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    /**
     * Meta verifies the URL once with a GET carrying hub.verify_token and a challenge to echo.
     */
    public function challenge(Request $request): ?string
    {
        $expected = (string) config('whatsapp.cloud.verify_token');
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));

        if ($expected === '' || $request->query('hub_mode', $request->query('hub.mode')) !== 'subscribe' || ! hash_equals($expected, $token)) {
            return null;
        }

        return (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
    }

    /**
     * @param  array<string, mixed>  $payload  the decoded body
     * @return array{statuses: int, stops: int}
     */
    public function apply(array $payload): array
    {
        $statuses = 0;
        $stops = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $statuses += (int) $this->applyStatus((array) $status);
                }
                foreach ((array) ($value['messages'] ?? []) as $inbound) {
                    $stops += (int) $this->applyInbound((array) $inbound);
                }
            }
        }

        return ['statuses' => $statuses, 'stops' => $stops];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): bool
    {
        $id = (string) ($status['id'] ?? '');
        $to = match ($status['status'] ?? null) {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };
        if ($id === '' || $to === null) {
            return false;
        }

        return DB::transaction(function () use ($id, $to, $status) {
            $log = CustomerMessage::query()->where('provider_message_id', $id)->lockForUpdate()->first();
            if ($log === null) {
                return false;
            }
            if ($to !== MessageStatus::Failed) {
                return $log->advance($to);
            }

            $code = (string) data_get($status, 'errors.0.code', 'failed');
            $moved = $log->advance(MessageStatus::Failed, $code);
            if ($moved) {
                $this->delivery->fallbackAfterReportedFailure($log, $code);
            }

            return $moved;
        });
    }

    /**
     * @param  array<string, mixed>  $inbound
     */
    private function applyInbound(array $inbound): bool
    {
        $text = mb_strtolower(trim((string) data_get($inbound, 'text.body', '')));
        $from = Phone::normalize('+'.ltrim((string) ($inbound['from'] ?? ''), '+'), false);
        if ($from === null || ! in_array($text, self::STOP_WORDS, true)) {
            return false;
        }

        $customer = Customer::query()->where('mobile', $from)->first();
        if ($customer === null || $customer->whatsapp_opt_out_at !== null) {
            return false;
        }

        DB::transaction(function () use ($customer) {
            $customer->forceFill(['whatsapp_opt_out_at' => now(), 'whatsapp_opt_out_source' => 'customer'])->save();
            Audit::record(AuditAction::CustomerWhatsappOptOut, $customer, null, after: ['source' => 'customer'], source: TransitionRecorder::SOURCE_SYSTEM, label: $customer->name);
        });

        return true;
    }
}
