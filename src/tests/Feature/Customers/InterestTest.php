<?php

/**
 * COM-03 / M-03: interest states and transitions (logged in state_transitions), one-time conversion,
 * scope matrix for P-09 and P-15, lists and filters.
 */

use App\Access\Exceptions\InvalidTransition;
use App\Actions\StateMachines\M03Interest\T00CreateInterest;
use App\Actions\StateMachines\M03Interest\T01TakeFollowUp;
use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Actions\StateMachines\M03Interest\T03CloseInterest;
use App\Actions\StateMachines\M03Interest\T04ReopenInterest;
use App\Enums\InterestCloseReason;
use App\Enums\InterestStatus;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Interest;
use App\Models\MemberGrant;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Org::make();
    $this->sales = $this->org->member('sales', [$this->org->a]);
    $this->payload = ['name' => 'ريم العتيبي', 'mobile' => '0555551234', 'need' => 'ستائر للصالة', 'channel' => 'whatsapp', 'branch_id' => $this->org->a->id];
    $this->make = fn () => app(T00CreateInterest::class)->handle($this->sales, $this->payload);
});

function transitionsOf(Interest $interest): array
{
    return StateTransition::query()->where('subject_type', $interest->getMorphClass())->where('subject_id', $interest->id)
        ->orderBy('id')->get()->map(fn ($t) => $t->transition.':'.($t->from_state ?? '-').'>'.$t->to_state)->all();
}

it('records an interest as new with a reference and a T00 transition', function () {
    $this->actingAs($this->sales)->post('/interests', $this->payload)->assertRedirect();

    $interest = Interest::query()->firstOrFail();
    expect($interest->status)->toBe(InterestStatus::New)
        ->and($interest->reference)->toBe(sprintf('INT-%04d', $interest->id))
        ->and($interest->mobile)->toBe('+966555551234')
        ->and(transitionsOf($interest))->toBe(['T00:->new'])
        ->and(AuditEvent::query()->where('action', 'interest.created')->count())->toBe(1);
});

it('needs a mobile or an email', function () {
    $this->actingAs($this->sales)->post('/interests', ['mobile' => ''] + $this->payload)->assertSessionHasErrors(['mobile', 'email']);
});

it('walks new → following → closed → following → converted, logging every step', function () {
    $interest = ($this->make)();

    app(T01TakeFollowUp::class)->handle($this->sales, $interest);
    app(T03CloseInterest::class)->handle($this->sales, $interest, InterestCloseReason::NoReply);
    expect($interest->refresh()->status)->toBe(InterestStatus::Closed)->and($interest->close_reason)->toBe(InterestCloseReason::NoReply);

    app(T04ReopenInterest::class)->handle($this->sales, $interest, 'عاد العميل');
    expect($interest->refresh()->status)->toBe(InterestStatus::Following)->and($interest->close_reason)->toBeNull();

    app(T02ConvertInterest::class)->handle($this->sales, $interest);

    expect(transitionsOf($interest))->toBe(['T00:->new', 'T01:new>following', 'T03:following>closed', 'T04:closed>following', 'T02:following>converted'])
        ->and(AuditEvent::query()->whereIn('action', ['interest.followed', 'interest.closed', 'interest.reopened', 'interest.converted'])->count())->toBe(4);
});

it('refuses undefined transitions without any change', function (string $from, string $transition) {
    $interest = ($this->make)();
    $interest->forceFill(['status' => $from])->save();
    $before = StateTransition::query()->count();

    $run = match ($transition) {
        'T01' => fn () => app(T01TakeFollowUp::class)->handle($this->sales, $interest),
        'T02' => fn () => app(T02ConvertInterest::class)->handle($this->sales, $interest),
        'T03' => fn () => app(T03CloseInterest::class)->handle($this->sales, $interest, InterestCloseReason::Other, 'x'),
        'T04' => fn () => app(T04ReopenInterest::class)->handle($this->sales, $interest, 'why'),
    };

    expect($run)->toThrow(InvalidTransition::class);
    expect($interest->refresh()->status->value)->toBe($from)->and(StateTransition::query()->count())->toBe($before);
})->with([
    ['following', 'T01'], ['converted', 'T01'], ['closed', 'T01'],
    ['closed', 'T02'], ['converted', 'T02'],
    ['closed', 'T03'], ['converted', 'T03'],
    ['new', 'T04'], ['following', 'T04'], ['converted', 'T04'],
]);

it('converts once: a second conversion is refused and creates nothing', function () {
    $interest = ($this->make)();
    app(T02ConvertInterest::class)->handle($this->sales, $interest);

    $customers = Customer::query()->count();
    $transitions = StateTransition::query()->count();
    $audits = AuditEvent::query()->count();

    expect(fn () => app(T02ConvertInterest::class)->handle($this->sales, $interest))->toThrow(InvalidTransition::class, 'already_converted');

    expect(Customer::query()->count())->toBe($customers)->and(StateTransition::query()->count())->toBe($transitions)->and(AuditEvent::query()->count())->toBe($audits);

    $customer = Customer::query()->firstOrFail();
    expect($customer->mobile)->toBe('+966555551234')->and($customer->branch_id)->toBe($this->org->a->id)
        ->and($interest->refresh()->converted_customer_id)->toBe($customer->id)
        ->and($interest->customer_id)->toBe($customer->id);
});

