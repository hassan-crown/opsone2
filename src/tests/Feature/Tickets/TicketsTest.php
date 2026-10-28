<?php

/**
 * Customer support tickets (P8 part D, SHR-08, M-21, ADR-0024): customer isolation, own-orders-only linking, the wall
 * between public messages and internal notes (props, notifications, downloads), the staff scope matrix of P-59, allowed
 * vs refused transitions without side effects, attachment authorization, notifications through the outbox, audit and
 * `state_transitions`.
 */

use App\Actions\BranchTransfer\TransferOrderBranch;
use App\Enums\TicketStatus;
use App\Files\AttachmentService;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\StateTransition;
use App\Models\Tickets\Ticket;
use App\Models\Tickets\TicketMessage;
use App\Models\UserNotification;
use App\Portal\PortalFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Orders/OrderTestHelpers.php';
require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake();
    $f = ordersFixture();
    $org = $f->org;
    $f->owner = $org->owner;
    $f->managerA = $org->member('manager', [$org->a]);
    $f->managerB = $org->member('manager', [$org->b]);
    $f->executor = $org->member('executor', [$org->a], $org->d1);
    $f->custA = Customer::factory()->create(['branch_id' => $org->a->id, 'portal_login_enabled' => true]);
    $f->custB = Customer::factory()->create(['branch_id' => $org->a->id, 'portal_login_enabled' => true]);
    $f->custC = Customer::factory()->create(['branch_id' => $org->b->id, 'portal_login_enabled' => true]);
    $f->orderA = tkOrder($f->sales, $f->custA);
    $f->orderB = tkOrder($f->sales, $f->custB);
    Auth::forgetGuards();
    $this->f = $f;
});

function tkOrder(object $actor, Customer $customer): Order
{
    test()->actingAs($actor)->postJson('/orders', customPayload($customer))->assertCreated();

    return Order::query()->latest('id')->firstOrFail();
}

function asCustomer(Customer $customer): void
{
    Auth::forgetGuards();
    actingAsCustomer($customer);
}

function asStaff(object $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user);
}

/**
 * @param  array<string, mixed>  $extra
 */
function tkOpen(Customer $customer, array $extra = [], ?array $files = null): Ticket
{
    asCustomer($customer);
    $payload = ['type' => 'issue', 'subject' => 'الباب لا يغلق', 'body' => 'الباب لا يغلق جيدًا بعد التركيب', ...$extra];
    if ($files !== null) {
        $payload['files'] = $files;
    }
    test()->post('/portal/tickets', $payload)->assertRedirect();

    return Ticket::query()->latest('id')->firstOrFail();
}

function tkPdf(string $name = 'photo.pdf', string $body = 'x'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body);
}

/**
 * Everything a refused step must leave untouched.
 *
 * @return array<string, mixed>
 */
function tkState(): array
{
    return [
        'tickets' => DB::table('tickets')->orderBy('id')->get()->all(),
        'messages' => DB::table('ticket_messages')->count(),
        'transitions' => StateTransition::query()->count(),
        'audit' => DB::table('audit_events')->count(),
        'outbox' => OutboxMessage::query()->count(),
        'notifications' => UserNotification::query()->count(),
        'attachments' => Attachment::query()->count(),
    ];
}

it('opens a general ticket and one linked to the customer\'s own order, assigned by default and logged', function () {
    $f = $this->f;
    $general = tkOpen($f->custA, ['type' => 'question']);
    expect($general->status)->toBe(TicketStatus::New)
        ->and($general->order_id)->toBeNull()
        ->and($general->branch_id)->toBe($f->custA->branch_id)
        ->and($general->number)->toMatch('/^TK-\d{4}-\d{6}$/');

    $linked = tkOpen($f->custA, ['order_id' => $f->orderA->id], [tkPdf()]);
    expect($linked->order_id)->toBe($f->orderA->id)
        ->and($linked->branch_id)->toBe($f->orderA->branch_id)
        ->and($linked->assignee_id)->not->toBeNull()
        ->and($linked->messages)->toHaveCount(1)
        ->and($linked->messages->first()->attachments)->toHaveCount(1);

    expect(StateTransition::query()->where('machine', 'M-21')->where('transition', 'T00')->count())->toBe(2)
        ->and(DB::table('audit_events')->where('action', 'ticket.opened')->count())->toBe(2);
    // The assignee is told through the in-app notice (opening re-checks P-59).
    expect(UserNotification::query()->where('type', 'ticket.opened')->count())->toBe(2);
});

