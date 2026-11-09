<?php

namespace App\Http\Controllers\Inbox;

use App\Ai\Inbox\InboxSuggestions;
use App\Ai\Inbox\ReplySuggester;
use App\Ai\Inbox\SuggestOutcome;
use App\Customers\CustomerAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\AssignConversationRequest;
use App\Http\Requests\Inbox\LinkConversationRequest;
use App\Http\Requests\Inbox\SendReplyRequest;
use App\Inbox\ConversationAssignment;
use App\Inbox\ConversationCustomers;
use App\Inbox\InboxAccess;
use App\Inbox\InboxPresenter;
use App\Inbox\InboxSettings;
use App\Inbox\ReplySender;
use App\Models\Customer;
use App\Models\Inbox\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WhatsApp inbox (AI-3, ADR-0030 amendment): the scoped conversation list and the conversation page (thread,
 * composer, assignment, customer link), and the AI-4 reply suggestion of the current staff member. Every page answers
 * 404 while the owner has the inbox switched off; every write authorizes P-108 on the conversation in its service.
 */
final class InboxController extends Controller
{
    private const PER_PAGE = 20;

    private const VIEWS = ['all', 'mine', 'unassigned', 'unread'];

    public function __construct(private readonly InboxSettings $settings) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->ensureEnabled();
        abort_unless(InboxAccess::any($actor), 403);

        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'all';
        $q = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($q, '%_\\').'%';

        $conversations = InboxAccess::scoped(Conversation::query(), $actor)->with(['customer', 'assignee', 'branch'])
            ->when($view === 'mine', fn ($query) => $query->where('conversations.assignee_id', $actor->id))
            ->when($view === 'unassigned', fn ($query) => $query->whereNull('conversations.assignee_id'))
            ->when($view === 'unread', fn ($query) => $query->where('conversations.unread_count', '>', 0))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('conversations.wa_id', 'like', $like)
                ->orWhere('conversations.contact_name', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))))
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')
            ->paginate(self::PER_PAGE)->withQueryString()->through(fn (Conversation $c) => InboxPresenter::row($c));

        return Inertia::render('inbox/index', [
            'conversations' => $conversations,
            'filters' => ['view' => $view, 'q' => $q],
        ]);
    }

    public function show(Request $request, Conversation $conversation, InboxPresenter $presenter): Response
    {
        $actor = $this->actor($request);
        $this->ensureEnabled();
        abort_unless(InboxAccess::canOpen($actor, $conversation), 403);

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->saveQuietly();
        }
        $conversation->load(['customer', 'assignee', 'branch']);

        $results = [];
        $search = trim((string) $request->query('customer_q', ''));
        if ($conversation->customer_id === null && mb_strlen($search) >= 2) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $results = array_values(CustomerAccess::visibleCustomers($actor)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('mobile', 'like', $like))
                ->orderBy('name')->limit(10)->get()->all());
        }

        return Inertia::render('inbox/show', [
            'conversation' => $presenter->detail($conversation, $actor, $results),
            'customerQuery' => $search,
        ]);
    }

    public function reply(SendReplyRequest $request, Conversation $conversation, ReplySender $sender, InboxSuggestions $suggestions): RedirectResponse
    {
        $actor = $this->actor($request);
        $key = (string) $request->validated('key');

        if ($request->validated('kind') === 'template') {
            $sender->template($actor, $conversation, (string) $request->validated('template'), (string) $request->validated('language'), array_values(array_map(fn ($p) => (string) $p, (array) $request->validated('params', []))), $key);
        } else {
            // The reply goes through the normal path (P-108, opt-out, window); a suggestion is only marked afterwards.
            $message = $sender->text($actor, $conversation, (string) $request->validated('body'), $key);
            $suggestionId = $request->validated('suggestion_id');
            if ($suggestionId !== null) {
                $suggestions->sent($actor, $conversation, (int) $suggestionId, $message);
            }
        }

        return $this->done($conversation, 'inbox.flash.queued');
    }

    public function suggest(Request $request, Conversation $conversation, ReplySuggester $suggester): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->ensureEnabled();
        abort_unless(InboxAccess::canOpen($actor, $conversation), 403);

        $outcome = $suggester->suggest($actor, $conversation->load('customer'));
        abort_if($outcome->status === SuggestOutcome::DENIED, 403);

        Inertia::flash('toast', [
            'type' => $outcome->ready() ? 'success' : 'info',
            'message' => __('inbox.ai.outcome.'.$outcome->status),
        ]);

        return redirect()->route('inbox.show', $conversation->id);
    }

    public function rejectSuggestion(Request $request, Conversation $conversation, int $suggestion, InboxSuggestions $suggestions): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->ensureEnabled();
        abort_unless(InboxAccess::canOpen($actor, $conversation), 403);
        abort_unless($suggestions->reject($actor, $conversation, $suggestion), 404);

        return $this->done($conversation, 'inbox.flash.suggestion_rejected');
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation, ConversationAssignment $assignment): RedirectResponse
    {
        $this->ensureEnabled();
        $id = $request->validated('assignee_id');
        $assignment->assign($this->actor($request), $conversation, $id === null ? null : (int) $id);

        return $this->done($conversation, 'inbox.flash.assigned');
    }

    public function link(LinkConversationRequest $request, Conversation $conversation, ConversationCustomers $customers): RedirectResponse
    {
        $this->ensureEnabled();
        $customers->link($this->actor($request), $conversation, Customer::query()->findOrFail((int) $request->validated('customer_id')));

        return $this->done($conversation, 'inbox.flash.linked');
    }

    private function ensureEnabled(): void
    {
        abort_unless($this->settings->enabled(), 404);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function done(Conversation $conversation, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return redirect()->route('inbox.show', $conversation->id);
    }
}
