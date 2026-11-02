<?php

/**
 * Installation field app (ADR-0036): the technician's own visits, arrival, before/after photos (mandatory, retry-safe),
 * remarks that block the order's closure until resolved, the customer's acceptance signature (on the phone or from the
 * workspace), the finish through the workflow engine and the «order completed» customer event, and authorization.
 */

use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Enums\ActionStatus;
use App\Enums\AppointmentStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Installation\InstallationStatus;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Installation\InstallationPhoto;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\OutboxMessage;
use App\Models\UserNotification;
use App\Orders\OrderClosure;
use App\Workflow\StageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/InstallationTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake();
    $this->f = workflowFixture();
    $this->owner = $this->f->org->owner;
    $this->tech = $this->f->org->member('executor', [$this->f->org->a]);
    $this->other = $this->f->org->member('executor', [$this->f->org->a]);
    $this->appointment = installationAppointment($this->f, [$this->tech], $this->tech);
    $this->base = '/field/visits/'.$this->appointment->id;
});

it('lists only the technician\'s own installation visits with what they need on site', function () {
    $this->actingAs($this->tech)->get('/field')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('field/index')
        ->has('visits', 1)
        ->where('visits.0.id', $this->appointment->id)
        ->where('visits.0.status', 'not_started')
        ->where('visits.0.customer.name', $this->f->order->customer->name)
        ->where('visits.0.customer.phone', $this->f->order->customer->mobile)
        ->where('visits.0.maps_url', 'https://www.google.com/maps/search/?api=1&query=24.81%2C46.61')
        ->has('visits.0.items')
        ->missing('visits.0.total_minor'));

    // Another technician of the same branch sees nothing, and cannot open the visit or act on it.
    $this->actingAs($this->other)->get('/field')->assertOk()->assertInertia(fn (Assert $page) => $page->has('visits', 0));
    $this->actingAs($this->other)->get($this->base)->assertForbidden();
    $this->actingAs($this->other)->post($this->base.'/arrive')->assertForbidden();
    // Neither can a manager with branch-wide scope act as the technician (the order page is their view).
    $this->actingAs($this->owner)->post($this->base.'/arrive')->assertForbidden();
    expect(InstallationVisit::query()->count())->toBe(0);
});

it('records the arrival once, with one optional location reading, and starts the installation task', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive', ['latitude' => 24.8112345, 'longitude' => 46.6123456, 'accuracy' => 12])
        ->assertRedirect($this->base);

    $visit = InstallationVisit::query()->sole();
    expect($visit->status)->toBe(InstallationStatus::InProgress)
        ->and($visit->arrived_by)->toBe($this->tech->id)
        ->and((float) $visit->arrival_latitude)->toBe(24.8112345)
        ->and($visit->arrival_accuracy_m)->toBe(12)
        ->and(openTaskStatus($this->f->order))->toBe(TaskStatus::InProgress)
        ->and(AuditEvent::query()->where('action', 'installation.arrived')->value('after'))->toMatchArray(['located' => true]);

    // Twice is refused; without consent the location is simply absent.
    $this->actingAs($this->tech)->postJson($this->base.'/arrive')->assertStatus(409)->assertJson(['condition' => 'already_arrived']);
});

it('refuses arrival on an appointment that is not confirmed', function () {
    $this->appointment->forceFill(['status' => AppointmentStatus::AwaitingConfirmation])->save();
    $this->actingAs($this->tech)->postJson($this->base.'/arrive')->assertStatus(409)->assertJson(['condition' => 'not_confirmed']);
});

