<?php

/**
 * Customer appointment requests (ADR-0039): the customer only asks (public site or workspace); staff assign, call and
 * book through ApptSchedule, which links the appointment and marks the request scheduled — no customer approval step.
 */

use App\Actions\AppointmentRequests\AssignAppointmentRequest;
use App\Actions\AppointmentRequests\UpdateAppointmentRequestSettings;
use App\Actions\Site\SitePageWriter;
use App\AppointmentRequests\AppointmentRequestSla;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Interest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = portalFixture();
    $this->f->managerC = $this->f->org->member('manager', [$this->f->org->c]);
    $this->f->managerA = $this->f->org->member('manager', [$this->f->org->a]);
    config(['site.lead.min_seconds' => 3]);
});

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function apptPayload(array $override = []): array
{
    $submission = $override['submission'] ?? (string) Str::uuid();

    return array_merge([
        'name' => 'نورة', 'phone' => '0559876543', 'kind' => 'measurement',
        'preferred_from' => now()->addDays(3)->format('Y-m-d'), 'preferred_to' => now()->addDays(5)->format('Y-m-d'),
        'time_of_day' => 'morning', 'city' => 'الرياض', 'address' => 'حي الملقا', 'note' => 'مطبخ جديد',
        'submission' => $submission,
        'form_token' => Crypt::encryptString((time() - 30).'|'.$submission),
        'website' => '',
    ], $override);
}

function siteRequest(array $override = []): AppointmentRequest
{
    test()->post('/ar/appointment-request', apptPayload($override))->assertRedirect()->assertSessionHas('appointment_reference');

    return AppointmentRequest::query()->latest('id')->firstOrFail();
}

function apptSettings(User $owner, array $override = []): void
{
    $data = [...AppointmentRequestSettings::DEFAULTS, ...$override, 'fields' => [...AppointmentRequestSettings::DEFAULTS['fields'], ...($override['fields'] ?? [])]];
    app(UpdateAppointmentRequestSettings::class)->handle($owner, $data, null);
}

// --- Public site -------------------------------------------------------------------------------------------------

