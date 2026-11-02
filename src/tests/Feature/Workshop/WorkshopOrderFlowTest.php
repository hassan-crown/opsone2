<?php

/**
 * ADR-0038: the retail default flow and its workshop order. The purchase order is the only source: confirming it
 * verifies «sent», a full accepted receipt verifies «received» (a rejected quantity never does), the receiving
 * stage's due date is the workshop's date, and a late workshop notifies its followers once per date.
 */

use App\Actions\StateMachines\M13PurchaseOrder\T01ConfirmPurchaseOrder;
use App\Actions\StateMachines\M13PurchaseOrder\T03CancelRemainder;
use App\Actions\StateMachines\M23Flow\T02SaveDraft;
use App\Actions\StateMachines\M23Flow\T03PublishFlow;
use App\Enums\ActionStatus;
use App\Enums\TaskStatus;
use App\Models\Facility;
use App\Models\Flow;
use App\Models\OrderLine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\RoleTemplate;
use App\Models\Task;
use App\Models\UserNotification;
use App\Procurement\WorkshopOrders;
use App\Workflow\FlowCheck;
use App\Workflow\FlowTemplates;
use App\Workflow\StageEngine;
use App\Workflow\WorkflowPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/WorkshopTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('attachments'));

it('provisions the retail flow with an external workshop as the default', function () {
    config(['opsone.default_flow_template' => null]);
    expect(FlowTemplates::provisioned())->toBe(FlowTemplates::RETAIL)->and(FlowTemplates::DEFAULT)->toBe('retail_outsourced');

    $f = workshopFixture();
    $stages = Flow::current()->publishedVersion->definition['stages'];

    expect(Flow::current()->template_key)->toBe(FlowTemplates::RETAIL)
        ->and(array_column($stages, 'key'))->toBe(['measure', 'design', 'agreement', 'workshop', 'receiving', 'install'])
        ->and($stages[3]['actions'][0])->toMatchArray(['type' => 'workshop_order', 'inputs' => ['reference' => 'workshop', 'milestone' => 'sent']])
        ->and($stages[4]['actions'][0])->toMatchArray(['type' => 'workshop_order', 'inputs' => ['reference' => 'workshop', 'milestone' => 'received']])
        // The workshop stages are followed by the procurement role; every action goes to its stage's responsible.
        ->and($stages[3]['follow_up'])->toBe(['type' => 'role', 'id' => (int) RoleTemplate::query()->where('key', 'inventory')->value('id')])
        ->and(collect($stages)->flatMap(fn ($s) => $s['actions'])->pluck('assign.type')->unique()->values()->all())->toBe(['stage'])
        ->and(stageOf($f->order, 'workshop')->follow_up_type)->toBe('role');
});

it('ships industry variants and keeps in-house manufacturing as a template and an add-on', function () {
    workshopFixture();
    $kitchens = FlowTemplates::definition(FlowTemplates::RETAIL_KITCHENS)['stages'];
    $aluminium = FlowTemplates::definition(FlowTemplates::RETAIL_ALUMINIUM)['stages'];
    $furniture = FlowTemplates::definition(FlowTemplates::RETAIL_FURNITURE)['stages'];

    expect(array_column($kitchens[3]['actions'], 'key'))->toBe(['workshop_sent', 'countertop_sent'])
        ->and($kitchens[4]['target_days'])->toBe(18)
        ->and($aluminium[0]['actions'][1]['applies_when'])->toBe('always')
        ->and($furniture[2]['actions'][3]['inputs']['value'])->toBe(30)
        ->and(array_column(FlowTemplates::definition(FlowTemplates::IN_HOUSE)['stages'], 'key'))->toContain('manufacture');

    // The add-on goes into a draft after the agreement, and the result checks and publishes like any change.
    $stages = FlowTemplates::definition(FlowTemplates::RETAIL)['stages'];
    array_splice($stages, 3, 0, FlowTemplates::definition(FlowTemplates::ADDON_IN_HOUSE)['stages']);
    expect(app(FlowCheck::class)->run(['stages' => $stages])->issues)->toBe([]);

    $owner = Facility::current()->owner->user;
    app(T02SaveDraft::class)->handle($owner, ['stages' => $stages], Flow::current()->lock_version);
    app(T03PublishFlow::class)->handle($owner, Flow::current()->lock_version);
    expect(array_column(Flow::current()->refresh()->publishedVersion->definition['stages'], 'key'))
        ->toBe(['measure', 'design', 'agreement', 'materials_prep', 'fabrication', 'assembly', 'inhouse_qc', 'workshop', 'receiving', 'install']);
});