it('stores before/after photos privately, idempotently on retry, and requires arrival first', function () {
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertStatus(409)->assertJson(['condition' => 'not_arrived']);
    $this->actingAs($this->tech)->post($this->base.'/arrive');

    $uuid = (string) Str::uuid();
    $first = $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before', $uuid))->assertCreated()->json('photo.id');
    // The device retries after a lost response: same id back, nothing added.
    $again = $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before', $uuid))->assertOk()->json('photo.id');
    expect($again)->toBe($first)->and(InstallationPhoto::query()->count())->toBe(1);

    $photo = InstallationPhoto::query()->with('attachment')->sole();
    expect($photo->attachment->attachable_type)->toBe((new InstallationVisit)->getMorphClass())
        ->and($photo->attachment->purpose)->toBe('installation_before');
    Storage::disk('attachments')->assertExists($photo->attachment->path);

    // A file that is not an allowed image is refused; another technician cannot add to this visit.
    $this->actingAs($this->tech)->postJson($this->base.'/photos', ['phase' => 'after', 'client_uuid' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('x.exe', 10)])->assertStatus(422);
    $this->actingAs($this->other)->postJson($this->base.'/photos', installPhotoForm('after'))->assertForbidden();

    // After the first «after» photo a «before» one would misorder the evidence.
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertStatus(409)->assertJson(['condition' => 'before_after_after']);
});

it('refuses to finish without a before photo, an after photo and the signature', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive');

    $this->actingAs($this->tech)->postJson($this->base.'/finish')->assertStatus(409)->assertJson(['condition' => 'photos_before_missing']);
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertCreated();
    $this->actingAs($this->tech)->postJson($this->base.'/finish')->assertStatus(409)->assertJson(['condition' => 'photos_after_missing']);
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->tech)->postJson($this->base.'/finish')->assertStatus(409)->assertJson(['condition' => 'signature_missing']);

    expect(InstallationVisit::query()->sole()->finished_at)->toBeNull()
        ->and($this->appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('takes the customer\'s signature on the phone with consent, stored privately and audited', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive');

    $this->actingAs($this->tech)->from($this->base)->post($this->base.'/signature', [...installSignatureForm(), 'consent' => false])->assertSessionHasErrors('consent');
    $this->actingAs($this->tech)->from($this->base)->post($this->base.'/signature', [...installSignatureForm(), 'signature' => 'data:image/png;base64,AAAA'])->assertSessionHasErrors('signature');
    $this->actingAs($this->tech)->from($this->base)->post($this->base.'/signature', installSignatureForm())->assertRedirect($this->base);

    $visit = InstallationVisit::query()->with('signature')->sole();
    expect($visit->signer_name)->toBe('أحمد العميل')
        ->and($visit->signature_method)->toBe('device')
        ->and($visit->signed_at)->not->toBeNull()
        ->and($visit->signature->purpose)->toBe('installation_signature')
        ->and($visit->signature_sha256)->toBe($visit->signature->sha256)
        ->and(AuditEvent::query()->where('action', 'installation.signed')->count())->toBe(1);

    // Signed once only.
    $this->actingAs($this->tech)->postJson($this->base.'/signature', installSignatureForm())->assertStatus(409)->assertJson(['condition' => 'already_signed']);
});

