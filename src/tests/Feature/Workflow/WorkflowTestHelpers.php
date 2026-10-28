<?php

use App\Access\Exceptions\InvalidTransition;
use App\Actions\Settings\UpdateWorkSettings;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\Workflow\RecordMeasurement;
use App\Enums\ActionStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSettings;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\StageEngine;
use App\Workflow\TaskEligibility;
use Illuminate\Support\Facades\DB;
use Tests\Support\Org;

require_once __DIR__.'/../Orders/OrderTestHelpers.php';

/**
 * Workflow fixture: the orders fixture (Org, sales in A, customers per branch) plus one custom order in branch A
 * opened by sales (needs measurement, no design) on the default flow.
 *
 * @return object{org: Org, sales: User, inA: Customer, inB: Customer, inC: Customer, order: Order}
 */
function workflowFixture(array $custom = []): object
{
    $f = ordersFixture();
    $f->order = openCustomOrder($f->sales, $f->inA, $custom);

    return $f;
}

function openCustomOrder(User $actor, Customer $customer, array $custom = []): Order
{
    $payload = customPayload($customer);
    $payload['custom'] = [...$payload['custom'], ...$custom];
    test()->actingAs($actor)->postJson('/orders', $payload)->assertCreated();

    return Order::query()->latest('id')->firstOrFail();
}

function stageOf(Order $order, string $key): OrderStage
{
    return OrderStage::query()->where('order_id', $order->id)->where('key', $key)->firstOrFail();
}

function actionOf(Order $order, string $key): OrderAction
{
    return OrderAction::query()->where('order_id', $order->id)->where('key', $key)->firstOrFail();
}

function taskOf(Order $order, string $actionKey): Task
{
    return Task::query()->where('order_action_id', actionOf($order, $actionKey)->id)->orderByDesc('id')->firstOrFail();
}

function finalMeasurement(User $actor, Order $order): void
{
    app(RecordMeasurement::class)->handle($actor, $order, [
        'location' => 'جدار المطبخ', 'name' => 'العرض', 'value' => '3.250', 'unit' => 'm', 'kind' => 'final', 'measured_on' => now()->toDateString(),
    ]);
}

/**
 * Brings every required action of the active stage to a result as the owner would, then completes the stage.
 */
function finishActiveStage(User $owner, Order $order): void
{
    $stage = app(StageEngine::class)->activeStage($order->refresh());
    foreach (OrderAction::query()->where('order_stage_id', $stage->id)->orderBy('position')->get() as $action) {
        $action->refresh();
        if (! $action->isRequired() || $action->status->satisfies()) {
            continue;
        }
        if ($action->type->completion()->createsTask()) {
            if ($action->type === ActionType::RecordMeasurements) {
                finalMeasurement($owner, $order);
            }
            $task = Task::query()->where('order_action_id', $action->id)->whereIn('status', TaskStatus::open())->firstOrFail();
            if ($task->status === TaskStatus::Unassigned) {
                // Inbox work: the first eligible member of its department takes it.
                $task->setRelation('order', $order);
                $holder = Membership::query()->where('department_id', $task->department_id)->where('status', 'active')->pluck('user_id')
                    ->first(fn (int $id) => TaskEligibility::allows($id, $task));
                app(T01AssignTask::class)->handle($owner, $task, $holder);
                $task->refresh();
            }
            app(T05CompleteTask::class)->handle($owner, $task);
        } else {
            app(ActionResults::class)->verifyFromSource($order, $action->type, purposeOf($action), 'test_source', $action->id, $owner);
        }
    }
    app(T04CompleteStage::class)->handle($owner, $stage->refresh());
}

function purposeOf(OrderAction $action): ?string
{
    $inputs = $action->inputs ?? [];

    return $inputs['reference'] ?? $inputs['subject'] ?? $inputs['document'] ?? null;
}

/**
 * Counts of the rows a refused transition must not touch.
 *
 * @return array<string, mixed>
 */
function workflowSnapshot(): array
{
    return [
        'transitions' => StateTransition::query()->count(),
        'audit' => AuditEvent::query()->count(),
        'tasks' => DB::table('tasks')->get()->map(fn ($t) => [$t->id, $t->status, $t->assignee_id, $t->lock_version])->all(),
        'actions' => DB::table('order_actions')->get()->map(fn ($a) => [$a->id, $a->status, $a->held_by_action_id])->all(),
        'stages' => DB::table('order_stages')->get()->map(fn ($s) => [$s->id, $s->status])->all(),
        'orders' => DB::table('orders')->get()->map(fn ($o) => [$o->id, $o->status, $o->lock_version])->all(),
        'appointments' => DB::table('appointments')->get()->map(fn ($a) => [$a->id, $a->status, $a->starts_at, $a->conflicted, $a->lock_version])->all(),
        'flows' => DB::table('flows')->get()->map(fn ($f) => [$f->id, $f->status, $f->published_version_id, $f->lock_version])->all(),
        'versions' => DB::table('flow_versions')->count(),
        'notifications' => DB::table('user_notifications')->count(),
    ];
}

/**
 * Runs a transition expected to be refused and asserts nothing changed.
 */
function expectRefusedWithoutEffect(Closure $attempt, string $exception = InvalidTransition::class, ?string $condition = null): void
{
    $before = workflowSnapshot();
    try {
        $attempt();
        test()->fail('The transition was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition)->toBe($condition);
        }
    }
    expect(workflowSnapshot())->toBe($before);
}

function lastTransition(string $machine): StateTransition
{
    return StateTransition::query()->where('machine', $machine)->latest('id')->firstOrFail();
}

function statusOf(OrderAction $action): ActionStatus
{
    return $action->refresh()->status;
}

function propose(User $actor, Order $order, array $memberIds, string $start = '2026-11-03 09:00', string $end = '2026-11-03 11:00'): Appointment
{
    return app(T00ProposeAppointment::class)->handle($actor, $order, [
        'kind' => 'measurement', 'starts_at' => $start.' Asia/Riyadh', 'ends_at' => $end.' Asia/Riyadh',
        'address' => 'الرياض، حي النرجس، شارع 12', 'member_ids' => $memberIds,
    ])['appointment'];
}

function blockConflicts(User $owner): void
{
    app(UpdateWorkSettings::class)->handle($owner, [...WorkSettings::DEFAULTS, 'conflicting_appointments' => WorkSettings::BLOCK]);
}