it('turns a public request into one new request with a new website interest, audited', function () {
    $request = siteRequest();

    expect($request->reference)->toBe(session('appointment_reference'))
        ->and($request->status->value)->toBe('new')
        ->and($request->source)->toBe('site')
        ->and($request->phone)->toBe('+966559876543')
        ->and($request->customer_id)->toBeNull()
        ->and($request->interest_id)->not->toBeNull()
        ->and($request->branch_id)->toBe($this->f->org->a->id);
    $interest = Interest::query()->findOrFail($request->interest_id);
    expect($interest->channel->value)->toBe('website')->and($interest->mobile)->toBe('+966559876543');
    expect(AuditEvent::query()->where('action', 'appointment_request.submitted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'interest.created')->count())->toBe(1);
});

it('is idempotent: the same submission key replays the first request', function () {
    $payload = apptPayload();
    $this->post('/en/appointment-request', $payload)->assertSessionHas('appointment_reference');
    $first = session('appointment_reference');
    $this->post('/en/appointment-request', $payload)->assertSessionHas('appointment_reference', $first);

    expect(AppointmentRequest::query()->count())->toBe(1)->and(Interest::query()->count())->toBe(1);
});

it('links an existing customer by mobile, or their open interest, instead of opening a new interest', function () {
    $customer = Customer::factory()->create(['branch_id' => $this->f->org->c->id, 'mobile' => '+966551112222']);
    $byCustomer = siteRequest(['phone' => '0551112222']);
    expect($byCustomer->customer_id)->toBe($customer->id)
        ->and($byCustomer->interest_id)->toBeNull()
        ->and($byCustomer->branch_id)->toBe($this->f->org->c->id);

    $first = siteRequest(['phone' => '0553334444']);
    $second = siteRequest(['phone' => '0553334444']);
    expect($second->interest_id)->toBe($first->interest_id)
        ->and(Interest::query()->where('mobile', '+966553334444')->count())->toBe(1);
});

it('silently drops bots (honeypot, too fast, forged token) without storing anything', function () {
    $fresh = (string) Str::uuid();
    $this->post('/en/appointment-request', apptPayload(['website' => 'http://spam.example']))->assertSessionHas('appointment_reference');
    $this->post('/en/appointment-request', apptPayload(['submission' => $fresh, 'form_token' => Crypt::encryptString(time().'|'.$fresh)]))->assertSessionHas('appointment_reference');
    $this->post('/en/appointment-request', apptPayload(['form_token' => 'forged']))->assertSessionHas('appointment_reference');

    expect(AppointmentRequest::query()->count())->toBe(0)->and(Interest::query()->count())->toBe(0);
});

it('throttles the public form per IP with its own budget', function () {
    config(['site.lead.per_ip_per_hour' => 2]);
    $this->post('/en/appointment-request', apptPayload())->assertRedirect();
    $this->post('/en/appointment-request', apptPayload())->assertRedirect();
    $this->post('/en/appointment-request', apptPayload())->assertStatus(429);

    expect(AppointmentRequest::query()->count())->toBe(2);
});

it('follows the settings: kinds, required and hidden fields, and the site switch', function () {
    apptSettings($this->f->owner, ['kinds' => ['visit'], 'fields' => ['note' => 'required', 'city' => 'off']]);

    $this->post('/en/appointment-request', apptPayload(['kind' => 'measurement']))->assertSessionHasErrors('kind');
    $this->post('/en/appointment-request', apptPayload(['kind' => 'visit', 'note' => '']))->assertSessionHasErrors('note');
    $request = siteRequest(['kind' => 'visit', 'city' => 'جدة']);
    expect($request->city)->toBeNull()->and($request->kind)->toBe('visit');

    apptSettings($this->f->owner, ['site_enabled' => false]);
    $this->post('/en/appointment-request', apptPayload())->assertSessionHasErrors('form');
});

it('refuses a preferred date in the past or a window longer than 30 days', function () {
    $this->post('/en/appointment-request', apptPayload(['preferred_from' => now()->subDay()->format('Y-m-d')]))->assertSessionHasErrors('preferred_from');
    $this->post('/en/appointment-request', apptPayload(['preferred_to' => now()->addDays(40)->format('Y-m-d')]))->assertSessionHasErrors('preferred_to');
});

// --- Customer workspace ------------------------------------------------------------------------------------------

it('lets a signed-in customer request an appointment on their own order, once per key', function () {
    actingAsCustomer($this->f->a);
    $key = (string) Str::uuid();
    $data = ['client_key' => $key, 'kind' => 'measurement', 'order_id' => $this->f->orderA->id, 'city' => 'الرياض'];

    $this->get('/portal/appointment-requests/new?order='.$this->f->orderA->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('portal/appointment-request')->where('order_id', $this->f->orderA->id)->where('enabled', true));
    $this->post('/portal/appointment-requests', $data)->assertRedirect('/portal/orders/'.$this->f->orderA->id);
    $this->post('/portal/appointment-requests', $data)->assertRedirect();

    $request = AppointmentRequest::query()->sole();
    expect($request->source)->toBe('portal')
        ->and($request->customer_id)->toBe($this->f->a->id)
        ->and($request->order_id)->toBe($this->f->orderA->id)
        ->and($request->branch_id)->toBe($this->f->orderA->branch_id)
        ->and($request->phone)->toBe($this->f->a->mobile);
});

it('never accepts another customer\'s order from the workspace, nor a signed-out visitor', function () {
    $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'measurement', 'city' => 'x'])
        ->assertRedirect('/portal/login');

    actingAsCustomer($this->f->a);
    $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'measurement', 'city' => 'x', 'order_id' => $this->f->orderB->id])
        ->assertNotFound();
    expect(AppointmentRequest::query()->count())->toBe(0);
});

it('caps the open requests a customer holds', function () {
    actingAsCustomer($this->f->a);
    foreach (range(1, 5) as $_) {
        $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'visit', 'city' => 'x'])->assertSessionHasNoErrors();
    }
    $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'visit', 'city' => 'x'])->assertSessionHasErrors('form');

    expect(AppointmentRequest::query()->count())->toBe(5);
});

// --- Staff queue -------------------------------------------------------------------------------------------------

