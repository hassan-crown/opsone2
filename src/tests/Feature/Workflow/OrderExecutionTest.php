<?php

/**
 * Running a custom order on its pinned flow: M-06 stages, M-27 action results, pause/resume and finish (M-04), rework
 * and closing correction, need changes and edits of upcoming work (WF-05..12, P01).
 */

use App\Actions\StateMachines\M04CustomOrder\T01PauseOrder;
use App\Actions\StateMachines\M04CustomOrder\T02ResumeOrder;
use App\Actions\StateMachines\M04CustomOrder\T03FinishExecution;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M27ActionResult\T02RequestResult;
use App\Actions\StateMachines\M27ActionResult\T04AllowProceed;
use App\Actions\StateMachines\M27ActionResult\T05ReworkAction;
use App\Actions\StateMachines\M27ActionResult\T08MarkNotRequired;
use App\Actions\Workflow\EditUpcomingWork;
use App\Actions\Workflow\RecordMeasurement;
use App\Enums\ActionStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Flow;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\OrderWorkflow;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\UserNotification;
use App\Support\StaleRecord;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use App\Workflow\FlowCheckFailed;
use App\Workflow\OrderDefinition;
use App\Workflow\RequirementsOpen;
use App\Workflow\StageEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/WorkflowTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->f = workflowFixture();
    $this->order = $this->f->order;
    $this->owner = $this->f->org->owner;
});

it('opens a custom order on stage 1 with its work created once and needs applied', function () {
    $stages = OrderStage::query()->where('order_id', $this->order->id)->orderBy('position')->get();

    expect($stages->map(fn ($s) => $s->status)->all())->toBe([
        StageStatus::InProgress, StageStatus::NotStarted, StageStatus::NotStarted, StageStatus::NotStarted, StageStatus::NotStarted,
    ])
        ->and(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'))->toBe($stages[0]->id)
        ->and(statusOf(actionOf($this->order, 'needs')))->toBe(ActionStatus::Ready)
        ->and(statusOf(actionOf($this->order, 'measure')))->toBe(ActionStatus::Ready)
        ->and(statusOf(actionOf($this->order, 'design')))->toBe(ActionStatus::NotRequired)        // needs_design = false
        ->and(statusOf(actionOf($this->order, 'design_approval')))->toBe(ActionStatus::NotRequired)
        ->and(statusOf(actionOf($this->order, 'quote')))->toBe(ActionStatus::Unavailable)
        ->and(Task::query()->where('order_id', $this->order->id)->count())->toBe(3)
        // Routed to the order's responsible person (sales of A), eligible → assigned, with a notification.
        ->and(taskOf($this->order, 'needs')->assignee_id)->toBe($this->f->sales->id)
        ->and(taskOf($this->order, 'needs')->status)->toBe(TaskStatus::Assigned)
        ->and(UserNotification::query()->where('user_id', $this->f->sales->id)->where('type', 'task.assigned')->count())->toBe(3)
        ->and(StateTransition::query()->where('machine', 'M-06')->where('transition', 'T01')->count())->toBe(1);

    // Refreshing again creates nothing new (WF-06 «دون تكرار»).
    app(StageEngine::class)->refresh($this->order->refresh(), null);
    expect(Task::query()->where('order_id', $this->order->id)->count())->toBe(3);
});

it('M-06-T04: refuses completing with required work open, completes once it is verified, then opens the next stage', function () {
    $stage = stageOf($this->order, 'measure');

    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->owner, $stage), RequirementsOpen::class, 'requirements_open');
    try {
        app(T04CompleteStage::class)->handle($this->owner, $stage);
    } catch (RequirementsOpen $e) {
        expect($e->open)->toBe(['needs', 'measure']);
    }

    // The measurement task needs a FINAL measurement; an estimated one does not satisfy it.
    app(RecordMeasurement::class)->handle($this->owner, $this->order, [
        'location' => 'جدار', 'name' => 'العرض', 'value' => '3', 'unit' => 'm', 'kind' => 'estimated', 'measured_on' => now()->toDateString(),
    ]);
    expect(fn () => app(T05CompleteTask::class)->handle($this->f->sales, taskOf($this->order, 'measure')))->toThrow(ValidationException::class);
    finalMeasurement($this->f->sales, $this->order);

    app(T05CompleteTask::class)->handle($this->f->sales, taskOf($this->order, 'needs'));
    app(T05CompleteTask::class)->handle($this->f->sales, taskOf($this->order, 'measure'));
    expect(statusOf(actionOf($this->order, 'measure')))->toBe(ActionStatus::Verified);

    // The optional site photos stay open under follow-up and do not block.
    app(T04CompleteStage::class)->handle($this->owner, $stage->refresh());
    expect($stage->refresh()->status)->toBe(StageStatus::Completed)
        ->and(stageOf($this->order, 'design')->status)->toBe(StageStatus::InProgress)
        ->and(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'))->toBe(stageOf($this->order, 'design')->id)
        ->and(taskOf($this->order, 'site_photos')->status)->toBe(TaskStatus::Assigned)
        ->and(statusOf(actionOf($this->order, 'quote')))->toBe(ActionStatus::Ready);

    // T04 again, or on a stage that is not the active one, is refused without effect.
    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->owner, $stage->refresh()), condition: 'not_from_completed');
    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->owner, stageOf($this->order, 'manufacture')), condition: 'not_from_not_started');
});

