<?php

/**
 * The supervisor's field team (ADR-0041): controlling a rep's permissions and discount inside the owner's ceiling and
 * the supervisor's own grants, scoped to the supervisor's branches or region, audited; the team follow-up through to
 * installation; and the supervisor's notices when a rep's lead or task is late.
 */

use App\Access\Access;
use App\Actions\FieldSales\UpdateFieldSalesSettings;
use App\Agreements\DiscountLimits;
use App\Configuration\ConfigReleases;
use App\Enums\MemberGrantEffect;
use App\FieldSales\FieldAssignmentSla;
use App\FieldSales\FieldDispatch;
use App\FieldSales\FieldRepAccess;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\MemberGrant;
use App\Models\RoleTemplate;
use App\Models\Task;
use App\Models\UserNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/FieldSalesTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    Queue::fake();
    $this->f = fieldSalesFixture();
});

describe('rep permissions', function () {
    it('lets the supervisor turn a rep permission off, audited, and the rep loses it at once', function () {
        $f = $this->f;
        $order = openCustomOrder($f->rep, Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]));
        expect(Access::can($f->rep, 'P-29', $order))->toBeTrue();

        $this->actingAs($f->supervisor)->post("/field-sales/team/{$f->rep->id}/permissions", ['permission' => 'P-29', 'enabled' => false])
            ->assertSessionHasNoErrors();

        $deny = MemberGrant::query()->where('membership_id', Org::membershipOf($f->rep)->id)->sole();
        $audit = AuditEvent::query()->where('action', 'field_sales.rep_permission_changed')->sole();
        expect($deny->effect)->toBe(MemberGrantEffect::Deny)
            ->and($deny->granted_by_membership_id)->toBe(Org::membershipOf($f->supervisor)->id)
            ->and($audit->before)->toMatchArray(['permission' => 'P-29', 'enabled' => true])
            ->and($audit->after)->toMatchArray(['permission' => 'P-29', 'enabled' => false])
            ->and(Access::can($f->rep, 'P-29', $order))->toBeFalse();
        $this->actingAs($f->rep)->post('/field/visits/0/sales/quote')->assertNotFound();
    });

    it('never goes beyond the supervisor\'s own grants: turning on what they do not hold is refused', function () {
        $f = $this->f;
        app(FieldRepAccess::class)->set($f->manager, $f->rep, 'P-29', false);

        // The default supervisor template does not hold P-29: it may restrict, not re-grant.
        $this->actingAs($f->supervisor)->post("/field-sales/team/{$f->rep->id}/permissions", ['permission' => 'P-29', 'enabled' => true])
            ->assertForbidden();
        expect(MemberGrant::query()->count())->toBe(1);

        // A manager of the branch holds it and may turn it back on.
        app(FieldRepAccess::class)->set($f->manager, $f->rep, 'P-29', true);
        expect(MemberGrant::query()->count())->toBe(0);
    });

    it('never goes beyond the owner\'s ceiling: a permission removed from the rep template cannot be controlled', function () {
        $f = $this->f;
        RoleTemplate::query()->where('key', 'field_sales')->firstOrFail()->grants()->where('permission', 'P-37')->delete();

        expect(fn () => app(FieldRepAccess::class)->set($f->owner, $f->rep, 'P-37', true))->toThrow(ValidationException::class);
        expect(fn () => app(FieldRepAccess::class)->set($f->owner, $f->rep, 'P-38', true))->toThrow(ValidationException::class);
        expect(collect(app(FieldRepAccess::class)->state($f->owner, $f->rep))->firstWhere('permission', 'P-37'))
            ->toMatchArray(['ceiling' => false, 'enabled' => false]);
    });

    it('caps the rep discount at the owner\'s ceiling and the supervisor\'s own limit', function () {
        $f = $this->f;
        app(UpdateFieldSalesSettings::class)->handle($f->owner, ['enabled' => true, 'kinds' => ['visit', 'measurement'], 'response_hours' => 24, 'max_discount_bp' => 800]);
        publishRules($f->owner);
        ConfigReleases::forget();

        // Above the owner's ceiling (8%).
        $this->actingAs($f->manager)->post("/field-sales/team/{$f->rep->id}/discount", ['limit_bp' => 900])->assertSessionHasErrors('limit_bp');
        // The supervisor's own limit is 0% (agreement fixture): nothing above it.
        $this->actingAs($f->supervisor)->post("/field-sales/team/{$f->rep->id}/discount", ['limit_bp' => 100])->assertSessionHasErrors('limit_bp');
        // Within both.
        $this->actingAs($f->manager)->post("/field-sales/team/{$f->rep->id}/discount", ['limit_bp' => 500])->assertSessionHasNoErrors();

        expect(DiscountLimits::of($f->rep, 'custom_bp'))->toBe(500)
            ->and(AuditEvent::query()->where('action', 'field_sales.rep_discount_changed')->sole()->after)->toBe(['custom_bp' => 500]);
    });

    it('refuses a supervisor outside the rep\'s branch or region', function () {
        $f = $this->f;
        $repC = $f->org->member('field_sales', [$f->org->c]);
        $regional = $f->org->member('supervisor', [$f->org->a], $f->org->d1, 'region');

        // Branch B's supervisor and the rep of A.
        $this->actingAs($f->supervisorB)->post("/field-sales/team/{$f->rep->id}/permissions", ['permission' => 'P-29', 'enabled' => false])->assertNotFound();
        $this->actingAs($f->supervisorB)->get("/field-sales/team/{$f->rep->id}")->assertNotFound();
        expect(fn () => app(FieldRepAccess::class)->set($f->supervisorB, $f->rep, 'P-29', false))->toThrow(AuthorizationException::class);

        // The north region's supervisor manages A and B, not C in the south.
        expect(FieldRepAccess::canManage($regional, $f->rep))->toBeTrue()
            ->and(FieldRepAccess::canManage($regional, $f->repB))->toBeTrue()
            ->and(FieldRepAccess::canManage($regional, $repC))->toBeFalse();
        expect(fn () => app(FieldRepAccess::class)->setDiscount($regional, $repC, 0))->toThrow(AuthorizationException::class);
        expect(MemberGrant::query()->count())->toBe(0);
    });
});

