<?php

namespace App\Http\Controllers\Inbox;

use App\Access\Access;
use App\Access\Permission;
use App\Ai\Sales\LeadApplier;
use App\Ai\Sales\LeadBooking;
use App\Ai\Sales\LeadOutcome;
use App\Ai\Sales\LeadQualifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\AcceptLeadFieldsRequest;
use App\Http\Requests\Inbox\BookLeadSlotRequest;
use App\Inbox\InboxAccess;
use App\Inbox\InboxSettings;
use App\Models\Inbox\Conversation;
use App\Models\User;
use App\Workflow\AppointmentConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * AI-7a on the inbox conversation: draft + qualification fields for a new lead, accepting the fields, and booking a
 * slot the server computed. Each write is an explicit staff request; nothing is sent, created or booked by the model.
 */
final class LeadAssistController extends Controller
{
    public function __construct(private readonly InboxSettings $settings) {}

    public function suggest(Request $request, Conversation $conversation, LeadQualifier $qualifier): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->guard($actor, $conversation);

        $outcome = $qualifier->suggest($actor, $conversation->load('customer'));
        abort_if($outcome->status === LeadOutcome::DENIED, 403);

        return $this->back($conversation, $outcome->ready() ? 'success' : 'info', __('inbox.ai.lead.outcome.'.$outcome->status));
    }

    public function accept(AcceptLeadFieldsRequest $request, Conversation $conversation, int $suggestion, LeadApplier $applier): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->guard($actor, $conversation);
        abort_unless(Access::can($actor, Permission::P09), 403);

        $applier->accept($actor, $conversation, $suggestion, $request->validated());

        return $this->back($conversation, 'success', __('inbox.ai.lead.flash.accepted'));
    }

    public function reject(Request $request, Conversation $conversation, int $suggestion, LeadApplier $applier): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->guard($actor, $conversation);
        abort_unless($applier->reject($actor, $conversation, $suggestion), 404);

        return $this->back($conversation, 'success', __('inbox.ai.lead.flash.rejected'));
    }

    public function book(BookLeadSlotRequest $request, Conversation $conversation, LeadBooking $booking): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->guard($actor, $conversation);
        abort_unless(Access::can($actor, Permission::P54), 403);
        $data = $request->validated();

        try {
            $booking->book($actor, $conversation, (int) $data['order_id'], (string) $data['starts_at'], (int) $data['member_id'], (string) $data['address']);
        } catch (AppointmentConflict $e) {
            throw ValidationException::withMessages(['slot' => __('workflow.appointment.'.$e->condition)]);
        }

        return $this->back($conversation, 'success', __('inbox.ai.lead.flash.booked'));
    }

    private function guard(User $actor, Conversation $conversation): void
    {
        abort_unless($this->settings->enabled(), 404);
        abort_unless(InboxAccess::canOpen($actor, $conversation), 403);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function back(Conversation $conversation, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->route('inbox.show', $conversation->id);
    }
}
