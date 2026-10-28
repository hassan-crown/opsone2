<?php

namespace App\Http\Controllers\Flow;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M04CustomOrder\T01PauseOrder;
use App\Actions\StateMachines\M04CustomOrder\T02ResumeOrder;
use App\Actions\StateMachines\M04CustomOrder\T03FinishExecution;
use App\Actions\StateMachines\M06Stage\T04CompleteStage;
use App\Actions\StateMachines\M27ActionResult\T04AllowProceed;
use App\Actions\StateMachines\M27ActionResult\T05ReworkAction;
use App\Actions\Workflow\EditUpcomingWork;
use App\Customers\CustomerPresenter;
use App\Enums\StageStatus;
use App\Flow\FlowPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\OrderProgressRequest;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Workflow\OrderDefinition;
use App\Workflow\WorkflowPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order progress screens (P5): `OrderStages`, `OrderCompleteStage`, `OrderPause`, `OrderRework` and
 * `OrderEditUpcoming`. JSON endpoints used by the order page; reading needs P-16 on the order and every write is
 * authorized on the record by its Action (P-25/P-26/P-27/P-28/P-35). Contract: docs/p5-engine-api.md.
 */
class OrderProgressController extends Controller
{
    public function show(Request $request, Order $order): JsonResponse
    {
        $actor = $this->viewer($request, $order);

        return $this->progress($order, $actor);
    }

    public function completeStage(OrderProgressRequest $request, Order $order, int $stage, T04CompleteStage $complete): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $complete->handle($actor, $this->stageOf($order, $stage), (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    public function allow(OrderProgressRequest $request, Order $order, int $action, T04AllowProceed $allow): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $allow->handle($actor, $this->actionOf($order, $action), (string) $request->validated('reason'), (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    public function rework(OrderProgressRequest $request, Order $order, int $action, T05ReworkAction $rework): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $rework->handle($actor, $this->actionOf($order, $action), (string) $request->validated('reason'), (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    public function pause(OrderProgressRequest $request, Order $order, T01PauseOrder $pause): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $review = $request->validated('review_on');
        $pause->handle(
            $actor,
            $order,
            (string) $request->validated('reason'),
            (int) $request->validated('follow_up_user_id'),
            $review === null ? null : CarbonImmutable::parse((string) $review),
            (int) $request->validated('lock_version'),
        );

        return $this->progress($order, $actor);
    }

    public function resume(OrderProgressRequest $request, Order $order, T02ResumeOrder $resume): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $resume->handle($actor, $order, $request->validated('note'), (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    public function finishExecution(OrderProgressRequest $request, Order $order, T03FinishExecution $finish): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $finish->handle($actor, $order, (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    /**
     * The not-started stages of this order in the editor's definition shape, with the catalogue to choose from.
     */
    public function upcoming(Request $request, Order $order): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        abort_unless(Access::can($actor, Permission::P28, $order), 403);

        $stages = OrderStage::query()->where('order_id', $order->id)->where('status', StageStatus::NotStarted->value)->orderBy('position')->get();

        return response()->json([
            'stages' => array_values($stages->map(fn (OrderStage $s) => OrderDefinition::stage($s))->all()),
            'options' => FlowPresenter::options(),
        ]);
    }

    public function editUpcoming(OrderProgressRequest $request, Order $order, EditUpcomingWork $edit): JsonResponse
    {
        $actor = $this->viewer($request, $order);
        $edit->handle($actor, $order, $request->stages(), (string) $request->validated('reason'), (int) $request->validated('lock_version'));

        return $this->progress($order, $actor);
    }

    private function progress(Order $order, User $actor): JsonResponse
    {
        return response()->json([
            'progress' => WorkflowPresenter::order($order->refresh(), $actor),
            'members' => CustomerPresenter::memberOptions(),
        ]);
    }

    private function viewer(Request $request, Order $order): User
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(OrderAccess::canView($actor, $order), 403);

        return $actor;
    }

    private function stageOf(Order $order, int $id): OrderStage
    {
        return OrderStage::query()->where('order_id', $order->id)->findOrFail($id);
    }

    private function actionOf(Order $order, int $id): OrderAction
    {
        return OrderAction::query()->where('order_id', $order->id)->findOrFail($id);
    }
}
