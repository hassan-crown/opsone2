<?php

namespace App\Http\Controllers\Tasks;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Workflow\RecordMeasurement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskMeasurementRequest;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Measurement entry for the executor (COM-07, Q-32): works through the assigned open measurement task, so the
 * executor needs no access to the order file. RecordMeasurement authorizes (P-14 or the task) and validates.
 */
class TaskMeasurementController extends Controller
{
    public function index(Request $request, Task $task): Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P24, $task);
        $task->loadMissing(['order', 'action']);

        return Inertia::render('tasks/measure', [
            'task' => TaskPresenter::row($task, $actor),
            'measurements' => TaskPresenter::measurements($task->order),
            'kind' => $task->action->inputs['measurement_kind'] ?? 'final',
            'units' => OrderMeasurement::UNITS,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(TaskMeasurementRequest $request, Task $task, RecordMeasurement $record): JsonResponse
    {
        $actor = $this->actor($request);
        $record->handle($actor, $task->order, $request->validated(), $task);

        return response()->json(['measurements' => TaskPresenter::measurements($task->order)], 201);
    }

    public function update(TaskMeasurementRequest $request, Task $task, OrderMeasurement $measurement, RecordMeasurement $record): JsonResponse
    {
        abort_unless($measurement->order_id === $task->order_id, 404);
        $record->handle($this->actor($request), $task->order, $request->validated(), $task, $measurement);

        return response()->json(['measurements' => TaskPresenter::measurements($task->order)]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
