<?php

namespace Database\Seeders\ShowcaseKit;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\IntegrationSetting;
use App\Whatsapp\MessageStatus;
use App\Whatsapp\WhatsAppEvent;

/**
 * WhatsApp channel for the showcase (ADR-0029): the facility has it on for every event from the start, so the real
 * flows queue their customer messages while the clock walks; once the history exists, a deterministic pass gives each
 * message the outcome a real provider would have reported (read / delivered / sent), a few SMS fallbacks, one
 * failure, and a handful of customers who opted out (their messages are SMS). No provider is called.
 */
final class Messaging
{
    public function enable(): void
    {
        IntegrationSetting::query()->updateOrCreate(
            ['key' => IntegrationSetting::WHATSAPP],
            ['enabled' => true, 'config' => ['events' => WhatsAppEvent::values()]],
        );
    }

    public function settle(): void
    {
        // Opted-out customers: every 11th customer, by their own request (some by STOP reply).
        Customer::query()->orderBy('id')->get()->each(function (Customer $customer) {
            if ($customer->id % 11 === 0) {
                $customer->forceFill([
                    'whatsapp_opt_out_at' => $customer->created_at->copy()->addDays(2),
                    'whatsapp_opt_out_source' => $customer->id % 22 === 0 ? 'customer' : 'staff',
                ])->save();
            }
        });

        CustomerMessage::query()->orderBy('id')->get()->each(function (CustomerMessage $m) {
            $optedOut = Customer::query()->whereKey($m->customer_id)->whereNotNull('whatsapp_opt_out_at')->exists();
            $at = $m->created_at->copy();
            $n = $m->id % 10;

            $m->forceFill(['provider_message_id' => null, 'error' => null, 'sent_at' => null, 'delivered_at' => null, 'read_at' => null, 'failed_at' => null]);
            if ($optedOut || $n === 8 || $m->channel === 'sms') {
                // SMS: either the customer's channel, or WhatsApp could not deliver and the same text went by SMS.
                $m->forceFill([
                    'channel' => 'sms', 'template' => null,
                    'fell_back' => ! $optedOut && $n === 8,
                    'error' => ! $optedOut && $n === 8 ? 'whatsapp_131026' : null,
                    'status' => $m->id % 23 === 0 ? MessageStatus::Failed : MessageStatus::Sent,
                    'sent_at' => $at->copy()->addSeconds(5),
                    'failed_at' => $m->id % 23 === 0 ? $at->copy()->addSeconds(40) : null,
                ]);
                if ($m->id % 23 === 0) {
                    $m->forceFill(['sent_at' => null, 'error' => 'delivery_failed']);
                }
            } else {
                $m->forceFill([
                    'provider_message_id' => 'wamid.showcase'.$m->id,
                    'status' => $n <= 3 ? MessageStatus::Read : ($n <= 6 ? MessageStatus::Delivered : MessageStatus::Sent),
                    'sent_at' => $at->copy()->addSeconds(4),
                    'delivered_at' => $n <= 6 ? $at->copy()->addSeconds(9) : null,
                    'read_at' => $n <= 3 ? $at->copy()->addMinutes(7 + $n) : null,
                ]);
            }
            $m->save();
        });
    }
}
