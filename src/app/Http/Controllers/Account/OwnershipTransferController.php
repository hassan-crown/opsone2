<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Actions\StateMachines\M37OwnershipTransfer\T00RequestOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T01AcceptOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T02RejectOwnershipTransfer;
use App\Actions\StateMachines\M37OwnershipTransfer\T03CancelOwnershipTransfer;
use App\Enums\MembershipStatus;
use App\Enums\OwnershipTransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DecideOwnershipTransferRequest;
use App\Http\Requests\Account\RequestOwnershipTransferRequest;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ownership transfer over M-37: the owner requests (re-verifying their password), the chosen active member accepts
 * or rejects, the owner may cancel; an undecided request lapses after seven days (`access:expire`).
 */
class OwnershipTransferController extends Controller
{
    /** Failed conditions that mean the caller is not the right party (not a stale state). */
    private const FORBIDDEN_CONDITIONS = ['not_owner', 'not_recipient', 'not_requester'];

    public function show(Request $request): Response
    {
        $user = $request->user();
        $mine = Membership::query()->where('user_id', $user->id)->first();
        $isOwnerRole = Access::can($user, Permission::P07);

        $open = OwnershipTransfer::query()
            ->with(['from.user', 'to.user'])
            ->where('status', OwnershipTransferStatus::Requested->value)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        $role = match (true) {
            $mine === null || $open === null => null,
            $open->from_membership_id === $mine->id => 'requester',
            $open->to_membership_id === $mine->id => 'recipient',
            default => null,
        };

        abort_unless($isOwnerRole || $role === 'recipient', 403);

        $facility = Facility::currentOrFail();

        return Inertia::render('account/ownership', [
            'owner' => $facility->owner()->with('user:id,name,email')->first()?->user?->only(['name', 'email']),
            'isOwner' => $isOwnerRole,
            'open' => $open === null || $role === null ? null : [
                'id' => $open->id,
                'role' => $role,
                'from' => $open->from->user->only(['name', 'email']),
                'to' => $open->to->user->only(['name', 'email']),
                'reason' => $open->reason,
                'expires_at' => $open->expires_at->toIso8601ZuluString(),
                'created_at' => $open->created_at?->toIso8601ZuluString(),
            ],
            'candidates' => $isOwnerRole && $open === null && $mine !== null
                ? Membership::query()
                    ->with('user:id,name,email')
                    ->where('status', MembershipStatus::Active->value)
                    ->whereKeyNot($mine->id)
                    ->get()
                    ->map(fn (Membership $m): array => [
                        'id' => $m->id,
                        'name' => $m->user->name,
                        'email' => $m->user->email,
                        'job_title' => $m->job_title,
                    ])
                    ->sortBy('name')->values()->all()
                : [],
            'history' => $isOwnerRole
                ? OwnershipTransfer::query()
                    ->with(['from.user:id,name', 'to.user:id,name'])
                    ->where(fn ($q) => $q->where('status', '!=', OwnershipTransferStatus::Requested->value)
                        ->orWhere('expires_at', '<=', now()))
                    ->latest('id')->limit(5)->get()
                    ->map(fn (OwnershipTransfer $t): array => [
                        'id' => $t->id,
                        'from' => $t->from->user->name,
                        'to' => $t->to->user->name,
                        'status' => $t->status === OwnershipTransferStatus::Requested ? 'expired' : $t->status->value,
                        'at' => ($t->decided_at ?? $t->expires_at)->toIso8601ZuluString(),
                    ])->all()
                : [],
        ]);
    }

    public function store(RequestOwnershipTransferRequest $request, T00RequestOwnershipTransfer $action): RedirectResponse
    {
        $recipient = Membership::query()->findOrFail($request->integer('recipient_id'));

        return $this->decide(
            fn () => $action->handle($request->user(), $request->string('current_password')->toString(), $recipient, $request->input('reason')),
            'account.ownership.requested',
        );
    }

    public function accept(Request $request, OwnershipTransfer $transfer, T01AcceptOwnershipTransfer $action): RedirectResponse
    {
        return $this->decide(fn () => $action->handle($request->user(), $transfer), 'account.ownership.accepted');
    }

    public function reject(DecideOwnershipTransferRequest $request, OwnershipTransfer $transfer, T02RejectOwnershipTransfer $action): RedirectResponse
    {
        return $this->decide(fn () => $action->handle($request->user(), $transfer, $request->input('reason')), 'account.ownership.rejected');
    }

    public function cancel(DecideOwnershipTransferRequest $request, OwnershipTransfer $transfer, T03CancelOwnershipTransfer $action): RedirectResponse
    {
        return $this->decide(fn () => $action->handle($request->user(), $transfer, $request->input('reason')), 'account.ownership.cancelled');
    }

    /**
     * @param  callable(): mixed  $run
     */
    private function decide(callable $run, string $successKey): RedirectResponse
    {
        try {
            $run();
        } catch (InvalidTransition $e) {
            abort_if(in_array($e->condition, self::FORBIDDEN_CONDITIONS, true), 403);

            return to_route('ownership.show')->withErrors(['transfer' => __('account.ownership.error.'.$e->condition)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($successKey)]);

        return to_route('ownership.show');
    }
}
