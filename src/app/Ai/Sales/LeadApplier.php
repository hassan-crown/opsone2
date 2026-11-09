<?php

namespace App\Ai\Sales;

use App\Actions\Customers\UpdateInterest;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Ai\AiSuggestions;
use App\Customers\Phone;
use App\Enums\InterestChannel;
use App\Enums\InterestStatus;
use App\Inbox\InboxAccess;
use App\Models\Inbox\Conversation;
use App\Models\Interest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff accept (or edit) the extracted lead fields: only now does anything change, and only through the existing
 * M-03 Actions (P-09, branch scope, audit). The lead is the conversation's open interest (same mobile), else a new one
 * in the conversation's branch from the WhatsApp channel. The staff member's own open suggestion only.
 */
final class LeadApplier
{
    public function __construct(
        private readonly AiSuggestions $suggestions,
        private readonly T00CreateInterest $create,
        private readonly UpdateInterest $update,
    ) {}

    /**
     * @param  array<string, mixed>  $edited  the fields as staff left them in the form
     * @param  bool  $replaceName  on an existing lead the name is kept unless staff choose to replace it; the email only
     *                             fills an empty one, and the qualification lines are appended to `need`, never replacing it
     *
     * @throws AuthorizationException
     */
    public function accept(User $actor, Conversation $conversation, int $suggestionId, array $edited, bool $replaceName = false): Interest
    {
        if (! InboxAccess::canOpen($actor, $conversation)) {
            throw new AuthorizationException;
        }
        $suggestion = LeadQualifier::open($actor, $conversation);
        if ($suggestion === null || $suggestion->id !== $suggestionId) {
            throw ValidationException::withMessages(['suggestion' => __('inbox.ai.lead.errors.suggestion_gone')]);
        }
        if ($conversation->branch_id === null) {
            throw ValidationException::withMessages(['suggestion' => __('inbox.ai.lead.errors.no_branch')]);
        }

        $fields = LeadFields::clean($edited);
        $mobile = Phone::normalize((string) $conversation->wa_id) ?? (string) $conversation->wa_id;

        return DB::transaction(function () use ($actor, $conversation, $suggestion, $fields, $mobile, $replaceName) {
            $found = self::existing($conversation);
            $interest = $found === null ? null : Interest::query()->lockForUpdate()->find($found->id);

            $values = array_filter([
                'name' => $fields['name'],
                'email' => $fields['email'],
                'need' => LeadFields::need($fields),
            ], fn ($v) => $v !== null);

            if ($interest === null) {
                $interest = $this->create->handle($actor, [
                    'name' => $values['name'] ?? ($conversation->contact_name ?: $mobile),
                    'mobile' => $mobile,
                    'email' => $values['email'] ?? null,
                    'need' => $values['need'] ?? __('inbox.ai.lead.default_need'),
                    'channel' => InterestChannel::Whatsapp->value,
                    'branch_id' => $conversation->branch_id,
                    'customer_id' => $conversation->customer_id,
                ]);
            } else {
                $changes = [];
                if ($replaceName && isset($values['name'])) {
                    $changes['name'] = $values['name'];
                }
                if (($interest->email === null || $interest->email === '') && isset($values['email'])) {
                    $changes['email'] = $values['email'];
                }
                $need = self::mergeNeed((string) $interest->need, $values['need'] ?? null);
                if ($need !== (string) $interest->need) {
                    $changes['need'] = $need;
                }
                if ($changes !== []) {
                    $interest = $this->update->handle($actor, $interest, $changes);
                }
            }

            $this->suggestions->accept($suggestion, $actor, ['fields' => $fields] + array_diff_key($suggestion->payload, ['fields' => true]));

            return $interest;
        });
    }

    /**
     * The conversation's open lead (same mobile and branch), if staff already have one.
     */
    public static function existing(Conversation $conversation): ?Interest
    {
        $mobile = Phone::normalize((string) $conversation->wa_id) ?? (string) $conversation->wa_id;

        return Interest::query()->where('mobile', $mobile)
            ->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])
            ->where('branch_id', $conversation->branch_id)->orderByDesc('id')->first();
    }

    /**
     * Appends the qualification lines the lead does not already have.
     */
    private static function mergeNeed(string $current, ?string $new): string
    {
        if ($new === null) {
            return $current;
        }
        $have = array_map('trim', explode("\n", $current));
        $add = array_values(array_filter(explode("\n", $new), fn (string $line) => ! in_array(trim($line), $have, true)));

        return $add === [] ? $current : mb_substr(rtrim($current)."\n".implode("\n", $add), 0, 4000);
    }

    public function reject(User $actor, Conversation $conversation, int $suggestionId): bool
    {
        if (! InboxAccess::canOpen($actor, $conversation)) {
            throw new AuthorizationException;
        }
        $suggestion = LeadQualifier::open($actor, $conversation);
        if ($suggestion === null || $suggestion->id !== $suggestionId) {
            return false;
        }
        $this->suggestions->reject($suggestion, $actor);

        return true;
    }
}
