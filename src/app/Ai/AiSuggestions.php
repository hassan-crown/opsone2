<?php

namespace App\Ai;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Ai\AiSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Drafts from the AI (ADR-0050 §1: «مقترِح لا منفّذ»). A feature stores its successful AiResult here; the staff
 * member then accepts (as is), edits, or rejects it. Accepting does NOT execute anything: the caller runs the
 * existing Action in the same transaction, so permissions, audit and releases stay in the path, and records
 * `source: ai_suggestion` there. The audit entry here never carries the payload.
 */
final class AiSuggestions
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function propose(AiTask $task, AiResult $result, array $payload, ?User $requestedBy = null, ?Model $subject = null): AiSuggestion
    {
        if (! $result->ok()) {
            throw new LogicException('Only a successful AI result becomes a suggestion.');
        }

        return AiSuggestion::query()->create([
            'task' => $task->value,
            'ai_usage_id' => $result->usageId,
            'status' => AiSuggestionStatus::Proposed,
            'payload' => $payload,
            'requested_by' => $requestedBy?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'expires_at' => now()->addDays(max(1, (int) config('ai.suggestion_ttl_days', 14))),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $final  what was actually applied, when the staff member changed the draft
     */
    public function accept(AiSuggestion $suggestion, User $actor, ?array $final = null): AiSuggestion
    {
        $edited = $final !== null && $final != $suggestion->payload;

        return $this->decide($suggestion, $actor, $edited ? AiSuggestionStatus::Edited : AiSuggestionStatus::Accepted, $edited ? $final : null);
    }

    public function reject(AiSuggestion $suggestion, User $actor): AiSuggestion
    {
        return $this->decide($suggestion, $actor, AiSuggestionStatus::Rejected, null);
    }

    /**
     * Marks open suggestions past their expiry. Returns how many changed.
     */
    public function expireDue(): int
    {
        return AiSuggestion::query()
            ->where('status', AiSuggestionStatus::Proposed->value)
            ->where('expires_at', '<=', now())
            ->update(['status' => AiSuggestionStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $final
     */
    private function decide(AiSuggestion $suggestion, User $actor, AiSuggestionStatus $status, ?array $final): AiSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor, $status, $final) {
            /** @var AiSuggestion $locked */
            $locked = AiSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            if ($locked->status !== AiSuggestionStatus::Proposed || ($locked->expires_at !== null && $locked->expires_at->isPast())) {
                throw new LogicException('This suggestion is no longer open.');
            }

            $locked->forceFill([
                'status' => $status,
                'final_payload' => $final,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            Audit::record(
                $status === AiSuggestionStatus::Rejected ? AuditAction::AiSuggestionRejected : AuditAction::AiSuggestionAccepted,
                $locked, $actor,
                after: ['task' => $locked->task, 'status' => $status->value],
                label: $locked->task,
            );

            return $locked;
        });
    }
}
