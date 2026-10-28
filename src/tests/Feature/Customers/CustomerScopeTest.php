<?php

/**
 * Scope matrix for P-09 / P-10 over customers: in scope, out of scope, unauthorized; lists, search and hidden
 * fields follow the same grants as opening a record.
 */

use App\Access\Access;
use App\Models\Customer;
use App\Models\MemberGrant;
use App\Models\User;
use App\Search\Providers\CustomerSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

function grantRaw(User $user, string $permission, string $scope, ?array $targets = null): void
{
    MemberGrant::query()->create([
        'membership_id' => Org::membershipOf($user)->id, 'permission' => $permission, 'effect' => 'allow', 'scope' => $scope, 'targets' => $targets,
    ]);
}

beforeEach(function () {
    $this->org = Org::make();
    $this->salesA = $this->org->member('sales', [$this->org->a]);
    $this->inA = Customer::factory()->organization()->create(['branch_id' => $this->org->a->id, 'name' => 'عميل الفرع أ']);
    $this->inB = Customer::factory()->create(['branch_id' => $this->org->b->id, 'name' => 'عميل الفرع ب']);
    $this->inC = Customer::factory()->create(['branch_id' => $this->org->c->id, 'name' => 'عميل الفرع ج']);
});

describe('P-10 edit customer', function () {
    it('allows in scope, denies out of scope, denies unauthorized', function () {
        $executor = $this->org->member('executor', [$this->org->a]);

        expect(Access::can($this->salesA, 'P-10', $this->inA))->toBeTrue()
            ->and(Access::can($this->salesA, 'P-10', $this->inC))->toBeFalse()
            ->and(Access::can($executor, 'P-10', $this->inA))->toBeFalse();

        $this->actingAs($this->salesA)->put('/customers/'.$this->inC->id, ['name' => 'x', 'kind' => 'person', 'mobile' => '0551112222'])->assertForbidden();
        expect($this->inC->refresh()->name)->toBe('عميل الفرع ج');
    });

    it('honours region and tenant scopes', function () {
        $northManager = $this->org->member('manager', [$this->org->a], null, 'region');
        $tenant = $this->org->member('sales', [$this->org->a], null, 'tenant');

        expect(Access::can($northManager, 'P-10', $this->inB))->toBeTrue()
            ->and(Access::can($northManager, 'P-10', $this->inC))->toBeFalse()
            ->and(Access::can($tenant, 'P-10', $this->inC))->toBeTrue();
    });

    it('honours own and assigned scopes', function () {
        $member = $this->org->member(null, [$this->org->a]);
        grantRaw($member, 'P-10', 'own');
        $mine = Customer::factory()->create(['branch_id' => $this->org->c->id, 'created_by' => $member->id]);

        expect(Access::can($member, 'P-10', $mine))->toBeTrue()
            ->and(Access::can($member, 'P-10', $this->inA))->toBeFalse();

        $assigned = $this->org->member(null, [$this->org->a]);
        grantRaw($assigned, 'P-10', 'assigned');
        $this->inC->update(['assignee_id' => $assigned->id]);

        expect(Access::can($assigned, 'P-10', $this->inC))->toBeTrue()
            ->and(Access::can($assigned, 'P-10', $this->inA))->toBeFalse();
    });
});

describe('P-09 view and create', function () {
    it('opens only in-scope customers and refuses others with 403', function () {
        $this->actingAs($this->salesA)->get('/customers/'.$this->inA->id)->assertOk();
        $this->actingAs($this->salesA)->get('/customers/'.$this->inC->id)->assertForbidden();
    });

    it('refuses the screens to a member without P-09 or P-10', function () {
        $executor = $this->org->member('executor', [$this->org->a]);

        $this->actingAs($executor)->get('/customers')->assertForbidden();
        $this->actingAs($executor)->get('/customers/'.$this->inA->id)->assertForbidden();
        $this->actingAs($executor)->get('/customers/create')->assertForbidden();
    });
});