it('a double submit with the same client key opens one ticket', function () {
    $f = $this->f;
    tkOpen($f->custA, ['client_key' => 'abc-123']);
    tkOpen($f->custA, ['client_key' => 'abc-123']);

    expect(Ticket::query()->count())->toBe(1);
});

it('lets a customer link only their own orders: a foreign or unknown order id is refused and writes nothing', function () {
    $f = $this->f;
    asCustomer($f->custA);
    $before = tkState();

    foreach ([$f->orderB->id, 99999] as $orderId) {
        $this->post('/portal/tickets', ['type' => 'issue', 'subject' => 'x', 'body' => 'y', 'order_id' => $orderId])
            ->assertSessionHasErrors('order_id');
    }
    $this->post('/portal/tickets', ['type' => 'bogus', 'subject' => 'x', 'body' => 'y'])->assertSessionHasErrors('type');

    expect(tkState())->toEqual($before);
});

it('keeps customer A out of customer B\'s tickets on every customer route, without effect', function () {
    $f = $this->f;
    $b = tkOpen($f->custB, ['order_id' => $f->orderB->id], [tkPdf('b.pdf')]);
    asCustomer($f->custA);
    $before = tkState();

    $this->get("/portal/tickets/{$b->id}")->assertNotFound();
    $this->post("/portal/tickets/{$b->id}/replies", ['body' => 'محاولة'])->assertNotFound();
    $this->post("/portal/tickets/{$b->id}/close")->assertNotFound();
    $this->get('/portal/tickets/abc')->assertNotFound();
    $this->get('/portal/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('portal/tickets')->has('tickets', 0));
    // Opening the ticket form with B's order id presets nothing.
    $this->get("/portal/tickets/new?order={$f->orderB->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order_id', null)->has('orders', 1));

    $file = $b->messages->first()->attachments->first();
    $this->get(PortalFiles::url($file, $f->custA))->assertNotFound();
    expect(tkState())->toEqual($before);
});

it('never shows an internal note, its files or its author to the customer: props, notifications and downloads', function () {
    $f = $this->f;
    $t = tkOpen($f->custA, ['order_id' => $f->orderA->id]);
    asStaff($f->owner);
    $this->post("/tickets/{$t->id}/take")->assertRedirect();
    $this->post("/tickets/{$t->id}/replies", ['visibility' => 'internal', 'body' => 'SECRET-NOTE-الخصم ممنوع', 'files' => [tkPdf('internal.pdf', 'internal')]])->assertRedirect();
    $this->post("/tickets/{$t->id}/replies", ['visibility' => 'public', 'body' => 'نعمل على حل المشكلة', 'files' => [tkPdf('public.pdf', 'public')]])->assertRedirect();

    asCustomer($f->custA);
    $page = $this->get("/portal/tickets/{$t->id}")->assertOk();
    $json = json_encode($page->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
    expect($json)->toContain('نعمل على حل المشكلة')->toContain('public.pdf')
        ->not->toContain('SECRET-NOTE')->not->toContain('internal.pdf')->not->toContain($f->owner->name);
    expect(json_encode($page->viewData('page')['props']['ticket']))->not->toContain('assignee');
    $page->assertInertia(fn (Assert $p) => $p->has('ticket.messages', 2)); // the opening message and the public reply

    // Notifications to the customer carry no text of any message, only that there is an update.
    $mine = OutboxMessage::query()->where('recipient', $f->custA->mobile)->orWhere('recipient', $f->custA->email)->get();
    expect($mine)->not->toBeEmpty();
    foreach ($mine as $message) {
        $text = json_encode($message->payload, JSON_UNESCAPED_UNICODE);
        expect($text)->not->toContain('SECRET-NOTE')->not->toContain('نعمل على حل المشكلة')->toContain($t->number);
    }

    // Downloads: the public file opens for its customer, the internal one is 404.
    $internal = TicketMessage::query()->where('visibility', 'internal')->firstOrFail()->attachments->firstOrFail();
    $public = TicketMessage::query()->where('ticket_id', $t->id)->where('visibility', 'public')->where('author_kind', 'staff')->firstOrFail()->attachments->firstOrFail();
    $this->get(PortalFiles::url($internal, $f->custA))->assertNotFound();
    $this->get(PortalFiles::url($public, $f->custA))->assertOk();
});

it('scopes the staff inbox and page by the P-59 grant: branch, assignment and role', function () {
    $f = $this->f;
    $inA = tkOpen($f->custA, ['order_id' => $f->orderA->id]);
    $inB = tkOpen($f->custC);

    // No grant at all (executor): no inbox, no page.
    asStaff($f->executor);
    $this->get('/tickets')->assertForbidden();
    $this->get("/tickets/{$inA->id}")->assertForbidden();

    foreach ([[$f->owner, [$inA->id, $inB->id]], [$f->managerA, [$inA->id]], [$f->managerB, [$inB->id]]] as [$user, $visible]) {
        asStaff($user);
        $this->get('/tickets')->assertOk()->assertInertia(fn (Assert $p) => $p->component('tickets/index')
            ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($visible)->sort()->values()->all()));
        foreach ([$inA, $inB] as $ticket) {
            $status = in_array($ticket->id, $visible, true) ? 200 : 403;
            $this->get("/tickets/{$ticket->id}")->assertStatus($status);
        }
    }

    // A foreign-branch staff cannot act either, and nothing changes.
    asStaff($f->managerB);
    $before = tkState();
    $this->post("/tickets/{$inA->id}/take")->assertForbidden();
    $this->post("/tickets/{$inA->id}/replies", ['visibility' => 'internal', 'body' => 'x'])->assertForbidden();
    $this->post("/tickets/{$inA->id}/assign", ['assignee_id' => $f->managerB->id])->assertForbidden();
    expect(tkState())->toEqual($before);
});

it('walks the whole M-21 flow, logging every transition and audit row, and notifies the customer through the outbox', function () {
    $f = $this->f;
    $t = tkOpen($f->custA, ['order_id' => $f->orderA->id]);

    asStaff($f->managerA);
    $this->post("/tickets/{$t->id}/take")->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::InProgress)->and($t->assignee_id)->toBe($f->managerA->id);
    $this->post("/tickets/{$t->id}/request-info", ['body' => 'أرسل صورة للباب'])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::AwaitingCustomer);

    asCustomer($f->custA);
    $this->post("/portal/tickets/{$t->id}/replies", ['body' => 'هذه الصورة', 'files' => [tkPdf('door.pdf')]])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::InProgress);

    asStaff($f->managerA);
    $this->post("/tickets/{$t->id}/resolve", ['body' => 'تم تعديل المفصلات'])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::Resolved)->and($t->resolution)->toBe('تم تعديل المفصلات')->and($t->resolved_at)->not->toBeNull();

    // The customer's reply to a resolved ticket reopens that SAME ticket, and a new ticket is not created.
    asCustomer($f->custA);
    $this->post("/portal/tickets/{$t->id}/replies", ['body' => 'ما زال يتحرك'])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::InProgress)->and($t->resolved_at)->toBeNull()->and(Ticket::query()->count())->toBe(1);

    asStaff($f->managerA);
    $this->post("/tickets/{$t->id}/resolve", ['body' => 'تم الإصلاح'])->assertRedirect();
    asCustomer($f->custA);
    $this->post("/portal/tickets/{$t->id}/close")->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::Closed)->and($t->closed_at)->not->toBeNull();
    // A reply after closing reopens it as well.
    $this->post("/portal/tickets/{$t->id}/replies", ['body' => 'عاد العطل'])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::InProgress);
    // Staff reopen needs a reason and works from resolved/closed.
    asStaff($f->managerA);
    $this->post("/tickets/{$t->id}/resolve", ['body' => 'أُصلح'])->assertRedirect();
    $this->post("/tickets/{$t->id}/close")->assertRedirect();
    $this->post("/tickets/{$t->id}/reopen", ['body' => ''])->assertSessionHasErrors('body');
    $this->post("/tickets/{$t->id}/reopen", ['body' => 'المشكلة مستمرة حسب الزيارة'])->assertRedirect();
    expect($t->refresh()->status)->toBe(TicketStatus::InProgress);

    $codes = StateTransition::query()->where('machine', 'M-21')->orderBy('id')->pluck('transition')->all();
    expect($codes)->toBe(['T00', 'T01', 'T02', 'T03', 'T04', 'T06', 'T04', 'T05', 'T06', 'T04', 'T05', 'T06']);
    expect(DB::table('audit_events')->where('action', 'ticket.status_changed')->count())->toBe(11);

    // One SMS + one mail per customer-facing event of staff, none for the customer's own steps.
    $sms = OutboxMessage::query()->where('recipient', $f->custA->mobile)->where('idempotency_key', 'like', 'ticket:%')->count();
    expect($sms)->toBeGreaterThanOrEqual(5);
});

