<?php

namespace App\Http\Controllers\SupportAccess;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Actions\StateMachines\M38SupportAccess\SupportAccessTransition;
use App\Actions\StateMachines\M38SupportAccess\T01GrantSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T02RejectSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T03RevokeSupportAccess;
use App\Actions\StateMachines\M38SupportAccess\T04ExpireSupportAccess;
use App\Enums\SupportGrantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupportAccess\GrantSupportAccessRequest;
use App\Models\AuditEvent;
use App\Models\SupportGrant;
use App\Models\User;
use App\SupportAccess\SupportArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SupportAccess (SHR-14, P-79): the owner sees OpsOne support's requests and active grants, grants (after
 * confirming their password), rejects or revokes them, and reads everything support did under each grant.
 */
class SupportAccessController extends Controller
{
    private const HISTORY = 20;

    private const ACTIVITY = 50;

    public function __construct(private readonly Authorizer $access) {}

    public function index(Request $request, T04ExpireSupportAccess $expire): Response
    {
        $this->access->authorize($request->user(), Permission::P79);
        $expire->handleDue();

        $open = SupportGrant::query()->with(['supportUser', 'decidedBy'])
            ->whereIn('status', [SupportGrantStatus::Requested->value, SupportGrantStatus::Active->value])
            ->orderByDesc('id')->get();
        $history = SupportGrant::query()->with(['supportUser', 'decidedBy'])
            ->whereNotIn('status', [SupportGrantStatus::Requested->value, SupportGrantStatus::Active->value])
            ->orderByDesc('id')->limit(self::HISTORY)->get();

        $ids = $open->pluck('id')->merge($history->pluck('id'))->all();
        $activity = AuditEvent::query()
            ->where('subject_type', (new SupportGrant)->getMorphClass())
            ->whereIn('subject_id', $ids === [] ? [0] : $ids)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::ACTIVITY)->get()
            ->map(fn (AuditEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'actor' => $e->actor_name,
                'ticket' => $e->subject_label,
                'path' => is_array($e->after) && is_string($e->after['path'] ?? null) ? $e->after['path'] : null,
                'at' => $e->occurred_at->toIso8601String(),
            ])->all();

        return Inertia::render('account/support-access', [
            'requested' => $open->where('status', SupportGrantStatus::Requested)->map(fn (SupportGrant $g) => $this->row($g))->values()->all(),
            'active' => $open->where('status', SupportGrantStatus::Active)->map(fn (SupportGrant $g) => $this->row($g))->values()->all(),
            'history' => $history->map(fn (SupportGrant $g) => $this->row($g))->values()->all(),
            'activity' => $activity,
            'areas' => SupportArea::values(),
            'maxHours' => (int) config('opsone.support.max_access_hours', 72),
        ]);
    }

    public function grant(GrantSupportAccessRequest $request, SupportGrant $grant, T01GrantSupportAccess $action): RedirectResponse
    {
        /** @var list<string> $areas */
        $areas = array_values($request->validated('areas'));

        return $this->decide(fn () => $action->handle($this->user($request), $grant, $areas, (int) $request->validated('hours')), 'support_access.granted');
    }

    public function reject(Request $request, SupportGrant $grant, T02RejectSupportAccess $action): RedirectResponse
    {
        $this->access->authorize($request->user(), Permission::P79);

        return $this->decide(fn () => $action->handle($this->user($request), $grant), 'support_access.rejected');
    }

    public function revoke(Request $request, SupportGrant $grant, T03RevokeSupportAccess $action): RedirectResponse
    {
        $this->access->authorize($request->user(), Permission::P79);

        return $this->decide(fn () => $action->handle($this->user($request), $grant), 'support_access.revoked');
    }

    /**
     * @param  callable(): SupportGrant  $transition
     */
    private function decide(callable $transition, string $message): RedirectResponse
    {
        try {
            $transition();
        } catch (InvalidTransition $e) {
            abort_if($e->condition === 'not_authorized', 403);

            return back()->withErrors(['transition' => __('support_access.error.'.$e->condition)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return redirect()->route('support-access.index');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SupportGrant $grant): array
    {
        return [
            'id' => $grant->uuid,
            'ticket' => SupportAccessTransition::label($grant),
            'support_name' => $grant->supportUser->name,
            'reason' => $grant->reason,
            'status' => $grant->status->value,
            'requested_areas' => $grant->requested_areas,
            'requested_hours' => $grant->requested_hours,
            'areas' => $grant->areas,
            'hours' => $grant->hours,
            'request_expires_at' => $grant->request_expires_at->toIso8601String(),
            'starts_at' => $grant->starts_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'ended_at' => $grant->ended_at?->toIso8601String(),
            'decided_by' => $grant->decidedBy?->name,
        ];
    }
}
