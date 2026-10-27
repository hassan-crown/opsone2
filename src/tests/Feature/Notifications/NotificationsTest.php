<?php

use App\Access\Contracts\ScopedRecord;
use App\Access\Permission;
use App\Models\NotificationPreference;
use App\Models\OutboxMessage;
use App\Models\UserNotification;
use App\Notifications\NotificationCatalogue;
use App\Notifications\NotificationTargets;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

uses(RefreshDatabase::class);

/** A domain record living in one branch, standing in for an order until domain phases exist. */
function fakeRecord(int $branchId): ScopedRecord
{
    return new class($branchId) implements ScopedRecord
    {
        public function __construct(private int $branchId) {}

        public function accessBranchIds(): array
        {
            return [$this->branchId];
        }

        public function accessDepartmentId(): ?int
        {
            return null;
        }

        public function accessAssigneeIds(): array
        {
            return [];
        }

        public function accessOwnerId(): ?int
        {
            return null;
        }
    };
}

beforeEach(function () {
    Queue::fake();
    $this->org = Org::make();
    $this->inA = $this->org->member('manager', [$this->org->a]);
    $this->inB = $this->org->member('manager', [$this->org->b]);

    NotificationCatalogue::define('test.record', [
        'mandatory' => false,
        'permission' => Permission::P03,
        'title' => 'notifications.type.work_assigned.title',
        'body' => null,
        'route' => null,
    ]);
    app(NotificationTargets::class)->register('test_record', fn (int $id) => $id === 1 ? fakeRecord($this->org->a->id) : null, fn (object $r) => '/records/1');

    $this->notify = fn ($user, string $type = 'test.record', ?string $targetType = 'test_record') => UserNotification::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'data' => ['title' => 'notifications.type.work_assigned.title', 'params' => []],
        'target_type' => $targetType,
        'target_id' => $targetType === null ? null : 1,
    ]);
});

afterEach(fn () => NotificationCatalogue::flush());

it('opens a link when the user may access the target record', function () {
    $n = ($this->notify)($this->inA);

    $this->actingAs($this->inA)->get(route('notifications.open', $n))->assertRedirect('/records/1');
    expect($n->fresh()->read_at)->not->toBeNull();
});

it('refuses a link to a record outside the user scope — a notification never grants access', function () {
    $n = ($this->notify)($this->inB);

    $this->actingAs($this->inB)->get(route('notifications.open', $n))->assertRedirect(route('dashboard'));
    expect($n->fresh()->read_at)->not->toBeNull();

    // Same for a member without the permission, and when the record no longer exists.
    $sales = $this->org->member('sales', [$this->org->a]);
    $this->actingAs($sales)->get(route('notifications.open', ($this->notify)($sales)))->assertRedirect(route('dashboard'));

    $gone = ($this->notify)($this->inA);
    $gone->forceFill(['target_id' => 99])->save();
    $this->actingAs($this->inA)->get(route('notifications.open', $gone))->assertRedirect(route('dashboard'));
});

it('re-checks access each time, so a revoked scope closes the link', function () {
    $n = ($this->notify)($this->inA);
    $this->actingAs($this->inA)->get(route('notifications.open', $n))->assertRedirect('/records/1');

    // Moves the user to branch B only: the same notification no longer opens the record.
    $membership = Org::membershipOf($this->inA);
    $membership->branches()->sync([$this->org->b->id]);
    $this->inA->increment('access_version');
    $this->flushSession();

    $this->actingAs($this->inA)->get(route('notifications.open', $n))->assertRedirect(route('dashboard'));
});

it('refuses a targeted notification whose type has no catalogued permission', function () {
    $n = ($this->notify)($this->inA, 'ownership.transfer_requested');

    $this->actingAs($this->inA)->get(route('notifications.open', $n))->assertRedirect(route('dashboard'));
});

it('hides other users notifications', function () {
    $n = ($this->notify)($this->inA);

    $this->actingAs($this->inB)->get(route('notifications.open', $n))->assertNotFound();
    $this->actingAs($this->inB)->post(route('notifications.read', $n))->assertNotFound();
    expect($n->fresh()->read_at)->toBeNull();
});

it('shares the unread count and marks one or all as read', function () {
    ($this->notify)($this->inA);
    ($this->notify)($this->inA);
    ($this->notify)($this->inB);

    $this->actingAs($this->inA)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 2)
            ->has('notifications.items', 2));

    $first = UserNotification::query()->where('user_id', $this->inA->id)->firstOrFail();
    $this->actingAs($this->inA)->post(route('notifications.read', $first))->assertRedirect();
    $this->actingAs($this->inA)->post(route('notifications.read-all'))->assertRedirect();

    expect(UserNotification::query()->where('user_id', $this->inA->id)->whereNull('read_at')->count())->toBe(0)
        // Another user's notifications are untouched by "read all".
        ->and(UserNotification::query()->where('user_id', $this->inB->id)->whereNull('read_at')->count())->toBe(1);

    $this->actingAs($this->inA)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 0));
});

it('does not put urls or record ids into the shared bell data', function () {
    ($this->notify)($this->inA);

    $props = $this->actingAs($this->inA)->get(route('dashboard'))->viewData('page')['props']['notifications'];

    expect(json_encode($props))->not->toContain('/records/')->and($props['items'][0])->not->toHaveKeys(['target_id', 'target_type', 'url']);
});

it('emails mandatory types always and optional ones only when the user wants them', function () {
    $notifier = app(Notifier::class);

    $notifier->send($this->inA, 'ownership.transfer_requested', ['from' => 'Owner']);
    expect(OutboxMessage::query()->count())->toBe(1);

    $notifier->send($this->inA, 'work.assigned');
    expect(OutboxMessage::query()->count())->toBe(2);

    NotificationPreference::query()->create(['user_id' => $this->inA->id, 'type' => 'work.assigned', 'email' => false]);
    $notifier->send($this->inA, 'work.assigned');

    // The in-app copy always exists; only the email is optional.
    expect(OutboxMessage::query()->count())->toBe(2)
        ->and(UserNotification::query()->where('user_id', $this->inA->id)->count())->toBe(3);
});

it('creates one notification per dedupe key', function () {
    $notifier = app(Notifier::class);

    $notifier->send($this->inA, 'work.reminder', [], null, 'reminder:1');
    $notifier->send($this->inA, 'work.reminder', [], null, 'reminder:1');

    expect(UserNotification::query()->where('user_id', $this->inA->id)->count())->toBe(1);
});

it('saves email preferences for optional types only', function () {
    $this->actingAs($this->inA)->get(route('notifications.preferences'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/notifications')->has('types'));

    $this->actingAs($this->inA)->put(route('notifications.preferences.update'), [
        'preferences' => [['type' => 'work.assigned', 'email' => false]],
    ])->assertRedirect();
    expect(NotificationPreference::query()->where('user_id', $this->inA->id)->where('type', 'work.assigned')->value('email'))->toBeFalse();

    // A mandatory type cannot be switched off.
    $this->actingAs($this->inA)->put(route('notifications.preferences.update'), [
        'preferences' => [['type' => 'ownership.transfer_requested', 'email' => false]],
    ])->assertSessionHasErrors('preferences.0.type');
});

it('requires sign-in', function () {
    $n = ($this->notify)($this->inA);

    $this->get(route('notifications.open', $n))->assertRedirect(route('login'));
    $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
});