it('M-06-T01: keeps a single active main stage', function () {
    expectRefusedWithoutEffect(fn () => app(StageEngine::class)->startStage($this->order, stageOf($this->order, 'design'), null, 'web'), condition: 'another_stage_active');
    expectRefusedWithoutEffect(fn () => app(StageEngine::class)->startStage($this->order, stageOf($this->order, 'measure'), null, 'web'), condition: 'not_from_in_progress');
});

it('M-27 T02/T03 and M-06 T02/T03: waiting for a result, verified from its source once', function () {
    finishActiveStage($this->owner, $this->order);
    $quote = actionOf($this->order, 'quote');
    $stage = stageOf($this->order, 'design');

    app(T02RequestResult::class)->handle($this->f->sales, $quote, 'بانتظار إعداد العرض في الاتفاق');
    expect(statusOf($quote))->toBe(ActionStatus::AwaitingResult)
        ->and($stage->refresh()->status)->toBe(StageStatus::Waiting);
    expectRefusedWithoutEffect(fn () => app(T02RequestResult::class)->handle($this->f->sales, $quote, 'again'), condition: 'not_from_awaiting_result');

    $results = app(ActionResults::class);
    $results->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'agreement_document', 41);
    expect(statusOf($quote))->toBe(ActionStatus::Verified)
        ->and($stage->refresh()->status)->toBe(StageStatus::InProgress)
        ->and($quote->refresh()->source_reference)->toBe(['type' => 'agreement_document', 'id' => 41]);

    // A repeated signal gives the known result, nothing new (WF-05).
    $count = StateTransition::query()->count();
    $results->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'agreement_document', 41);
    expect(StateTransition::query()->count())->toBe($count);

    // A task never verifies a source action, and an unknown purpose matches nothing.
    expect($results->verifyFromSource($this->order, ActionType::PaymentCheck, 'deposit', 'receipt', 1))->toBeNull()
        ->and(fn () => $results->verifyFromSource($this->order, ActionType::WorkTask, null, 'x', 1))->toThrow(LogicException::class);
});

it('applies a result recorded before its stage opens (early collection)', function () {
    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PaymentCheck, 'first', 'receipt', 7);
    $payment = actionOf($this->order, 'first_payment');
    expect($payment->status)->toBe(ActionStatus::Unavailable)->and($payment->source_reference)->toBe(['type' => 'receipt', 'id' => 7]);

    finishActiveStage($this->owner, $this->order); // measure
    finishActiveStage($this->owner, $this->order); // design
    app(ActionResults::class)->verifyFromSource($this->order, ActionType::CustomerApproval, 'quote', 'approval', 3);

    expect(statusOf($payment))->toBe(ActionStatus::Verified);
});