it('sends the customer a workspace link and accepts the signature there, only for their own order', function () {
    $customer = $this->f->order->customer;
    $this->actingAs($this->tech)->post($this->base.'/arrive');
    $visit = InstallationVisit::query()->sole();

    // Without workspace access there is nothing to sign in to.
    $this->actingAs($this->tech)->postJson($this->base.'/signature-link')->assertStatus(409)->assertJson(['condition' => 'portal_disabled']);
    $customer->forceFill(['portal_login_enabled' => true, 'email' => 'client@example.test'])->save();
    $this->actingAs($this->tech)->post($this->base.'/signature-link')->assertRedirect();
    expect($visit->refresh()->signature_requested_at)->not->toBeNull()
        ->and(OutboxMessage::query()->where('idempotency_key', 'like', 'installation-sign:'.$visit->id.':%')->exists())->toBeTrue();

    // Another customer gets 404; the order's customer signs from the workspace.
    $stranger = Customer::factory()->create(['branch_id' => $this->f->org->a->id, 'portal_login_enabled' => true]);
    require_once __DIR__.'/../Portal/PortalTestHelpers.php';
    auth()->guard('web')->logout();
    actingAsCustomer($stranger);
    $this->get('/portal/installations/'.$visit->id)->assertNotFound();
    $this->post('/portal/installations/'.$visit->id.'/sign', installSignatureForm())->assertNotFound();

    actingAsCustomer($customer);
    $this->get('/portal/installations/'.$visit->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/installation')->where('installation.signable', true));
    $this->post('/portal/installations/'.$visit->id.'/sign', installSignatureForm())->assertRedirect('/portal/installations/'.$visit->id);
    expect($visit->refresh()->signature_method)->toBe('portal')
        ->and(AuditEvent::query()->where('action', 'installation.signed')->value('source'))->toBe('customer');
});

it('records remarks on the delivery-remark model: each notifies the order owner and blocks closure until resolved on its own', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive');
    $this->actingAs($this->tech)->post($this->base.'/remarks', ['kind' => 'other', 'description' => 'لون المقبض مختلف قليلاً'])->assertRedirect();
    $this->actingAs($this->tech)->post($this->base.'/remarks', ['kind' => 'missing_part', 'description' => 'ينقص مفصلان للباب العلوي'])->assertRedirect();
    $this->actingAs($this->tech)->post($this->base.'/remarks', ['kind' => 'damage', 'description' => 'خدش في لوح الرخام'])->assertRedirect();

    $order = $this->f->order->refresh();
    $open = InstallationRemark::query()->where('status', 'open')->orderBy('id')->get();
    $blocker = fn () => collect(OrderClosure::blockers($order->refresh()))->firstWhere('key', 'installation_remark_open');
    expect($open)->toHaveCount(3)
        ->and($open[0]->follow_up_user_id)->toBe($order->assignee_id)
        ->and(UserNotification::query()->where('user_id', $order->assignee_id)->where('type', 'installation.remark_blocking')->count())->toBe(3)
        ->and($blocker()['details'])->toBe(['count' => 3])
        ->and(array_column($blocker()['items'], 'id'))->toBe($open->pluck('id')->all());

    // The read contract mirrors GET /orders/{order}/delivery-remarks (P-52 on the order).
    $this->actingAs($this->owner)->getJson('/orders/'.$order->id.'/installation-remarks')->assertOk()
        ->assertJsonCount(3, 'remarks')
        ->assertJsonStructure(['remarks' => [['id', 'installation_visit_id', 'kind', 'description', 'status', 'recorded_by', 'recorded_at', 'follow_up', 'resolution', 'resolved_by', 'resolved_at', 'lock_version']]]);
    $this->actingAs($this->other)->getJson('/orders/'.$order->id.'/installation-remarks')->assertForbidden();

    // The technician cannot resolve (no P-52 over this order); the owner resolves each remark on its own.
    $this->actingAs($this->tech)->post('/installation-remarks/'.$open[0]->id.'/resolve', ['resolution' => 'تمت المعالجة'])->assertForbidden();
    $this->actingAs($this->owner)->postJson('/installation-remarks/'.$open[0]->id.'/resolve', ['resolution' => 'وافق العميل على اللون'])->assertOk()
        ->assertJsonPath('remark.status', 'resolved')->assertJsonPath('remark.resolved_by', $this->owner->name);
    expect($blocker()['details'])->toBe(['count' => 2]);
    $this->actingAs($this->owner)->postJson('/installation-remarks/'.$open[0]->id.'/resolve', ['resolution' => 'مرة أخرى'])->assertStatus(409);
    $this->actingAs($this->owner)->post('/installation-remarks/'.$open[1]->id.'/resolve', ['resolution' => 'وصلت المفصلات وركبت'])->assertRedirect();
    $this->actingAs($this->owner)->post('/installation-remarks/'.$open[2]->id.'/resolve', ['resolution' => 'استبدل اللوح'])->assertRedirect();
    expect($blocker())->toBeNull();
});