it('is idempotent over HTTP: repeating the request opens the interest again without a second customer', function () {
    $interest = ($this->make)();

    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/convert')->assertRedirect('/customers/'.Customer::query()->value('id'));
    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/convert')->assertRedirect('/interests/'.$interest->id);

    expect(Customer::query()->count())->toBe(1);
});

it('links an existing customer found by mobile instead of creating a duplicate', function () {
    $existing = Customer::factory()->create(['mobile' => '+966555551234', 'branch_id' => $this->org->a->id]);
    $interest = ($this->make)();

    app(T02ConvertInterest::class)->handle($this->sales, $interest);

    expect(Customer::query()->count())->toBe(1)->and($interest->refresh()->converted_customer_id)->toBe($existing->id);
});

it('does not link or reveal a customer outside the actor scope', function () {
    Customer::factory()->create(['mobile' => '+966555551234', 'branch_id' => $this->org->c->id]);
    $interest = ($this->make)();

    expect(fn () => app(T02ConvertInterest::class)->handle($this->sales, $interest))->toThrow(ValidationException::class);
    expect($interest->refresh()->status)->toBe(InterestStatus::New);
});

it('needs a mobile to create the customer on conversion (Q-27)', function () {
    $interest = app(T00CreateInterest::class)->handle($this->sales, ['mobile' => null, 'email' => 'x@example.test'] + $this->payload);

    expect(fn () => app(T02ConvertInterest::class)->handle($this->sales, $interest))->toThrow(ValidationException::class);
    expect(Customer::query()->count())->toBe(0);
});

it('requires a reason to close, and a note for «other»', function () {
    $interest = ($this->make)();

    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/close', [])->assertSessionHasErrors('reason');
    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/close', ['reason' => 'other'])->assertSessionHasErrors('note');
    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/close', ['reason' => 'price'])->assertSessionHasNoErrors();

    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/reopen', [])->assertSessionHasErrors('reason');
    $this->actingAs($this->sales)->post('/interests/'.$interest->id.'/reopen', ['reason' => 'عاد'])->assertSessionHasNoErrors();
    expect($interest->refresh()->status)->toBe(InterestStatus::Following);
});

describe('scope matrix', function () {
    it('allows in scope, denies out of scope, denies unauthorized', function () {
        $inA = ($this->make)();
        $inC = Interest::factory()->create(['branch_id' => $this->org->c->id]);
        $executor = $this->org->member('executor', [$this->org->a]);

        $this->actingAs($this->sales)->post('/interests/'.$inA->id.'/follow')->assertRedirect();
        $this->actingAs($this->sales)->post('/interests/'.$inC->id.'/follow')->assertForbidden();
        $this->actingAs($executor)->post('/interests/'.$inA->id.'/close', ['reason' => 'price'])->assertForbidden();
        $this->actingAs($this->sales)->get('/interests/'.$inC->id)->assertForbidden();

        expect($inC->refresh()->status)->toBe(InterestStatus::New);
    });

    it('creates only inside the P-09 branches', function () {
        $this->actingAs($this->sales)->post('/interests', ['branch_id' => $this->org->c->id] + $this->payload)->assertForbidden();
        expect(Interest::query()->count())->toBe(0);
    });

    it('needs P-15 to convert, even with P-09', function () {
        $member = $this->org->member(null, [$this->org->a]);
        MemberGrant::query()->create(['membership_id' => Org::membershipOf($member)->id, 'permission' => 'P-09', 'effect' => 'allow', 'scope' => 'branches', 'targets' => [$this->org->a->id]]);
        $interest = ($this->make)();

        $this->actingAs($member)->post('/interests/'.$interest->id.'/convert')->assertForbidden();
        expect($interest->refresh()->status)->toBe(InterestStatus::New)->and(Customer::query()->count())->toBe(0);
    });

    it('lists only interests inside the grant, with filters, counts and pages', function () {
        ($this->make)();
        Interest::factory()->count(22)->create(['branch_id' => $this->org->a->id]);
        Interest::factory()->create(['branch_id' => $this->org->c->id]);
        Interest::factory()->create(['branch_id' => $this->org->a->id, 'status' => 'closed', 'channel' => 'website']);

        $sales = $this->actingAs($this->sales);
        $sales->get('/interests')->assertInertia(fn (Assert $page) => $page
            ->component('interests/index')->where('meta.total', 24)->has('interests', 20)->where('counts.new', 23)->where('counts.closed', 1));
        $sales->get('/interests?status=closed')->assertInertia(fn (Assert $page) => $page->where('meta.total', 1));
        $sales->get('/interests?channel=website')->assertInertia(fn (Assert $page) => $page->where('meta.total', 1));
        $sales->get('/interests?q='.urlencode('ريم'))->assertInertia(fn (Assert $page) => $page->where('meta.total', 1));
        $sales->get('/interests?page=2')->assertInertia(fn (Assert $page) => $page->has('interests', 4));
        $this->actingAs($this->org->owner)->get('/interests')->assertInertia(fn (Assert $page) => $page->where('meta.total', 25));
    });
});
