<?php

namespace App\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Ai\Inbox\InboxSuggestions;
use App\Customers\CustomerAccess;
use App\Files\AttachmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\User;
use App\Whatsapp\Inbox\ConversationTransport;

/**
 * Inbox payloads (AI-3). Only for staff who passed InboxAccess over the conversation; the message text is returned only
 * here. The customer link is shown as a link only when the viewer may open that customer.
 */
final class InboxPresenter
{
    private const THREAD_LIMIT = 200;

    public function __construct(
        private readonly AttachmentService $files,
        private readonly InboxSettings $settings,
        private readonly ConversationTransport $transport,
        private readonly InboxSuggestions $suggestions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function row(Conversation $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->customer->name ?? $c->contact_name,
            'wa_id' => $c->wa_id,
            'known' => $c->customer_id !== null,
            'branch' => $c->branch === null ? null : self::branchName($c->branch),
            'assignee' => $c->assignee === null ? null : ['id' => $c->assignee->id, 'name' => $c->assignee->name],
            'unread' => $c->unread_count,
            'window_open' => $c->windowOpen(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    public static function branchName(Branch $branch): string
    {
        return app()->getLocale() === 'en' && filled($branch->name_en) ? (string) $branch->name_en : $branch->name_ar;
    }

    /**
     * @param  list<Customer>  $customerResults
     * @return array<string, mixed>
     */
    public function detail(Conversation $c, User $viewer, array $customerResults = []): array
    {
        $messages = $c->messages()->with(['attachment', 'sender'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::THREAD_LIMIT)->get()->reverse()->values();
        $customer = $c->customer;
        $canWrite = Access::can($viewer, Permission::P108, $c);
        $optedOut = $customer?->whatsapp_opt_out_at !== null;

        return [
            ...self::row($c),
            'window_closes_at' => ServiceWindow::closesAt($c->last_inbound_at)?->toIso8601String(),
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'url' => CustomerAccess::canViewCustomer($viewer, $customer) ? route('customers.show', $customer->id) : null,
                'opted_out' => $optedOut,
            ],
            'messages' => $messages->map(fn (ConversationMessage $m) => $this->message($m, $viewer))->all(),
            'templates' => $this->settings->templates(),
            'configured' => $this->transport->isConfigured(),
            'can' => [
                'reply' => $canWrite && ! $optedOut && $this->transport->isConfigured(),
                'assign' => $canWrite,
                'link' => $canWrite && $customer === null,
            ],
            'assignable' => $canWrite ? array_map(fn (User $u) => ['id' => $u->id, 'name' => $u->name], ConversationAssignment::candidates($c)) : [],
            'customer_results' => array_map(fn (Customer $r) => ['id' => $r->id, 'name' => $r->name, 'mobile' => $r->mobile], $customerResults),
            'ai' => $this->suggestions->panel($c, $viewer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ConversationMessage $m, User $viewer): array
    {
        $params = null;
        $body = $m->body;
        if ($m->type === 'template') {
            $decoded = json_decode((string) $m->body, true);
            $params = is_array($decoded) ? array_values((array) ($decoded['params'] ?? [])) : [];
            $body = null;
        }

        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'type' => $m->type,
            'body' => $body,
            'template' => $m->template,
            'params' => $params,
            'status' => $m->status,
            'error' => $m->error,
            'media_status' => $m->media_status,
            'file' => $m->attachment === null ? null : [
                'name' => $m->attachment->original_name,
                'mime' => $m->attachment->mime,
                'size_bytes' => $m->attachment->size_bytes,
                'url' => $this->files->downloadUrl($m->attachment, $viewer),
            ],
            'sender' => $m->sender?->name,
            'ai_intent' => $m->ai_intent,
            'ai_suspicious' => $m->ai_suspicious,
            'from_suggestion' => $m->ai_suggestion_id !== null,
            'occurred_at' => $m->occurred_at->toIso8601String(),
        ];
    }
}