it('finishes through the engine: visit done, task completed, action verified; the stage waits for its manager', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive');
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertCreated();
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->tech)->post($this->base.'/remarks', ['kind' => 'extra_work', 'description' => 'طلب العميل رفاً إضافياً']);
    $this->actingAs($this->tech)->post($this->base.'/signature', installSignatureForm());
    settleOtherInstallWork($this->f, $this->f->order);

    $this->actingAs($this->tech)->post($this->base.'/finish', ['note' => 'تم التركيب'])->assertRedirect('/field');

    $order = $this->f->order->refresh();
    $visit = InstallationVisit::query()->sole();
    $stage = stageOf($order, 'install');
    expect($visit->status)->toBe(InstallationStatus::FinishedWithRemarks)
        ->and($visit->durationMinutes())->toBeInt()
        ->and($this->appointment->refresh()->status)->toBe(AppointmentStatus::Done)
        ->and(openTaskStatus($order))->toBe(TaskStatus::Completed)
        ->and(statusOf(actionOf($order, 'installation')))->toBe(ActionStatus::Verified)
        // An executor holds no P-25: the stage stays for the manager (Q-30 a), nothing is bypassed.
        ->and($stage->status)->toBe(StageStatus::InProgress)
        ->and($order->status)->not->toBe(CustomOrderStatus::ExecutionFinished->value);

    // Finishing twice is refused; the manager completes the stage → order finished → the customer event.
    $this->actingAs($this->tech)->postJson($this->base.'/finish')->assertStatus(409)->assertJson(['condition' => 'already_finished']);
    app(T04CompleteStage::class)->handle($this->owner, $stage);
    expect($order->refresh()->status)->toBe(CustomOrderStatus::ExecutionFinished->value)
        ->and(CustomerMessage::query()->where('event', 'order_completed')->where('order_id', $order->id)->count())->toBe(1);
});

it('completes the last stage and raises «order completed» when the technician may complete the stage', function () {
    // The owner works the visit himself (holds P-24 and P-25 facility-wide).
    $appointment = installationAppointment($this->f, [$this->owner], $this->owner, '+2 days');
    $base = '/field/visits/'.$appointment->id;
    settleOtherInstallWork($this->f, $this->f->order);
    $this->actingAs($this->owner)->post($base.'/arrive');
    $this->actingAs($this->owner)->postJson($base.'/photos', installPhotoForm('before'))->assertCreated();
    $this->actingAs($this->owner)->postJson($base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->owner)->post($base.'/signature', installSignatureForm());

    $this->actingAs($this->owner)->post($base.'/finish')->assertRedirect('/field');

    $order = $this->f->order->refresh();
    expect(InstallationVisit::query()->where('appointment_id', $appointment->id)->sole()->status)->toBe(InstallationStatus::Finished)
        ->and(stageOf($order, 'install')->status)->toBe(StageStatus::Completed)
        ->and(app(StageEngine::class)->activeStage($order))->toBeNull()
        ->and($order->status)->toBe(CustomOrderStatus::ExecutionFinished->value)
        ->and(CustomerMessage::query()->where('event', 'order_completed')->where('order_id', $order->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'installation.finished')->count())->toBe(1);
});

it('shows managers the installation status on the order and in the calendar', function () {
    $this->actingAs($this->tech)->post($this->base.'/arrive');
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertCreated();

    $this->actingAs($this->owner)->get('/orders/'.$this->f->order->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('installation.visits.0.status', 'in_progress')
        ->where('installation.visits.0.photo_count', 1)
        ->has('installation.visits.0.photos', 1)
        ->where('installation.can_resolve', true));

    $date = $this->appointment->starts_at->setTimezone('Asia/Riyadh')->toDateString();
    $this->actingAs($this->owner)->get('/calendar?date='.$date)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('appointments', fn ($rows) => collect($rows)->firstWhere('id', $this->appointment->id)['installation'] === 'in_progress'));
});