it('M-27-T04: allowance lets the stage proceed without changing the truth; only P-35 holders (owner/manager)', function () {
    finishActiveStage($this->owner, $this->order);
    $quote = actionOf($this->order, 'quote');
    $supervisor = $this->f->org->member('supervisor', [$this->f->org->a], $this->f->org->d1, 'branches');

    expectRefusedWithoutEffect(fn () => app(T04AllowProceed::class)->handle($this->f->sales, $quote, 'سماح'), AuthorizationException::class);
    expectRefusedWithoutEffect(fn () => app(T04AllowProceed::class)->handle($supervisor, $quote, 'سماح'), AuthorizationException::class);
    expect(fn () => app(T04AllowProceed::class)->handle($this->owner, $quote, ' '))->toThrow(ValidationException::class);

    app(T04AllowProceed::class)->handle($this->owner, $quote, 'العرض مرسل ورقيًا');
    expect(statusOf($quote))->toBe(ActionStatus::Allowed)
        ->and($quote->refresh()->source_reference)->toBeNull()
        ->and($quote->allowed_reason)->toBe('العرض مرسل ورقيًا');
    expectRefusedWithoutEffect(fn () => app(T04AllowProceed::class)->handle($this->owner, $quote, 'again'), condition: 'not_from_allowed');

    // Optional work is never «allowed»: it does not block.
    expectRefusedWithoutEffect(fn () => app(T04AllowProceed::class)->handle($this->owner, actionOf($this->order, 'site_photos'), 'x'), condition: 'optional');

    // Supervisor (Q-30 a) completes the stage within scope (branches here); the truth arrives later and is kept.
    app(T04CompleteStage::class)->handle($supervisor, stageOf($this->order, 'design'));
    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'agreement_document', 5);
    expect(statusOf($quote))->toBe(ActionStatus::Allowed)->and($quote->refresh()->source_reference)->toBe(['type' => 'agreement_document', 'id' => 5]);
});

it('M-04 T01/T02: pause keeps everything and blocks work; resume re-checks without resetting', function () {
    $task = taskOf($this->order, 'needs');
    $sales = $this->f->sales;

    expect(fn () => app(T01PauseOrder::class)->handle($this->owner, $this->order, '', $sales->id))->toThrow(ValidationException::class);
    expect(fn () => app(T01PauseOrder::class)->handle($this->owner, $this->order, 'العميل مسافر', $this->f->org->member('sales', [$this->f->org->c])->id))->toThrow(ValidationException::class);
    expectRefusedWithoutEffect(fn () => app(T02ResumeOrder::class)->handle($this->owner, $this->order), condition: 'not_from_in_progress');

    app(T01PauseOrder::class)->handle($this->owner, $this->order, 'العميل مسافر', $sales->id, now()->addWeek());
    expect($this->order->refresh()->status)->toBe('paused')
        ->and(OrderWorkflow::query()->where('order_id', $this->order->id)->value('pause_reason'))->toBe('العميل مسافر');
    expectRefusedWithoutEffect(fn () => app(T01PauseOrder::class)->handle($this->owner, $this->order, 'x', $sales->id), condition: 'not_from_paused');

    // Work waits while paused; the result recorded meanwhile is kept.
    expectRefusedWithoutEffect(fn () => app(T02StartTask::class)->handle($sales, $task), condition: 'order_paused');
    expectRefusedWithoutEffect(fn () => app(T05CompleteTask::class)->handle($sales, $task), condition: 'order_paused');
    expectRefusedWithoutEffect(fn () => app(T04CompleteStage::class)->handle($this->owner, stageOf($this->order, 'measure')), condition: 'order_paused');
    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PaymentCheck, 'first', 'receipt', 9);

    $startedAt = stageOf($this->order, 'measure')->started_at;
    app(T02ResumeOrder::class)->handle($this->owner, $this->order, 'عاد العميل');
    expect($this->order->refresh()->status)->toBe('in_progress')
        ->and(stageOf($this->order, 'measure')->started_at->equalTo($startedAt))->toBeTrue()
        ->and(actionOf($this->order, 'first_payment')->source_reference)->toBe(['type' => 'receipt', 'id' => 9]);
    app(T05CompleteTask::class)->handle($sales, $task);
    expect(statusOf(actionOf($this->order, 'needs')))->toBe(ActionStatus::Verified);
});