it('verifies «sent» when the workshop purchase order is confirmed, never from a draft', function () {
    $f = workshopFixture();
    toWorkshopStage($f);
    $sent = actionOf($f->order, 'workshop_sent');
    expect($sent->status)->toBe(ActionStatus::Ready)
        // A source action creates no task: only the purchase order can complete it.
        ->and(Task::query()->where('order_action_id', $sent->id)->exists())->toBeFalse();

    $po = workshopPo($f, confirm: false);
    expect($sent->refresh()->status)->toBe(ActionStatus::Ready);

    app(T01ConfirmPurchaseOrder::class)->handle($f->inventory, $po, $po->lock_version, procKey());
    expect($sent->refresh()->status)->toBe(ActionStatus::Verified)
        ->and($sent->source_reference)->toBe(['type' => 'purchase_order', 'id' => $po->id]);

    // The expected date proposed from the workshop's lead time becomes the receiving stage's due date.
    expect(stageOf($f->order, 'receiving')->expected_on?->toDateString())->toBe($po->refresh()->expected_on->toDateString());
});

it('keeps «received» open on a rejected quantity and verifies it on the full accepted receipt', function () {
    $f = workshopFixture();
    toWorkshopStage($f);
    $po = workshopPo($f);
    finishActiveStage($f->owner, $f->order);
    expect(app(StageEngine::class)->activeStage($f->order->refresh())->key)->toBe('receiving');
    $received = actionOf($f->order, 'workshop_received');

    procReceive($f, $po, [1 => [0, 1000, 'خدش في الواجهة']]);
    expect($received->refresh()->status)->toBe(ActionStatus::Ready)
        ->and(Task::query()->where('order_action_id', actionOf($f->order, 'quality_check')->id)->exists())->toBeFalse();

    $receipt = procReceive($f, $po, [1 => [1000]]);
    expect($received->refresh()->status)->toBe(ActionStatus::Verified)
        ->and($received->source_reference)->toBe(['type' => 'purchase_receipt', 'id' => $receipt->id])
        // The quality check opens after the receipt, for the stage's responsible (the procurement role member).
        ->and(taskOf($f->order, 'quality_check')->assignee_id)->toBe($f->inventory->id)
        ->and(taskOf($f->order, 'quality_check')->status)->toBe(TaskStatus::Assigned);
});

it('applies a workshop result recorded before its stage opens, and never on a cancelled remainder', function () {
    $f = workshopFixture();
    $po = workshopPo($f);
    $sent = actionOf($f->order, 'workshop_sent');
    expect($sent->refresh()->status)->toBe(ActionStatus::Unavailable)
        ->and($sent->source_reference)->toBe(['type' => 'purchase_order', 'id' => $po->id]);

    toWorkshopStage($f);
    expect($sent->refresh()->status)->toBe(ActionStatus::Verified);

    app(T03CancelRemainder::class)->handle($f->inventory, $po->refresh(), 'الورشة اعتذرت', $po->lock_version, procKey());
    expect(actionOf($f->order, 'workshop_received')->source_reference)->toBeNull();
});

it('follows each purchase order by its flow reference when the flow has two workshop orders', function () {
    $f = workshopFixture(FlowTemplates::RETAIL_KITCHENS);
    toWorkshopStage($f);
    $stone = procSupplier(['name' => ['ar' => 'مورد الرخام'], 'is_workshop' => true]);

    workshopPo($f, supplier: $stone, extra: ['flow_reference' => 'countertop']);
    expect(actionOf($f->order, 'countertop_sent')->status)->toBe(ActionStatus::Verified)
        ->and(actionOf($f->order, 'workshop_sent')->status)->toBe(ActionStatus::Ready);

    // Without a reference, two workshop references are ambiguous: nothing is verified by guess.
    workshopPo($f);
    expect(actionOf($f->order, 'workshop_sent')->status)->toBe(ActionStatus::Ready);
    workshopPo($f, extra: ['flow_reference' => 'workshop']);
    expect(actionOf($f->order, 'workshop_sent')->status)->toBe(ActionStatus::Verified);
});