it('lets a P-06 holder sign a member out of every device, rotating the remember-me token', function () {
    $membership = Org::membershipOf($this->tech);
    $token = $this->tech->refresh()->remember_token;
    $version = $this->tech->access_version;

    $this->actingAs($this->other)->post('/org/team/members/'.$membership->id.'/sessions/revoke')->assertForbidden();
    $this->actingAs($this->owner)->post('/org/team/members/'.$membership->id.'/sessions/revoke')->assertRedirect();

    $this->tech->refresh();
    expect($this->tech->remember_token)->not->toBe($token)
        ->and($this->tech->access_version)->toBe($version + 1)
        ->and(AuditEvent::query()->where('action', 'membership.sessions_revoked')->count())->toBe(1)
        ->and(config('auth.guards.web.remember'))->toBe(60 * 24 * 90);
});

it('serves the PWA manifest with OpsOne identity and the service worker scoped to the field app', function () {
    auth()->guard('web')->logout();
    $response = $this->get('/field/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
    $manifest = $response->json();
    expect($manifest['name'])->toContain('OpsOne')
        ->and($manifest['start_url'])->toBe('/field')
        ->and($manifest['scope'])->toBe('/field')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512')
        ->and(collect($manifest['icons'])->every(fn (array $i) => file_exists(public_path(ltrim($i['src'], '/')))))->toBeTrue()
        ->and(file_exists(public_path('field-sw.js')))->toBeTrue();

    $this->actingAs($this->tech)->get('/field')->assertOk()->assertSee('/field/manifest.webmanifest', false);
    $this->actingAs($this->tech)->get('/field/install')->assertOk()->assertInertia(fn (Assert $page) => $page->component('field/install'));
});

it('reports first-visit completion and average duration per team on the owner dashboard', function () {
    // Visit 1 (technician): finished with a blocking remark → not a first-visit completion; 120 minutes on site.
    $this->actingAs($this->tech)->post($this->base.'/arrive');
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('before'))->assertCreated();
    $this->travel(120)->minutes();
    $this->actingAs($this->tech)->postJson($this->base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->tech)->post($this->base.'/remarks', ['kind' => 'missing_part', 'description' => 'ينقص مقبض']);
    $this->actingAs($this->tech)->post($this->base.'/signature', installSignatureForm());
    $this->actingAs($this->tech)->post($this->base.'/finish')->assertRedirect('/field');

    $this->actingAs($this->owner)->get('/executive?period=last30')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.metrics', fn ($metrics) => collect($metrics)->firstWhere('key', 'dashboard.installation_first_visit_percent')['value'] == 0
            && collect($metrics)->firstWhere('key', 'dashboard.installation_avg_minutes')['value'] === 120)
        ->has('dashboard.installation_teams', 1)
        ->where('dashboard.installation_teams.0.visits', 1));

    // A return visit on the same order (its task is already done) finishes cleanly, but it is not the order's first
    // visit: still 0 %; the average covers both visits.
    $appointment = installationAppointment($this->f, [$this->owner], null, '+2 days');
    $base = '/field/visits/'.$appointment->id;
    $this->actingAs($this->owner)->post($base.'/arrive');
    $this->actingAs($this->owner)->postJson($base.'/photos', installPhotoForm('before'))->assertCreated();
    $this->travel(60)->minutes();
    $this->actingAs($this->owner)->postJson($base.'/photos', installPhotoForm('after'))->assertCreated();
    $this->actingAs($this->owner)->post($base.'/signature', installSignatureForm());
    $this->actingAs($this->owner)->post($base.'/finish')->assertRedirect('/field');

    $this->actingAs($this->owner)->get('/executive?period=last30')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.metrics', fn ($metrics) => collect($metrics)->firstWhere('key', 'dashboard.installation_first_visit_percent')['value'] == 0
            && collect($metrics)->firstWhere('key', 'dashboard.installation_avg_minutes')['value'] === 90));

    // An executor without reports sees no dashboard at all.
    $this->actingAs($this->tech)->get('/executive')->assertForbidden();
});