describe('team follow-up', function () {
    it('shows each supervisor only their own reps, with leads, visits, late work and conversions', function () {
        $f = $this->f;
        $lead = fieldLead($f->owner, $f->org->a);
        app(FieldDispatch::class)->assignLead($f->supervisor, $lead, $f->rep->id);

        $this->actingAs($f->supervisor)->get('/field-sales/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field-sales/team')->has('reps', 1)->where('reps.0.id', $f->rep->id)->where('reps.0.leads.new', 1));
        $this->actingAs($f->supervisorB)->get('/field-sales/team')->assertInertia(fn (Assert $page) => $page
            ->has('reps', 1)->where('reps.0.id', $f->repB->id));
        $this->actingAs($f->rep)->get('/field-sales/team')->assertForbidden();
    });

    it('follows the rep\'s orders to the end and links them, with amounts only for P-37', function () {
        $f = $this->f;
        $order = openCustomOrder($f->rep, Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]));
        agrApprove($f->owner, agrSend($f->rep, agrQuote($f->rep, $order)));
        fieldVisit($f->owner, $order, [$f->rep]);

        $this->actingAs($f->supervisor)->get("/field-sales/team/{$f->rep->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('field-sales/rep')
            ->has('orders', 1)->where('orders.0.id', $order->id)->where('orders.0.due_left_minor', null)
            ->where('orders.0.delivery', 'none')->where('orders.0.open_remarks', 0)
            ->where('orders.0.stages_total', fn ($n) => $n > 0)
            ->has('visits', 1)->has('permissions', count(FieldRepAccess::TOGGLES)));

        $this->actingAs($f->manager)->get("/field-sales/team/{$f->rep->id}")->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.due_left_minor', fn ($v) => is_int($v) && $v > 0));
    });

    it('notifies the rep\'s supervisors, not others, when a lead or a task of the rep is late', function () {
        $f = $this->f;
        $this->travelTo(now()->next('Sunday')->setTime(9, 0));
        app(FieldDispatch::class)->assignLead($f->supervisor, fieldLead($f->owner, $f->org->a), $f->rep->id);
        $order = openCustomOrder($f->rep, Customer::factory()->create(['branch_id' => $f->org->a->id, 'assignee_id' => $f->rep->id]));
        $task = Task::query()->where('order_id', $order->id)->firstOrFail();
        $task->forceFill(['assignee_id' => $f->rep->id, 'status' => 'assigned', 'due_at' => now()->addHours(2)])->save();

        $this->travel(25)->hours();
        app(FieldAssignmentSla::class)->run(now());

        $team = UserNotification::query()->where('type', 'field_sales.team_overdue');
        expect((clone $team)->where('user_id', $f->supervisor->id)->count())->toBe(2)
            ->and((clone $team)->where('user_id', $f->supervisorB->id)->count())->toBe(0)
            ->and(UserNotification::query()->where('type', 'field_sales.lead_overdue')->where('user_id', $f->rep->id)->count())->toBe(1);

        // Once per item.
        app(FieldAssignmentSla::class)->run(now());
        expect((clone $team)->where('user_id', $f->supervisor->id)->count())->toBe(2);
        $this->actingAs($f->supervisor)->get('/notifications/'.(clone $team)->where('user_id', $f->supervisor->id)->value('id').'/open')
            ->assertRedirect('/field-sales/team');
    });
});