it('completes supply follow-up from the materials purchase order (W11)', function () {
    $f = workshopFixture(FlowTemplates::IN_HOUSE);
    $materials = actionOf($f->order, 'materials');
    $po = procOrdered($f, [[...procLine('material', $f->plain->id, 5_000, 100), 'order_id' => $f->order->id]]);
    expect($materials->refresh()->source_reference)->toBeNull();

    $receipt = procReceive($f, $po, [1 => [5_000]]);
    expect($materials->refresh()->source_reference)->toBe(['type' => 'purchase_receipt', 'id' => $receipt->id]);
});

it('links only lines of the order itself to a workshop purchase order', function () {
    $f = workshopFixture();
    $other = openCustomOrder($f->sales, $f->customer);
    $foreign = OrderLine::query()->where('order_id', $other->id)->value('id');

    expect(fn () => procDraft($f, $f->inventory, [[
        'id' => null, 'item_type' => 'order_line', 'item_id' => $foreign, 'quantity_milli' => 1000, 'order_id' => $f->order->id,
    ]], ['supplier_id' => $f->workshop->id]))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->count())->toBe(0);

    // A workshop line never reaches the stock.
    $po = workshopPo($f);
    procReceive($f, $po, [1 => [1000]]);
    expect($f->stock->calls)->toBe([]);
});

it('marks a late workshop, notifies its followers once per date, and lists it on the board', function () {
    $f = workshopFixture();
    toWorkshopStage($f);
    $po = workshopPo($f, extra: ['expected_on' => now()->addDays(3)->toDateString()]);
    finishActiveStage($f->owner, $f->order);

    $this->travel(5)->days();
    expect(WorkshopOrders::lateDays($po->refresh()))->toBe(2);

    Artisan::call('workshops:late-alerts');
    Artisan::call('workshops:late-alerts');
    $notified = UserNotification::query()->where('type', 'workshop.late')->pluck('user_id')->sort()->values()->all();
    expect($notified)->toBe(collect([$f->inventory->id, $f->sales->id])->sort()->values()->all());

    $progress = WorkflowPresenter::order($f->order->refresh(), $f->owner);
    $receiving = collect($progress['stages'])->firstWhere('key', 'receiving');
    expect($receiving['days_late'])->toBe(2)->and($receiving['due_on'])->toBe($po->expected_on->toDateString());

    $this->actingAs($f->inventory)->get(route('workshops.index', ['late' => 1]))->assertOk()
        ->assertInertia(fn ($p) => $p->component('purchases/workshops')
            ->has('rows', 1)->where('rows.0.number', $po->number)->where('rows.0.days_late', 2)
            ->where('rows.0.orders.0.number', $f->order->number)->where('summary.late', 1));
    $this->get(route('workshops.index', ['supplier' => $f->supplier->id]))->assertInertia(fn ($p) => $p->has('rows', 0));
    $this->get(route('workshops.index', ['branch' => $f->org->b->id]))->assertInertia(fn ($p) => $p->has('rows', 0));

    // Another branch's buyer does not see it; a member without purchase permissions cannot open the board.
    $this->actingAs($f->inventoryB)->get(route('workshops.index'))->assertOk()->assertInertia(fn ($p) => $p->has('rows', 0));
    $this->actingAs($f->sales)->get(route('workshops.index'))->assertForbidden();

    // Moving the date re-arms the alert.
    $po->forceFill(['expected_on' => now()->subDay()->toDateString()])->save();
    Artisan::call('workshops:late-alerts');
    expect(UserNotification::query()->where('type', 'workshop.late')->count())->toBe(4);
});