it('refuses transitions that are not defined from the current status, with no side effects', function () {
    $f = $this->f;
    $t = tkOpen($f->custA);
    asStaff($f->owner);
    $before = tkState();

    // From «new»: only take. Resolve, request-info, close, reopen and a public reply are all refused.
    foreach (['resolve' => ['body' => 'x'], 'request-info' => ['body' => 'x'], 'close' => [], 'reopen' => ['body' => 'x'], 'replies' => ['visibility' => 'public', 'body' => 'x']] as $path => $data) {
        $this->postJson("/tickets/{$t->id}/{$path}", $data)->assertStatus(409);
    }
    expect(tkState())->toEqual($before)->and($t->refresh()->status)->toBe(TicketStatus::New);

    // Customer: cannot close an unresolved ticket.
    asCustomer($f->custA);
    $this->postJson("/portal/tickets/{$t->id}/close")->assertStatus(409);
    expect(tkState())->toEqual($before);

    // After take: take again is refused; close (not resolved) refused; internal notes are always possible.
    asStaff($f->owner);
    $this->post("/tickets/{$t->id}/take")->assertRedirect();
    $mid = tkState();
    $this->postJson("/tickets/{$t->id}/take")->assertStatus(409);
    $this->postJson("/tickets/{$t->id}/close")->assertStatus(409);
    $this->postJson("/tickets/{$t->id}/reopen", ['body' => 'x'])->assertStatus(409);
    expect(tkState())->toEqual($mid);
    $this->postJson("/tickets/{$t->id}/replies", ['visibility' => 'internal', 'body' => 'ملاحظة'])->assertRedirect();
});

