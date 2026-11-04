<?php

/**
 * Field sales (ADR-0041): the rep role's least privilege, the supervisor's dispatch (branch scoped, with a response
 * time and a notification), the rep's own leads and visits in the field app, sales channel attribution and its audited
 * correction, the commission's sale owner and the settings as a configuration-release section.
 */

use App\Access\Access;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Access\PermissionCatalogue;
use App\Actions\FieldSales\UpdateFieldSalesSettings;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Configuration\ConfigDiff;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Enums\AppointmentStatus;
use App\Enums\SalesChannel;
use App\FieldSales\FieldAssignmentSla;
use App\FieldSales\FieldDispatch;
use App\FieldSales\FieldSalesConfig;
use App\FieldSales\SalesVisits;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DocumentSignLink;
use App\Models\AuditEvent;
use App\Models\Automation\AutomationEvent;
use App\Models\Customer;
use App\Models\FieldSales\FieldAssignment;
use App\Models\FieldSales\SalesVisit;
use App\Models\FieldSales\SalesVisitPhoto;
use App\Models\Order;
use App\Models\OrderMeasurement;
use App\Models\RoleTemplate;
use App\Models\UserNotification;
use App\Orders\OrderOpener;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/FieldSalesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake();
});

describe('the field sales rep role', function () {
    it('is a default template with least-privilege grants: own leads, visits, customers and orders, no finance admin', function () {
        $f = fieldSalesFixture(enabled: false);
        $template = RoleTemplate::query()->where('key', 'field_sales')->firstOrFail();
        $grants = $template->grants()->pluck('scope', 'permission')->map(fn ($s) => $s instanceof Scope ? $s->value : $s)->all();

        expect($template->name_ar)->toBe('بائع ميداني')
            ->and($grants)->toMatchArray([
                'P-09' => 'assigned', 'P-10' => 'assigned', 'P-14' => 'assigned', 'P-15' => 'assigned', 'P-16' => 'assigned',
                'P-17' => 'assigned', 'P-24' => 'assigned', 'P-29' => 'assigned', 'P-30' => 'assigned', 'P-32' => 'assigned',
                'P-37' => 'assigned', 'P-54' => 'assigned', 'P-94' => 'own',
            ])
            ->and(array_intersect(array_keys($grants), ['P-38', 'P-39', 'P-40', 'P-41', 'P-43', 'P-60', 'P-62', 'P-95', 'P-96', 'P-97', 'P-98', 'P-104', 'P-105', 'P-106']))->toBe([])
            ->and(PermissionCatalogue::definition(Permission::P105)['roles'])->toBe(['owner', 'manager', 'supervisor']);

        // Editable on the roles screens like any template (owner P-04).
        $this->actingAs($f->owner)->get("/org/roles/{$template->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('org/roles')->where('canEdit', true));
    });

    it('sees only the leads assigned to them, never the rest of the branch', function () {
        $f = fieldSalesFixture();
        $mine = fieldLead($f->owner, $f->org->a, 'سالم أحمد');
        $other = fieldLead($f->owner, $f->org->a, 'فهد محمد');
        app(FieldDispatch::class)->assignLead($f->supervisor, $mine, $f->rep->id);

        $this->actingAs($f->rep)->get('/interests')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('interests', 1)->where('interests.0.id', $mine->id));
        $this->actingAs($f->rep)->get("/interests/{$other->id}")->assertForbidden();
        $this->actingAs($f->rep)->get("/field/leads/{$other->id}")->assertNotFound();
        $this->actingAs($f->rep)->get("/field/leads/{$mine->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('field/lead')->where('lead.id', $mine->id)->where('lead.can.convert', true));
        // Someone else's order in the same branch stays closed to the rep.
        $this->actingAs($f->rep)->get("/orders/{$f->order->id}")->assertForbidden();
        // And no customer or lead may be opened in a branch the rep does not belong to, even self-assigned.
        expect(fn () => app(T00CreateInterest::class)->handle($f->rep, [
            'name' => 'عمر علي', 'mobile' => '0551112222', 'need' => 'خزائن', 'channel' => 'phone', 'branch_id' => $f->org->b->id, 'assignee_id' => $f->rep->id,
        ]))->toThrow(AuthorizationException::class);
    });
});

describe('dispatch', function () {
    it('hands a lead to a rep of its branch with a response time, a notification and an audit entry', function () {
        $f = fieldSalesFixture();
        $lead = fieldLead($f->owner, $f->org->a);
        $this->travelTo(now()->setTime(10, 0));

        $this->actingAs($f->supervisor)->post("/field-sales/leads/{$lead->id}/assign", ['rep_id' => $f->rep->id, 'note' => 'العميل يفضّل المساء'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $assignment = FieldAssignment::query()->sole();
        expect($lead->refresh()->assignee_id)->toBe($f->rep->id)
            ->and($assignment->status)->toBe('open')
            ->and($assignment->due_at?->equalTo(now()->addHours(24)))->toBeTrue()
            ->and($assignment->note)->toBe('العميل يفضّل المساء')
            ->and(UserNotification::query()->where('user_id', $f->rep->id)->where('type', 'field_sales.lead_assigned')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'field_sales.lead_assigned')->count())->toBe(1);

        // Re-dispatch to another rep of A ends the first assignment as reassigned.
        $rep2 = $f->org->member('field_sales', [$f->org->a]);
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $rep2->id);
        expect(FieldAssignment::query()->where('status', 'reassigned')->count())->toBe(1)
            ->and(FieldDispatch::openFor(FieldAssignment::INTEREST, $lead->id)?->rep_id)->toBe($rep2->id);
    });

    it('respects branch scope: a rep must cover the branch and a supervisor only dispatches inside theirs', function () {
        $f = fieldSalesFixture();
        $leadA = fieldLead($f->owner, $f->org->a);
        $leadB = fieldLead($f->owner, $f->org->b);

        $this->actingAs($f->supervisor)->post("/field-sales/leads/{$leadA->id}/assign", ['rep_id' => $f->repB->id])->assertSessionHasErrors('rep_id');
        $this->actingAs($f->supervisor)->post("/field-sales/leads/{$leadB->id}/assign", ['rep_id' => $f->repB->id])->assertForbidden();
        $this->actingAs($f->rep)->post("/field-sales/leads/{$leadA->id}/assign", ['rep_id' => $f->rep->id])->assertForbidden();
        $this->actingAs($f->supervisorB)->post("/field-sales/leads/{$leadB->id}/assign", ['rep_id' => $f->repB->id])->assertSessionHasNoErrors();

        expect(FieldAssignment::query()->count())->toBe(1)
            ->and($leadA->refresh()->assignee_id)->not->toBe($f->repB->id);

        // The board lists only the supervisor's branch.
        $this->actingAs($f->supervisor)->get('/field-sales?show=all')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field-sales/index')->has('leads', 1)->where('leads.0.id', $leadA->id));
    });

    it('dispatches an appointment request of a kind reps handle, and refuses installation requests', function () {
        $f = fieldSalesFixture();
        $request = fieldRequest($f->org->a, 'visit');
        $installation = fieldRequest($f->org->a, 'installation');

        $this->actingAs($f->supervisor)->post("/field-sales/requests/{$request->id}/assign", ['rep_id' => $f->rep->id, 'lock_version' => $request->lock_version])
            ->assertSessionHasNoErrors();
        $request->refresh();
        expect($request->status->value)->toBe('assigned')
            ->and($request->assignee_id)->toBe($f->rep->id)
            ->and($request->due_at)->not->toBeNull()
            ->and(UserNotification::query()->where('user_id', $f->rep->id)->where('type', 'field_sales.request_assigned')->exists())->toBeTrue();

        $this->actingAs($f->supervisor)->post("/field-sales/requests/{$installation->id}/assign", ['rep_id' => $f->rep->id])->assertSessionHasErrors('request');

        // The rep sees it in the field app; closing it ends the rep's task.
        $this->actingAs($f->rep)->get('/field')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('work.requests', 1)->where('work.requests.0.id', $request->id));
        $request->forceFill(['status' => 'closed', 'close_reason' => 'unreachable', 'closed_at' => now()])->save();
        expect(FieldAssignment::query()->sole()->outcome)->toBe('closed');
    });

    it('needs field sales on in the published release, not just in the draft', function () {
        $f = fieldSalesFixture(enabled: false);
        fieldSalesOn($f->owner, publish: false);
        $lead = fieldLead($f->owner, $f->org->a);

        expect(FieldSalesConfig::enabled())->toBeFalse();
        $this->actingAs($f->supervisor)->get('/field-sales')->assertForbidden();
        expect(fn () => app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id))->toThrow(ValidationException::class);

        publishRules($f->owner);
        ConfigReleases::forget();
        expect(FieldSalesConfig::current())->toBe(['enabled' => true, 'kinds' => ['visit', 'measurement'], 'response_hours' => 24, 'max_discount_bp' => 0]);
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id);
        expect(FieldAssignment::query()->count())->toBe(1);
    });

    it('alerts the rep and their supervisor once when a lead passes its response time', function () {
        $f = fieldSalesFixture();
        $lead = fieldLead($f->owner, $f->org->a);
        $this->travelTo(now()->next('Sunday')->setTime(9, 0));
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id);

        expect(app(FieldAssignmentSla::class)->run(now()))->toBe(0);
        $this->travel(25)->hours();
        expect(app(FieldAssignmentSla::class)->run(now()))->toBe(2)
            ->and(app(FieldAssignmentSla::class)->run(now()))->toBe(0)
            ->and(UserNotification::query()->where('type', 'field_sales.lead_overdue')->pluck('user_id')->all())->toBe([$f->rep->id])
            ->and(UserNotification::query()->where('type', 'field_sales.team_overdue')->pluck('user_id')->all())->toBe([$f->supervisor->id]);
    });
});