it('M-04-T03: finishing the last stage finishes execution; a closing correction reopens it (M-04-T08)', function () {
    foreach (range(1, 5) as $i) {
        finishActiveStage($this->owner, $this->order);
    }
    expect($this->order->refresh()->status)->toBe(CustomOrderStatus::ExecutionFinished->value)
        ->and(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'))->toBeNull();
    expectRefusedWithoutEffect(fn () => app(T03FinishExecution::class)->handle($this->owner, $this->order), condition: 'not_from_execution_finished');

    // Rework of the installation after the finish: the order runs again, the stage carries a correction marker and
    // no second main stage becomes active.
    $installation = actionOf($this->order, 'installation');
    $done = taskOf($this->order, 'installation');
    app(T05ReworkAction::class)->handle($this->owner, $installation, 'خلل في التركيب بعد التسليم');

    $install = stageOf($this->order, 'install');
    $correction = taskOf($this->order, 'installation');
    expect($this->order->refresh()->status)->toBe('in_progress')
        ->and($install->status)->toBe(StageStatus::CompletedNeedsCorrection)
        ->and(OrderWorkflow::query()->where('order_id', $this->order->id)->value('active_stage_id'))->toBeNull()
        ->and($correction->kind)->toBe(Task::KIND_CORRECTION)
        ->and($correction->corrects_task_id)->toBe($done->id)
        ->and($done->refresh()->status)->toBe(TaskStatus::Completed)
        ->and(statusOf($installation))->toBe(ActionStatus::Ready)
        ->and(StateTransition::query()->where('machine', 'M-04')->orderByDesc('id')->value('transition'))->toBe('T08')
        ->and(StateTransition::query()->where('machine', 'M-27')->where('subject_id', $installation->id)->orderByDesc('id')->limit(2)->pluck('transition')->all())->toBe(['T06', 'T05']);

    // Finishing waits for the correction, then the marker is removed and execution can finish again.
    expectRefusedWithoutEffect(fn () => app(T03FinishExecution::class)->handle($this->owner, $this->order), condition: 'work_pending');
    app(T05CompleteTask::class)->handle($this->owner, $correction);
    expect($install->refresh()->status)->toBe(StageStatus::Completed);
    app(T03FinishExecution::class)->handle($this->owner, $this->order);
    expect($this->order->refresh()->status)->toBe('execution_finished');
});

it('M-27-T05/T06 in the active stage: reopens the same task (M-07-T08) and holds only dependent work', function () {
    finishActiveStage($this->owner, $this->order); // measure
    finishActiveStage($this->owner, $this->order); // design
    finishActiveStage($this->owner, $this->order); // agreement
    $manufacturing = actionOf($this->order, 'manufacturing');
    $task = taskOf($this->order, 'manufacturing');
    app(T05CompleteTask::class)->handle($this->f->sales, $task);
    $quality = actionOf($this->order, 'quality_check');
    expect(statusOf($quality))->toBe(ActionStatus::Ready);

    expectRefusedWithoutEffect(fn () => app(T05ReworkAction::class)->handle($this->owner, $quality, 'x'), condition: 'not_from_ready');
    expectRefusedWithoutEffect(fn () => app(T05ReworkAction::class)->handle($this->f->sales, $manufacturing, 'x'), AuthorizationException::class);

    app(T05ReworkAction::class)->handle($this->owner, $manufacturing, 'المقاسات خاطئة');
    expect($task->refresh()->status)->toBe(TaskStatus::Assigned)
        ->and(Task::query()->where('order_action_id', $manufacturing->id)->count())->toBe(1)
        ->and(statusOf($manufacturing))->toBe(ActionStatus::Ready)
        ->and($manufacturing->rework_count)->toBe(1)
        ->and($quality->refresh()->held_by_action_id)->toBe($manufacturing->id)
        // Earlier results are not reopened.
        ->and(statusOf(actionOf($this->order, 'first_payment')))->toBe(ActionStatus::Verified);

    // The held quality check cannot complete, and the stage cannot complete, until the rework is verified.
    expectRefusedWithoutEffect(fn () => app(T05CompleteTask::class)->handle($this->f->sales, taskOf($this->order, 'quality_check')), condition: 'held_by_rework');
    expect(fn () => app(T04CompleteStage::class)->handle($this->owner, stageOf($this->order, 'manufacture')))->toThrow(RequirementsOpen::class);

    app(T05CompleteTask::class)->handle($this->f->sales, $task);
    expect($quality->refresh()->held_by_action_id)->toBeNull();
    app(T05CompleteTask::class)->handle($this->f->sales, taskOf($this->order, 'quality_check'));
    app(T04CompleteStage::class)->handle($this->owner, stageOf($this->order, 'manufacture'));
    expect(stageOf($this->order, 'install')->status)->toBe(StageStatus::InProgress);
});

it('reworks a source-verified action without reusing the old result', function () {
    finishActiveStage($this->owner, $this->order);
    finishActiveStage($this->owner, $this->order);
    $quote = actionOf($this->order, 'quote');
    $approval = actionOf($this->order, 'quote_approval');
    expect(statusOf($approval))->toBe(ActionStatus::Ready);

    app(T05ReworkAction::class)->handle($this->owner, $quote, 'تغيير البنود');
    expect(stageOf($this->order, 'design')->status)->toBe(StageStatus::CompletedNeedsCorrection)
        ->and(statusOf($quote))->toBe(ActionStatus::Ready)
        ->and($quote->refresh()->source_reference)->toBeNull()
        ->and($approval->refresh()->held_by_action_id)->toBe($quote->id);

    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PrepareDocument, 'quote', 'agreement_document', 77);
    expect(stageOf($this->order, 'design')->status)->toBe(StageStatus::Completed)
        ->and($approval->refresh()->held_by_action_id)->toBeNull();
});