it('scopes the queue by branch: other branches neither list nor open it', function () {
    $request = siteRequest(); // lands in branch A
    Auth::forgetGuards();

    $this->actingAs($this->f->managerA)->get('/appointment-requests')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('appointment-requests/index')->has('requests', 1)->where('counts.new', 1));
    $this->actingAs($this->f->managerC)->get('/appointment-requests')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requests', 0)->where('counts.new', 0));
    $this->actingAs($this->f->managerC)->get('/appointment-requests/'.$request->id)->assertNotFound();
    $this->actingAs($this->f->finance)->get('/appointment-requests')->assertForbidden();
});

it('assigns to a member covering the branch with a response time and a notification', function () {
    $request = siteRequest();
    Auth::forgetGuards();

    $this->actingAs($this->f->managerA)->post('/appointment-requests/'.$request->id.'/assign', ['assignee_id' => $this->f->managerC->id])
        ->assertSessionHasErrors('assignee_id');
    $this->actingAs($this->f->managerA)->post('/appointment-requests/'.$request->id.'/assign', ['assignee_id' => $this->f->sales->id])
        ->assertRedirect('/appointment-requests/'.$request->id);

    $request->refresh();
    expect($request->status->value)->toBe('assigned')
        ->and($request->assignee_id)->toBe($this->f->sales->id)
        ->and($request->due_at?->diffInHours(now(), true))->toBeGreaterThan(23.0)
        ->and(UserNotification::query()->where('user_id', $this->f->sales->id)->where('type', 'appointment_request.assigned')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'appointment_request.assigned')->count())->toBe(1);

    $this->actingAs($this->f->managerC)->post('/appointment-requests/'.$request->id.'/assign', ['assignee_id' => $this->f->managerC->id])->assertNotFound();
});

it('books through ApptSchedule: prefilled, confirmed without customer approval, linked and the customer messaged', function () {
    actingAsCustomer($this->f->a);
    $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'measurement', 'order_id' => $this->f->orderA->id, 'city' => 'الرياض', 'address' => 'حي الملقا', 'preferred_from' => now()->addDays(4)->format('Y-m-d'), 'time_of_day' => 'afternoon']);
    $request = AppointmentRequest::query()->sole();
    Auth::forgetGuards();

    $this->actingAs($this->f->sales)->get('/appointments/new?request='.$request->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('calendar/schedule')
            ->where('orderId', $this->f->orderA->id)
            ->where('fromRequest.id', $request->id)
            ->where('fromRequest.address', 'الرياض، حي الملقا')
            ->where('date', now()->addDays(4)->format('Y-m-d')));

    $day = now()->addDays(4)->format('Y-m-d');
    $this->actingAs($this->f->sales)->post('/appointments', [
        'order_id' => $this->f->orderA->id, 'kind' => 'measurement', 'starts_at' => $day.'T13:00', 'ends_at' => $day.'T14:00',
        'address' => 'الرياض، حي الملقا', 'member_ids' => [$this->f->tech->id], 'confirm_mode' => 'confirm', 'confirmation_method' => 'phone',
        'appointment_request_id' => $request->id,
    ])->assertRedirect();

    $request->refresh();
    $appointment = Appointment::query()->findOrFail($request->appointment_id);
    expect($request->status->value)->toBe('scheduled')
        ->and($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->order_id)->toBe($this->f->orderA->id)
        ->and(CustomerMessage::query()->where('event', 'measurement_booked')->where('customer_id', $this->f->a->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(AuditEvent::query()->where('action', 'appointment_request.scheduled')->count())->toBe(1);

    // A booked request cannot be booked again or closed.
    $this->actingAs($this->f->sales)->get('/appointments/new?request='.$request->id)->assertNotFound();
    $this->actingAs($this->f->sales)->post('/appointment-requests/'.$request->id.'/close', ['close_reason' => 'duplicate'])->assertSessionHasErrors('request');
});