describe('sales channel attribution', function () {
    it('marks a converted dispatched lead as field sales sold by the rep, and closes the rep\'s task', function () {
        $f = fieldSalesFixture();
        $lead = fieldLead($f->owner, $f->org->a);
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id);

        $this->actingAs($f->rep)->post("/field/leads/{$lead->id}/follow")->assertSessionHasNoErrors();
        $this->actingAs($f->rep)->post("/field/leads/{$lead->id}/convert")->assertRedirect("/field/leads/{$lead->id}");

        $order = Order::query()->findOrFail($lead->refresh()->converted_order_id);
        expect($order->sales_channel)->toBe(SalesChannel::FieldSales)
            ->and($order->seller_id)->toBe($f->rep->id)
            ->and($order->assignee_id)->toBe($f->rep->id)
            ->and(FieldAssignment::query()->sole()->outcome)->toBe('converted');
        $this->actingAs($f->rep)->get("/orders/{$order->id}")->assertOk();
    });

    it('attributes the showroom by default, the rep\'s own orders to field sales and store orders to the online store', function () {
        $f = fieldSalesFixture();
        expect($f->order->sales_channel)->toBe(SalesChannel::Showroom)->and($f->order->seller_id)->toBeNull();

        $customer = Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]);
        $own = openCustomOrder($f->rep, $customer);
        expect($own->sales_channel)->toBe(SalesChannel::FieldSales)->and($own->seller_id)->toBe($f->rep->id);

        $store = DB::transaction(fn () => app(OrderOpener::class)->openFromStore($f->inA, $f->org->a->id, null,
            [['type' => 'ready_variant', 'variant_id' => $f->variant->id, 'quantity' => '1']], 'store'));
        expect($store->sales_channel)->toBe(SalesChannel::OnlineStore);
    });

    it('keeps the showroom while field sales is off, even for a rep', function () {
        $f = fieldSalesFixture(enabled: false);
        $customer = Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]);
        $order = openCustomOrder($f->rep, $customer);

        expect($order->sales_channel)->toBe(SalesChannel::Showroom)->and($order->seller_id)->toBeNull();
    });

    it('lets P-106 correct the channel and seller, audited; a rep cannot and the seller must cover the branch', function () {
        $f = fieldSalesFixture();
        $order = $f->order->refresh();

        $this->actingAs($f->rep)->post("/orders/{$order->id}/sales-channel", ['lock_version' => $order->lock_version, 'sales_channel' => 'field_sales', 'seller_id' => $f->rep->id])
            ->assertForbidden();
        $this->actingAs($f->manager)->post("/orders/{$order->id}/sales-channel", ['lock_version' => $order->lock_version, 'sales_channel' => 'field_sales', 'seller_id' => $f->repB->id])
            ->assertSessionHasErrors('seller_id');
        $this->actingAs($f->manager)->post("/orders/{$order->id}/sales-channel", ['lock_version' => $order->lock_version, 'sales_channel' => 'field_sales', 'seller_id' => $f->rep->id])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $audit = AuditEvent::query()->where('action', 'order.sales_channel_changed')->sole();
        expect($order->sales_channel)->toBe(SalesChannel::FieldSales)
            ->and($order->seller_id)->toBe($f->rep->id)
            ->and($audit->before)->toMatchArray(['sales_channel' => 'showroom', 'seller_id' => null])
            ->and($audit->after)->toMatchArray(['sales_channel' => 'field_sales', 'seller_id' => $f->rep->id]);

        $this->actingAs($f->manager)->get("/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('salesChannel.channel', 'field_sales')->where('salesChannel.seller', $f->rep->name)->where('salesChannel.can_change', true));
    });

    it('pins the field seller as the commission\'s sale owner through the existing rates', function () {
        $f = fieldSalesFixture();
        comPolicy($f->owner, 400, roles: [['role_template_id' => Org::templateId('field_sales'), 'rate_bp' => 700]]);
        $customer = Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]);
        $order = openCustomOrder($f->rep, $customer);

        $quote = agrSend($f->rep, agrQuote($f->rep, $order));
        agrApprove($f->owner, $quote);

        $sale = comSale($order);
        expect($sale?->user_id)->toBe($f->rep->id)
            ->and($sale?->owner_source)->toBe('seller')
            ->and($sale?->rate_bp)->toBe(700);
    });
});