describe('lists and search', function () {
    it('lists only customers inside the grant', function () {
        $this->actingAs($this->salesA)->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->where('meta.total', 1)
            ->where('customers.0.id', $this->inA->id));

        $this->actingAs($this->org->owner)->get('/customers')->assertInertia(fn (Assert $page) => $page->where('meta.total', 3));
    });

    it('searches only inside the grant', function () {
        $provider = app(CustomerSearchProvider::class);

        expect($provider->search($this->salesA, 'عميل', 10))->toHaveCount(1)
            ->and($provider->search($this->salesA, 'عميل', 10)[0]->title)->toBe('عميل الفرع أ')
            ->and($provider->search($this->org->owner, 'عميل', 10))->toHaveCount(3)
            ->and($provider->search($this->org->member('executor', [$this->org->a]), 'عميل', 10))->toBe([]);

        $this->actingAs($this->salesA)->getJson('/search/suggest?q='.urlencode('الفرع ج'))->assertOk()->assertJsonMissing(['title' => 'عميل الفرع ج']);
    });

    it('applies filters and pages of twenty', function () {
        Customer::factory()->count(25)->create(['branch_id' => $this->org->a->id]);
        $this->inC->update(['assignee_id' => $this->org->owner->id]);
        $owner = $this->actingAs($this->org->owner);

        $owner->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 28)->where('meta.last_page', 2)->has('customers', 20));
        $owner->get('/customers?page=2')->assertInertia(fn (Assert $page) => $page->has('customers', 8));
        $owner->get('/customers?kind=organization')->assertInertia(fn (Assert $page) => $page->where('meta.total', 1));
        $owner->get('/customers?branch='.$this->org->c->id)->assertInertia(fn (Assert $page) => $page->where('meta.total', 1));
        $owner->get('/customers?assignee='.$this->org->owner->id)->assertInertia(fn (Assert $page) => $page->where('meta.total', 1)->where('customers.0.id', $this->inC->id));
        $owner->get('/customers?assignee=none')->assertInertia(fn (Assert $page) => $page->where('meta.total', 27));
        $owner->get('/customers?q='.urlencode($this->inB->mobile))->assertInertia(fn (Assert $page) => $page->where('meta.total', 1)->where('customers.0.id', $this->inB->id));
        $owner->get('/customers?kind=bogus')->assertSessionHasErrors('kind');
    });

    it('never widens the page by filter: a filter on an out-of-scope branch returns nothing', function () {
        $this->actingAs($this->salesA)->get('/customers?branch='.$this->org->c->id)->assertInertia(fn (Assert $page) => $page->where('meta.total', 0));
    });
});

describe('hidden fields per grant', function () {
    beforeEach(function () {
        $this->inA->update(['internal_notes' => 'ملاحظة داخلية', 'office_phone' => '+966114652290']);
    });

    it('sends sensitive fields to a P-10 holder', function () {
        $this->actingAs($this->salesA)->get('/customers/'.$this->inA->id)->assertInertia(fn (Assert $page) => $page
            ->where('customer.vat_number', '310458712300003')
            ->where('customer.commercial_registration', '1010587342')
            ->where('customer.internal_notes', 'ملاحظة داخلية')
            ->where('sensitiveHidden', false)
            ->where('can.edit', true));
    });

    it('leaves sensitive fields out of the payload for a P-09-only viewer', function () {
        $viewer = $this->org->member(null, [$this->org->a]);
        grantRaw($viewer, 'P-09', 'branches', [$this->org->a->id]);

        $this->actingAs($viewer)->get('/customers/'.$this->inA->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('customer.vat_number')->missing('customer.commercial_registration')
            ->missing('customer.internal_notes')->missing('customer.office_phone')
            ->where('customer.name', 'عميل الفرع أ')
            ->where('sensitiveHidden', true)
            ->where('can.edit', false));

        $this->actingAs($viewer)->get('/customers/'.$this->inA->id.'/edit')->assertForbidden();
    });

    it('does not expose sensitive fields in the list rows', function () {
        $this->actingAs($this->org->owner)->get('/customers')->assertInertia(fn (Assert $page) => $page
            ->missing('customers.0.vat_number')->missing('customers.0.internal_notes'));
    });
});
