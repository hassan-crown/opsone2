<?php

namespace App\Http\Controllers\Inbox;

use App\Ai\AiSuggestions;
use App\Ai\Inbox\SuggestOutcome;
use App\Ai\Orders\ConfirmOrderDraft;
use App\Ai\Orders\ConversationToOrderProposer;
use App\Ai\Orders\OrderDraftScreen;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\OrderDraftRequest;
use App\Inbox\InboxAccess;
use App\Inbox\InboxSettings;
use App\Models\Ai\AiSuggestion;
use App\Models\Inbox\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * «مسودة طلب من المحادثة» (AI-5): ask for a draft, review it, see the server's prices, then create the order through
 * the normal Action or reject the draft. A draft belongs to the staff member it was made for; anyone else gets 404.
 * Every request needs the inbox on and the conversation open to the actor (P-108 in scope).
 *
 * Only `propose` needs the AI to be available (feature switch, `ai_plus`, credits — checked by the gateway). Showing,
 * pricing, confirming and rejecting an existing draft make no AI call, so they keep working after the facility switches
 * the feature off or the license loses `ai_plus`: a draft already reviewed by a staff member can still become an order
 * through the normal Action, or be rejected. The button to ask for a new draft disappears.
 */
final class OrderDraftController extends Controller
{
    public function __construct(private readonly InboxSettings $settings) {}

    public function propose(Request $request, Conversation $conversation, ConversationToOrderProposer $proposer): RedirectResponse
    {
        $actor = $this->open($request, $conversation);
        abort_unless(ConversationToOrderProposer::mayDraft($actor, $conversation), 403);

        $outcome = $proposer->propose($actor, $conversation->load('customer'));
        abort_if($outcome->status === SuggestOutcome::DENIED, 403);

        if ($outcome->ready() && $outcome->suggestion !== null) {
            return redirect()->route('inbox.order-draft.show', [$conversation->id, $outcome->suggestion->id]);
        }

        Inertia::flash('toast', ['type' => 'info', 'message' => __('inbox.order_draft.outcome.'.$outcome->status)]);

        return redirect()->route('inbox.show', $conversation->id);
    }

    public function show(Request $request, Conversation $conversation, int $draft): Response
    {
        $actor = $this->open($request, $conversation);
        $suggestion = $this->draft($actor, $conversation, $draft);

        return Inertia::render('inbox/order-draft', OrderDraftScreen::props($actor, $conversation->load('customer'), $suggestion));
    }

    public function price(OrderDraftRequest $request, Conversation $conversation, int $draft): JsonResponse
    {
        $actor = $this->open($request, $conversation);
        $this->draft($actor, $conversation, $draft);
        $data = $request->draftData();

        /** @var list<array<string, mixed>> $lines */
        $lines = array_values((array) $data['lines']);

        return response()->json(OrderDraftScreen::pricing($actor, (int) $data['branch_id'], $lines));
    }

    public function confirm(OrderDraftRequest $request, Conversation $conversation, int $draft, ConfirmOrderDraft $confirm): JsonResponse|RedirectResponse
    {
        $actor = $this->open($request, $conversation);
        // Not only open drafts: a retry after a lost response replays the created orders (ConfirmOrderDraft).
        $suggestion = ConversationToOrderProposer::own($actor, $conversation, $draft) ?? abort(404);
        abort_unless(ConversationToOrderProposer::mayDraft($actor, $conversation), 403);

        $ids = $confirm->handle($actor, $conversation->load('customer'), $suggestion, $request->draftData());
        if ($request->expectsJson()) {
            return response()->json(['order_ids' => $ids, 'redirect' => route('orders.show', $ids[0])], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inbox.order_draft.created')]);

        return redirect()->route('orders.show', $ids[0]);
    }

    public function reject(Request $request, Conversation $conversation, int $draft, AiSuggestions $suggestions): RedirectResponse
    {
        $actor = $this->open($request, $conversation);
        $suggestion = $this->draft($actor, $conversation, $draft);
        try {
            $suggestions->reject($suggestion, $actor);
        } catch (LogicException) {
            abort(404);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inbox.order_draft.rejected')]);

        return redirect()->route('inbox.show', $conversation->id);
    }

    private function open(Request $request, Conversation $conversation): User
    {
        abort_unless($this->settings->enabled(), 404);
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(InboxAccess::canOpen($actor, $conversation), 403);

        return $actor;
    }

    private function draft(User $actor, Conversation $conversation, int $id): AiSuggestion
    {
        return ConversationToOrderProposer::open($actor, $conversation, $id) ?? abort(404);
    }
}