describe('the field app for reps', function () {
    beforeEach(function () {
        $this->f = fieldSalesFixture();
        $customer = Customer::factory()->create(['branch_id' => $this->f->org->a->id, 'assignee_id' => $this->f->rep->id]);
        $this->order = openCustomOrder($this->f->rep, $customer);
        $this->visit = fieldVisit($this->f->owner, $this->order, [$this->f->rep]);
        $this->base = "/field/visits/{$this->visit->id}";
    });

    it('lists the rep\'s visits with their kind, and opens the sales visit screen', function () {
        $this->actingAs($this->f->rep)->get('/field')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field/index')->has('visits', 1)->where('visits.0.kind', 'visit')->where('visits.0.status', 'not_started')
            ->has('work.leads', 0));
        $this->actingAs($this->f->rep)->get($this->base)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field/sales-visit')->where('visit.can.start', true)->where('visit.order.channel', 'field_sales'));
        // Another rep is not booked on it.
        $other = $this->f->org->member('field_sales', [$this->f->org->a]);
        $this->actingAs($other)->get($this->base)->assertForbidden();
    });

    it('records arrival, notes, a retry-safe photo and a measurement, then finishes the appointment', function () {
        $rep = $this->f->rep;
        $this->actingAs($rep)->post("{$this->base}/sales/note", ['note' => 'قبل البدء'])->assertSessionHasErrors();
        $this->actingAs($rep)->post("{$this->base}/sales/start")->assertRedirect($this->base);
        $this->actingAs($rep)->post("{$this->base}/sales/note", ['note' => 'العميل يريد رخامًا أبيض'])->assertSessionHasNoErrors();

        $uuid = (string) Str::uuid();
        $photo = ['client_uuid' => $uuid, 'file' => UploadedFile::fake()->image('site.jpg', 800, 600)];
        $this->actingAs($rep)->post("{$this->base}/sales/photos", $photo, ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($rep)->post("{$this->base}/sales/photos", ['client_uuid' => $uuid, 'file' => UploadedFile::fake()->image('site.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($rep)->post("{$this->base}/sales/measurements", [
            'location' => 'المطبخ', 'name' => 'الجدار الشمالي', 'value' => '3.40', 'unit' => 'm', 'kind' => 'estimated', 'measured_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($rep)->post("{$this->base}/sales/finish", ['outcome' => 'اتفقنا على عرض سعر خلال يومين'])->assertRedirect('/field');

        $this->actingAs($rep)->get('/field')->assertInertia(fn (Assert $page) => $page->where('visits.0.status', 'finished'));
        $visit = SalesVisit::query()->sole();
        expect($visit->note)->toBe('العميل يريد رخامًا أبيض')
            ->and($visit->finished_at)->not->toBeNull()
            ->and(SalesVisitPhoto::query()->count())->toBe(1)
            ->and(OrderMeasurement::query()->where('order_id', $this->order->id)->count())->toBe(1)
            ->and($this->visit->refresh()->status)->toBe(AppointmentStatus::Done)
            ->and(AuditEvent::query()->whereIn('action', ['field_sales.visit_started', 'field_sales.visit_noted', 'field_sales.visit_photo_added', 'field_sales.visit_finished'])->count())->toBe(4);
    });

    it('prepares the quote and sends the customer the signing link from the visit', function () {
        $rep = $this->f->rep;
        $this->order->customer->forceFill(['portal_login_enabled' => true, 'email' => 'client@example.test'])->save();
        $this->actingAs($rep)->post("{$this->base}/sales/start");
        $this->actingAs($rep)->post("{$this->base}/sales/quote")->assertSessionHasNoErrors();

        $quote = AgreementDocument::query()->where('order_id', $this->order->id)->sole();
        app(UpdateDocumentDraft::class)->handle($rep, $quote, agrQuoteData(), $quote->lock_version, agrKey());
        $quote->refresh();
        $this->actingAs($rep)->post("{$this->base}/sales/documents/{$quote->id}/send", ['lock_version' => $quote->lock_version])->assertSessionHasNoErrors();
        $this->actingAs($rep)->post("{$this->base}/sales/documents/{$quote->id}/sign-link")->assertSessionHasNoErrors();

        expect($quote->refresh()->status->value)->toBe('awaiting_customer')
            ->and(DocumentSignLink::query()->where('agreement_document_id', $quote->id)->count())->toBe(1);
        // A document of another order is not reachable through this visit.
        $foreign = agrQuote($this->f->sales, $this->f->order);
        $this->actingAs($rep)->post("{$this->base}/sales/documents/{$foreign->id}/sign-link")->assertNotFound();
    });

    it('keeps installation visits for technicians and sales kinds out while field sales is off', function () {
        app(UpdateFieldSalesSettings::class)->handle($this->f->owner, ['enabled' => false, 'kinds' => ['visit'], 'response_hours' => null]);
        publishRules($this->f->owner);
        ConfigReleases::forget();

        $this->actingAs($this->f->rep)->get('/field')->assertInertia(fn (Assert $page) => $page->has('visits', 0)->where('work', null));
        $this->actingAs($this->f->rep)->get($this->base)->assertForbidden();
        expect(Access::can($this->f->rep, 'P-24'))->toBeTrue();
    });
});

describe('the settings section', function () {
    it('is a release section: draft, publish, diff and rollback', function () {
        $f = fieldSalesFixture(enabled: false);
        $this->actingAs($f->owner)->put('/settings/field-sales', ['enabled' => true, 'kinds' => [], 'response_hours' => 12])->assertSessionHasErrors('kinds');
        $this->actingAs($f->manager)->put('/settings/field-sales', ['enabled' => true, 'kinds' => ['visit'], 'response_hours' => 12])->assertForbidden();
        $this->actingAs($f->owner)->put('/settings/field-sales', ['enabled' => true, 'kinds' => ['measurement'], 'response_hours' => 12])->assertSessionHasNoErrors();
        $this->actingAs($f->manager)->get('/settings/field-sales')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('rules/field-sales')->where('settings.enabled', true)->where('canEdit', false));

        $first = publishRules($f->owner);
        ConfigReleases::forget();
        expect(FieldSalesConfig::kinds())->toBe(['measurement'])
            ->and($first->snapshot['field_sales'])->toBe(['enabled' => true, 'kinds' => ['measurement'], 'response_hours' => 12, 'max_discount_bp' => 0]);

        app(UpdateFieldSalesSettings::class)->handle($f->owner, ['enabled' => false, 'kinds' => ['measurement'], 'response_hours' => 12]);
        $diff = ConfigDiff::between($first->snapshot, ConfigSnapshot::capture());
        expect(collect($diff)->where('section', 'field_sales')->first()['fields'][0])->toMatchArray(['field' => 'enabled', 'before' => '✓', 'after' => '✗']);
    });
});

describe('automation triggers (ADR-0042)', function () {
    it('raises «lead dispatched to a field rep» and «field visit completed» for published rules', function () {
        require_once __DIR__.'/../Automation/AutomationTestHelpers.php';
        $f = fieldSalesFixture();
        autoRule($f->owner, 'rep_lead', 'lead.dispatched_to_rep', [noteAction()]);
        autoRule($f->owner, 'rep_visit', 'field_visit.completed', [noteAction()]);
        publishRules($f->owner);
        ConfigReleases::forget();

        $lead = fieldLead($f->owner, $f->org->a);
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id);
        $order = openCustomOrder($f->rep, Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]));
        $visits = app(SalesVisits::class);
        $visits->finish($f->rep, $visits->start($f->rep, fieldVisit($f->owner, $order, [$f->rep])), 'تم الاتفاق');

        $events = AutomationEvent::query()->whereIn('trigger', ['lead.dispatched_to_rep', 'field_visit.completed'])->get();
        expect($events->pluck('trigger')->sort()->values()->all())->toBe(['field_visit.completed', 'lead.dispatched_to_rep'])
            ->and($events->firstWhere('trigger', 'lead.dispatched_to_rep')->subject_id)->toBe($lead->id)
            ->and($events->firstWhere('trigger', 'field_visit.completed')->subject_id)->toBe($order->id);
    });
});
