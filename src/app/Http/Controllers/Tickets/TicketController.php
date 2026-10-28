<?php

namespace App\Http\Controllers\Tickets;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M21Ticket\AssignTicket;
use App\Actions\StateMachines\M21Ticket\ReplyToTicket;
use App\Actions\StateMachines\M21Ticket\T01TakeTicket;
use App\Actions\StateMachines\M21Ticket\T02RequestInformation;
use App\Actions\StateMachines\M21Ticket\T04ResolveTicket;
use App\Actions\StateMachines\M21Ticket\T05CloseTicket;
use App\Actions\StateMachines\M21Ticket\T06ReopenTicket;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\TicketMessageRequest;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\User;
use App\Tickets\TicketAccess;
use App\Tickets\TicketPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff ticket inbox and page (SHR-08, P-59): the scoped inbox (grant branches / assigned tickets), the page with the
 * conversation (public replies AND internal notes, visibly distinct) and the M-21 actions. Every write authorizes P-59
 * on the ticket inside its Action; the inbox is narrowed by the same grants (ScopedQuery), so a list never shows what
 * a direct open would refuse.
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly TicketPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(TicketAccess::any($actor), 403);

        $status = TicketStatus::tryFrom((string) $request->query('status'))?->value;
        $mine = $request->boolean('mine');
        $q = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($q, '%_\\').'%';

        $tickets = TicketAccess::scoped(Ticket::query(), $actor)->with(['customer', 'order', 'assignee'])
            ->when($status, fn ($query) => $query->where('tickets.status', $status))
            ->when($mine, fn ($query) => $query->where('tickets.assignee_id', $actor->id))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('tickets.number', 'like', $like)->orWhere('tickets.subject', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))))
            ->orderByRaw("tickets.status = 'closed'")->orderByDesc('tickets.id')
            ->paginate(self::PER_PAGE)->withQueryString()->through(fn (Ticket $t) => TicketPresenter::staffRow($t));

        return Inertia::render('tickets/index', [
            'tickets' => $tickets,
            'filters' => ['status' => $status, 'mine' => $mine, 'q' => $q],
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P59, $ticket);

        return Inertia::render('tickets/show', ['ticket' => $this->presenter->staffDetail($ticket, $actor)]);
    }

    public function take(Request $request, Ticket $ticket, T01TakeTicket $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket);

        return $this->done($ticket, 'tickets.flash.taken');
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket, (int) $request->validated('assignee_id'));

        return $this->done($ticket, 'tickets.flash.assigned');
    }

    public function reply(TicketMessageRequest $request, Ticket $ticket, ReplyToTicket $action): RedirectResponse
    {
        $visibility = (string) $request->validated('visibility', TicketMessage::PUBLIC);
        $action->handle($this->actor($request), $ticket, $visibility, (string) $request->validated('body'), $request->uploads());

        return $this->done($ticket, $visibility === TicketMessage::PUBLIC ? 'tickets.flash.replied' : 'tickets.flash.noted');
    }

    public function requestInfo(TicketMessageRequest $request, Ticket $ticket, T02RequestInformation $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket, (string) $request->validated('body'), $request->uploads());

        return $this->done($ticket, 'tickets.flash.info');
    }

    public function resolve(TicketMessageRequest $request, Ticket $ticket, T04ResolveTicket $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket, (string) $request->validated('body'));

        return $this->done($ticket, 'tickets.flash.resolved');
    }

    public function close(Request $request, Ticket $ticket, T05CloseTicket $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket);

        return $this->done($ticket, 'tickets.flash.closed');
    }

    public function reopen(TicketMessageRequest $request, Ticket $ticket, T06ReopenTicket $action): RedirectResponse
    {
        $action->handle($this->actor($request), $ticket, (string) $request->validated('body'));

        return $this->done($ticket, 'tickets.flash.reopened');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function done(Ticket $ticket, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return redirect()->route('tickets.show', $ticket->id);
    }
}
