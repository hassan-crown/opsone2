<?php

/**
 * `AuditLog` (P-63): read-only, server-paginated, limited to the viewer's scope; the detail drawer re-checks
 * the scope of the single event it opens.
 */

use App\Actions\StateMachines\M22Membership\T01DisableMembership;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->manager = $this->org->member('manager', [$this->org->a]);
    $this->inA = $this->org->member('sales', [$this->org->a]);
    $this->inC = $this->org->member('sales', [$this->org->c]);

    app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->inA), 'left');
    app(T01DisableMembership::class)->handle($this->org->owner, Org::membershipOf($this->inC), 'left');
    DB::transaction(fn () => Audit::record(AuditAction::FacilityProfileUpdated, $this->org->facility, $this->org->owner, ['name_en' => 'x'], ['name_en' => 'y']));

    $this->eventA = AuditEvent::query()->where('action', 'membership.disabled')->where('subject_label', $this->inA->name)->firstOrFail();
    $this->eventC = AuditEvent::query()->where('action', 'membership.disabled')->where('subject_label', $this->inC->name)->firstOrFail();
});

it('shows every event to a facility-wide viewer', function () {
    $this->actingAs($this->org->owner)
        ->get('/org/audit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->where('events.total', AuditEvent::query()->count())
            ->where('selected', null)
            ->has('areas'));
});

it('limits a branch-scoped manager to events inside their branches', function () {
    $this->actingAs($this->manager)
        ->get('/org/audit')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.data', fn ($rows) => collect($rows)->pluck('id')->contains($this->eventA->id)
                && ! collect($rows)->pluck('id')->contains($this->eventC->id)
                && ! collect($rows)->pluck('action')->contains('settings.profile_updated')));
});

it('opens the detail drawer only for an event in scope', function () {
    $this->actingAs($this->manager)
        ->get('/org/audit?event='.$this->eventA->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $this->eventA->id)
            ->where('selected.before', ['status' => 'active'])
            ->where('selected.after', ['status' => 'disabled'])
            ->where('selected.reason', 'left'));

    $this->actingAs($this->manager)
        ->get('/org/audit?event='.$this->eventC->id)
        ->assertInertia(fn (Assert $page) => $page->where('selected', null));
});

it('filters by area and actor', function () {
    $this->actingAs($this->org->owner)
        ->get('/org/audit?area=settings&actor='.$this->org->owner->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.area', 'settings')
            ->where('events.data', fn ($rows) => collect($rows)->isNotEmpty()
                && collect($rows)->every(fn ($r) => $r['area'] === 'settings')));
});

it('refuses members without P-63', function () {
    $this->actingAs($this->org->member('sales', [$this->org->a]))->get('/org/audit')->assertForbidden();
});

it('shows the audit entry in navigation only to viewers with P-63', function () {
    $this->actingAs($this->org->owner)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('orgNav.audit', true));
    $this->actingAs($this->org->member('sales', [$this->org->a]))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('orgNav.audit', false));
});