it('M-27 T07/T08: need changes apply only through the order or P-28, never silently', function () {
    finishActiveStage($this->owner, $this->order);
    $design = actionOf($this->order, 'design');
    expect($design->status)->toBe(ActionStatus::NotRequired);

    // T08 refused while the need still applies, and for a need that no longer applies after work started.
    expectRefusedWithoutEffect(fn () => app(T08MarkNotRequired::class)->handle($this->owner, actionOf($this->order, 'quote'), 'x'), condition: 'need_applies');

    // P-17 sets needs_design → T07 → ready in the active stage with its task.
    $this->actingAs($this->f->sales)->putJson("/orders/{$this->order->id}", ['lock_version' => $this->order->refresh()->lock_version, 'needs_design' => true])->assertOk();
    expect(statusOf($design))->toBe(ActionStatus::Ready)
        ->and(Task::query()->where('order_action_id', $design->id)->count())->toBe(1)
        ->and(statusOf(actionOf($this->order, 'design_approval')))->toBe(ActionStatus::Unavailable);

    // Need removed again: nothing changes by itself; P-28 marks it not required and cancels the unstarted task.
    $this->actingAs($this->f->sales)->putJson("/orders/{$this->order->id}", ['lock_version' => $this->order->refresh()->lock_version, 'needs_design' => false])->assertOk();
    expect(statusOf($design))->toBe(ActionStatus::Ready);
    expectRefusedWithoutEffect(fn () => app(T08MarkNotRequired::class)->handle($this->f->sales, $design, 'x'), AuthorizationException::class);
    app(T08MarkNotRequired::class)->handle($this->owner, $design, 'العميل لا يحتاج تصميمًا');
    expect(statusOf($design))->toBe(ActionStatus::NotRequired)
        ->and(taskOf($this->order, 'design')->status)->toBe(TaskStatus::Cancelled);
    expectRefusedWithoutEffect(fn () => app(T08MarkNotRequired::class)->handle($this->owner, $design, 'x'), condition: 'not_from_not_required');
});