it('assigns only an active member who holds P-59 over the ticket', function () {
    $f = $this->f;
    $t = tkOpen($f->custA, ['order_id' => $f->orderA->id]);
    asStaff($f->owner);
    $before = tkState();

    // The executor has no P-59 and the manager of another branch has no scope over this ticket.
    $this->postJson("/tickets/{$t->id}/assign", ['assignee_id' => $f->executor->id])->assertStatus(422);
    $this->postJson("/tickets/{$t->id}/assign", ['assignee_id' => $f->managerB->id])->assertStatus(422);
    $this->postJson("/tickets/{$t->id}/assign", ['assignee_id' => 999999])->assertStatus(422);
    expect(tkState())->toEqual($before);

    $this->post("/tickets/{$t->id}/assign", ['assignee_id' => $f->managerA->id])->assertRedirect();
    expect($t->refresh()->assignee_id)->toBe($f->managerA->id)
        ->and(UserNotification::query()->where('user_id', $f->managerA->id)->where('type', 'ticket.assigned')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('action', 'ticket.assigned')->count())->toBeGreaterThanOrEqual(1);
});

it('authorizes staff downloads on the ticket: in scope opens, another branch or role gets 404', function () {
    $f = $this->f;
    $t = tkOpen($f->custA, ['order_id' => $f->orderA->id], [tkPdf('doc.pdf')]);
    $file = $t->messages->first()->attachments->first();
    $url = fn ($user) => app(AttachmentService::class)->downloadUrl($file, $user);

    asStaff($f->managerA);
    $this->get($url($f->managerA))->assertOk();
    asStaff($f->managerB);
    $this->get($url($f->managerB))->assertNotFound(); // existence is not revealed
    asStaff($f->executor);
    $this->get($url($f->executor))->assertNotFound();
});

it('rejects an unsafe upload on a ticket without creating anything', function () {
    $f = $this->f;
    asCustomer($f->custA);
    $before = tkState();
    $bad = UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;');

    $this->post('/portal/tickets', ['type' => 'issue', 'subject' => 'x', 'body' => 'y', 'files' => [$bad]])->assertSessionHasErrors('files.0');
    expect(tkState())->toEqual($before);
});

it('answers a customer-signed-out request with a redirect to sign in, never a ticket', function () {
    Auth::forgetGuards();
    $this->get('/portal/tickets')->assertRedirect();
    $this->post('/portal/tickets', ['type' => 'issue', 'subject' => 'x', 'body' => 'y'])->assertRedirect();
    expect(Ticket::query()->count())->toBe(0);
});

it('moves a linked ticket with its order to the new branch scope', function () {
    $f = $this->f;
    $t = tkOpen($f->custA, ['order_id' => $f->orderA->id]);

    app(TransferOrderBranch::class)->handle($f->owner, $f->orderA->refresh(), $f->orderA->lock_version, $f->org->b->id, 'نقل الطلب', $f->managerB->id);

    expect($t->refresh()->branch_id)->toBe($f->org->b->id);
    asStaff($f->managerB);
    $this->get("/tickets/{$t->id}")->assertOk();
    asStaff($f->managerA);
    $this->get("/tickets/{$t->id}")->assertForbidden();
});