it('refuses booking a request on another customer\'s order and leaves no appointment behind', function () {
    actingAsCustomer($this->f->a);
    $this->post('/portal/appointment-requests', ['client_key' => (string) Str::uuid(), 'kind' => 'measurement', 'city' => 'x']);
    $request = AppointmentRequest::query()->sole();
    Auth::forgetGuards();
    $before = Appointment::query()->count();
    $day = now()->addDays(4)->format('Y-m-d');

    $this->actingAs($this->f->sales)->post('/appointments', [
        'order_id' => $this->f->orderB->id, 'kind' => 'measurement', 'starts_at' => $day.'T13:00', 'ends_at' => $day.'T14:00',
        'address' => 'x', 'member_ids' => [$this->f->tech->id], 'confirm_mode' => 'confirm', 'confirmation_method' => 'phone',
        'appointment_request_id' => $request->id,
    ])->assertSessionHasErrors('order_id');

    expect(Appointment::query()->count())->toBe($before)->and($request->refresh()->status->value)->toBe('new');
});

it('closes an open request with a reason; «other» needs a note', function () {
    $request = siteRequest();
    Auth::forgetGuards();

    $this->actingAs($this->f->managerA)->post('/appointment-requests/'.$request->id.'/close', ['close_reason' => 'other'])->assertSessionHasErrors('close_note');
    $this->actingAs($this->f->managerA)->post('/appointment-requests/'.$request->id.'/close', ['close_reason' => 'unreachable'])->assertRedirect();

    expect($request->refresh()->status->value)->toBe('closed')
        ->and($request->close_reason?->value)->toBe('unreachable')
        ->and(AuditEvent::query()->where('action', 'appointment_request.closed')->count())->toBe(1);
});

it('alerts the assignee once when the response time passed', function () {
    $request = siteRequest();
    app(AssignAppointmentRequest::class)->handle($this->f->managerA, $request, $this->f->sales->id, null);
    $at = now()->setTimezone('Asia/Riyadh')->setTime(11, 0);
    $request->refresh()->forceFill(['due_at' => $at->copy()->subHour()])->save();

    $sla = app(AppointmentRequestSla::class);
    $sla->run($at);
    $sla->run($at);

    expect(UserNotification::query()->where('type', 'appointment_request.overdue')->where('user_id', $this->f->sales->id)->count())->toBe(1)
        ->and(UserNotification::query()->where('type', 'appointment_request.overdue')->where('user_id', $this->f->managerA->id)->count())->toBe(1);
});

// --- Settings ----------------------------------------------------------------------------------------------------

it('lets only the business-rules permission change the settings, audited', function () {
    $payload = [...AppointmentRequestSettings::DEFAULTS, 'kinds' => ['installation', 'measurement'], 'response_hours' => null];

    $this->actingAs($this->f->sales)->put('/settings/appointment-requests', $payload)->assertForbidden();
    $this->actingAs($this->f->owner)->get('/settings/appointment-requests')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rules/appointment-requests')->where('canEdit', true));
    $this->actingAs($this->f->owner)->put('/settings/appointment-requests', $payload)->assertRedirect();

    $settings = AppointmentRequestSettings::current();
    expect($settings->kinds)->toBe(['measurement', 'installation'])
        ->and($settings->response_hours)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'appointment_request.settings_updated')->count())->toBe(1);

    $this->actingAs($this->f->owner)->put('/settings/appointment-requests', [...$payload, 'fields' => ['note' => 'sometimes']])->assertSessionHasErrors();
});

it('renders the «request an appointment» section with the configured kinds, and hides it when switched off', function () {
    $writer = app(SitePageWriter::class);
    $page = $writer->create($this->f->owner, 'booking', ['ar' => 'موعد', 'en' => 'Booking']);
    $content = fn (string $title) => ['title' => $title, 'meta_description' => $title, 'sections' => [
        ['id' => 's1', 'type' => 'appointment', 'visible' => true, 'data' => ['heading' => $title, 'text' => '']],
    ]];
    $writer->saveDraft($this->f->owner, $page, ['ar' => $content('اطلب موعداً'), 'en' => $content('Request a visit')]);
    $writer->publish($this->f->owner, $page, ['ar', 'en']);
    apptSettings($this->f->owner, ['kinds' => ['visit'], 'fields' => ['address' => 'off']]);

    $this->get('/en/booking')->assertOk()
        ->assertSee('data-test="appointment-form"', false)
        ->assertSee('value="visit"', false)
        ->assertDontSee('value="measurement"', false)
        ->assertDontSee('name="address"', false);

    apptSettings($this->f->owner, ['site_enabled' => false]);
    $this->get('/en/booking')->assertOk()->assertDontSee('data-test="appointment-form"', false);
});
