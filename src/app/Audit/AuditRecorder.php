<?php

namespace App\Audit;

use App\Access\Contracts\ScopedRecord;
use App\Access\TransitionRecorder;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Appends one row to `audit_events` (ADR-0006). Call INSIDE the transaction that makes the change, so the change
 * and its audit row commit or roll back together.
 *
 * Callers pass only the fields worth auditing; values are redacted again here (secrets, tokens, codes) and long
 * strings are cut. The IP address is stored as a keyed hash, never in clear.
 */
final class AuditRecorder
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE = '/(password|secret|token|_hash$|^hash$|two_factor|recovery|remember|otp|proof|api_key|private_key|signature|cvv|card_number|iban)/i';

    private const MAX_STRING = 500;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<int>|null  $branchIds  scope facts; derived from a ScopedRecord subject when null
     */
    public function record(
        AuditAction $action,
        ?Model $subject,
        ?User $actor,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        string $source = TransitionRecorder::SOURCE_WEB,
        ?array $branchIds = null,
        ?int $departmentId = null,
        ?string $label = null,
    ): AuditEvent {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Audit events must be recorded inside the transaction that makes the change.');
        }

        if ($subject instanceof ScopedRecord) {
            $branchIds ??= $subject->accessBranchIds();
            $departmentId ??= $subject->accessDepartmentId();
        }

        $fromRequest = $source === TransitionRecorder::SOURCE_WEB;

        $event = AuditEvent::query()->create([
            'action' => $action->value,
            'area' => $action->area(),
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'source' => $source,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? ($subject === null ? null : $this->labelOf($subject)),
            'department_id' => $departmentId,
            'before' => $before === [] ? null : self::redact($before),
            'after' => $after === [] ? null : self::redact($after),
            'reason' => $reason === null ? null : Str::limit($reason, 2000),
            'ip_hash' => $fromRequest ? $this->ipHash() : null,
            'user_agent' => $fromRequest ? $this->userAgent() : null,
            'request_id' => self::requestId(),
            'occurred_at' => now(),
        ]);

        $branchIds = array_values(array_unique(array_map(intval(...), $branchIds ?? [])));
        if ($branchIds !== []) {
            DB::table('audit_event_branches')->insert(array_map(
                fn (int $id) => ['audit_event_id' => $event->id, 'branch_id' => $id],
                $branchIds,
            ));
        }

        return $event;
    }

    /**
     * Recursively replaces sensitive values and bounds long strings. Also applied when events are displayed.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } elseif ($value instanceof \BackedEnum) {
                $result[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $result[$key] = $value->format(DATE_ATOM);
            } elseif (is_string($value)) {
                $result[$key] = Str::limit($value, self::MAX_STRING);
            } else {
                $result[$key] = is_scalar($value) || $value === null ? $value : get_debug_type($value);
            }
        }

        return $result;
    }

    /**
     * One id per request (or console run), taken from a well-formed `X-Request-Id` header when present.
     */
    public static function requestId(): string
    {
        $id = Context::getHidden('audit_request_id');
        if (is_string($id)) {
            return $id;
        }

        $header = app()->bound('request') ? request()->header('X-Request-Id') : null;
        $id = is_string($header) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $header) === 1 ? $header : (string) Str::uuid();
        Context::addHidden('audit_request_id', $id);

        return $id;
    }

    private function ipHash(): ?string
    {
        $ip = $this->request()->ip();

        return $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function userAgent(): ?string
    {
        $agent = $this->request()->userAgent();

        return $agent === null || $agent === '' ? null : Str::limit($agent, 250, '');
    }

    private function request(): Request
    {
        return app('request');
    }

    private function labelOf(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof User => $subject->name,
            $subject instanceof Membership => $subject->user->name,
            $subject instanceof Branch => self::bilingual($subject->name_ar, $subject->name_en),
            default => self::nameAttribute($subject),
        };
    }

    private static function nameAttribute(Model $subject): ?string
    {
        $ar = $subject->getAttribute('name_ar');
        $en = $subject->getAttribute('name_en');
        if (is_string($ar)) {
            return self::bilingual($ar, is_string($en) ? $en : null);
        }

        $name = $subject->getAttribute('name');

        return is_string($name) ? $name : null;
    }

    private static function bilingual(string $ar, ?string $en): string
    {
        return $en === null || $en === '' || $en === $ar ? $ar : $ar.' / '.$en;
    }
}
