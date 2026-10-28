<?php

namespace App\Http\Controllers\Portal;

use App\Files\Rules\AllowedAttachment;
use App\Models\Tickets\Ticket;
use App\Portal\CustomerTickets;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use App\Tickets\TicketMessages;
use App\Tickets\TicketPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tickets in the customer workspace (SHR-08, P-71): the list («الدعم»), a new ticket (general or linked to one of the
 * customer's orders, also from the order page) and the ticket page with the public conversation. Only the customer's
 * own tickets exist here: a foreign id is 404, and a foreign order id is a validation error. Public messages only.
 */
final class TicketController extends PortalController
{
    public function __construct(private readonly CustomerTickets $tickets) {}

    public function index(): Response
    {
        $tickets = PortalAccess::tickets($this->customer())->with('order')->orderByRaw("tickets.status = 'closed'")->latest('tickets.id')->limit(200)->get();

        return Inertia::render('portal/tickets', [
            'tickets' => array_values($tickets->map(fn (Ticket $t) => TicketPresenter::customerRow($t))->all()),
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = $this->customer();
        $orders = PortalAccess::orders($customer)->latest('id')->limit(100)->get();
        $linked = $request->query('order');
        $preset = is_string($linked) ? $orders->firstWhere('id', (int) $linked)?->id : null;

        return Inertia::render('portal/ticket-new', [
            'orders' => PortalPresenter::orders($orders),
            'order_id' => $preset,
            'max_files' => TicketMessages::MAX_FILES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(CustomerTickets::rules());
        $ticket = $this->tickets->open($this->actor(), $data, array_values($request->file('files', [])));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('tickets.flash.opened', ['number' => $ticket->number])]);

        return redirect()->route('customer.tickets.show', $ticket->id);
    }

    public function show(string $ticket): Response
    {
        $customer = $this->customer();

        return Inertia::render('portal/ticket', [
            'ticket' => TicketPresenter::customerDetail($customer, PortalAccess::ticket($customer, $ticket)),
            'max_files' => TicketMessages::MAX_FILES,
        ]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $record = PortalAccess::ticket($this->customer(), $ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:'.TicketMessages::MAX_FILES],
            'files.*' => ['file', new AllowedAttachment],
        ]);
        $this->tickets->reply($this->actor(), $record, $data['body'], array_values($request->file('files', [])));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('tickets.flash.sent')]);

        return redirect()->route('customer.tickets.show', $record->id);
    }

    public function close(string $ticket): RedirectResponse
    {
        $record = PortalAccess::ticket($this->customer(), $ticket);
        $this->tickets->close($this->actor(), $record);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('tickets.flash.customer_closed')]);

        return redirect()->route('customer.tickets.show', $record->id);
    }
}