it('P-28: edits only upcoming, not-started work of this order, re-checked and with a reason', function () {
    finishActiveStage($this->owner, $this->order); // measure done, design active
    $definition = OrderDefinition::fixedStages($this->order);
    $upcoming = array_slice($definition, 2); // agreement, manufacture, install
    $upcoming[1]['actions'][] = ['key' => 'paint_check', 'type' => 'work_task', 'name' => ['ar' => 'فحص الدهان'], 'inputs' => ['label' => ['ar' => 'فحص'], 'requires_attachment' => true]];
    $version = $this->order->refresh()->lock_version;

    expect(fn () => app(EditUpcomingWork::class)->handle($this->owner, $this->order, $upcoming, '', $version))->toThrow(ValidationException::class);
    expectRefusedWithoutEffect(fn () => app(EditUpcomingWork::class)->handle($this->f->sales, $this->order, $upcoming, 'x', $version), AuthorizationException::class);

    // Touching a started stage (here: re-sending «design») is a duplicate of an existing key → refused by FlowCheck.
    expectRefusedWithoutEffect(fn () => app(EditUpcomingWork::class)->handle($this->owner, $this->order, array_slice($definition, 1), 'x', $version), FlowCheckFailed::class);
    // Removing the quote approval would leave the percentage without agreement → refused.
    $broken = $upcoming;
    array_shift($broken[0]['actions']);
    expectRefusedWithoutEffect(fn () => app(EditUpcomingWork::class)->handle($this->owner, $this->order, $broken, 'x', $version), FlowCheckFailed::class);

    app(EditUpcomingWork::class)->handle($this->owner, $this->order, $upcoming, 'فحص إضافي لهذا العميل', $version);
    expect(OrderAction::query()->where('order_id', $this->order->id)->where('key', 'paint_check')->exists())->toBeTrue()
        ->and(stageOf($this->order, 'design')->status)->toBe(StageStatus::InProgress)
        ->and(OrderStage::query()->where('order_id', $this->order->id)->orderBy('position')->pluck('key')->all())->toBe(['measure', 'design', 'agreement', 'manufacture', 'install'])
        // The facility flow is untouched.
        ->and(collect(Flow::current()->publishedVersion->definition['stages'][3]['actions'])->pluck('key')->all())->not->toContain('paint_check');

    // A stale version is refused.
    expectRefusedWithoutEffect(fn () => app(EditUpcomingWork::class)->handle($this->owner, $this->order, $upcoming, 'x', $version), StaleRecord::class);
});

it('keeps a result recorded early when its upcoming stage is edited, and refuses dropping it', function () {
    app(ActionResults::class)->verifyFromSource($this->order, ActionType::PaymentCheck, 'first', 'receipt', 12);
    $upcoming = array_slice(OrderDefinition::fixedStages($this->order), 1);
    $version = $this->order->refresh()->lock_version;

    $dropped = $upcoming;
    array_pop($dropped[1]['actions']); // first_payment
    expectRefusedWithoutEffect(fn () => app(EditUpcomingWork::class)->handle($this->owner, $this->order, $dropped, 'x', $version), condition: 'has_reference');

    $upcoming[0]['target_days'] = 9;
    app(EditUpcomingWork::class)->handle($this->owner, $this->order, $upcoming, 'تمديد', $version);
    expect(actionOf($this->order, 'first_payment')->source_reference)->toBe(['type' => 'receipt', 'id' => 12])
        ->and(stageOf($this->order, 'design')->target_days)->toBe(9);
});

it('does not run a workflow for ready orders', function () {
    $this->actingAs($this->f->sales)->postJson('/orders', readyPayload($this->f->inA, CatalogReadyVariant::query()->firstOrFail()))->assertCreated();
    $ready = Order::query()->latest('id')->first();

    expect(OrderWorkflow::query()->where('order_id', $ready->id)->exists())->toBeFalse()
        ->and(fn () => app(T01PauseOrder::class)->handle($this->owner, $ready, 'x', $this->f->sales->id))->toThrow(LogicException::class);
});
