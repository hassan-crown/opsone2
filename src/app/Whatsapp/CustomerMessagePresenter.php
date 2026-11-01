<?php

namespace App\Whatsapp;

use App\Models\Customer;
use App\Models\CustomerMessage;

/**
 * The customer page's message log. Every staff member who can open the customer sees which event went out, by which
 * channel and what became of it; the text itself only when `$withBody` (holders of P-10 over the customer).
 */
final class CustomerMessagePresenter
{
    public const LIMIT = 30;

    /**
     * @return array{opt_out: bool, opt_out_source: string|null, preferred_channel: string|null, messages: list<array<string, mixed>>, body_hidden: bool}
     */
    public static function panel(Customer $customer, bool $withBody): array
    {
        $messages = CustomerMessage::query()->where('customer_id', $customer->id)->orderByDesc('id')->limit(self::LIMIT)->get();

        return [
            'opt_out' => $customer->whatsapp_opt_out_at !== null,
            'opt_out_source' => $customer->whatsapp_opt_out_source,
            'preferred_channel' => $customer->preferred_channel?->value,
            'messages' => array_values($messages->map(fn (CustomerMessage $m) => [
                'id' => $m->id,
                'event' => $m->event->value,
                'channel' => $m->channel,
                'fell_back' => $m->fell_back,
                'status' => $m->status->value,
                'error' => $m->status === MessageStatus::Failed || $m->fell_back ? $m->error : null,
                'at' => $m->created_at->toIso8601String(),
                'body' => $withBody ? $m->body : null,
            ])->all()),
            'body_hidden' => ! $withBody,
        ];
    }
}
