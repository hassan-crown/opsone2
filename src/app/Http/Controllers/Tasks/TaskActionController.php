<?php

namespace App\Http\Controllers\Tasks;

use App\Actions\StateMachines\M07Task\T01AssignTask;
use App\Actions\StateMachines\M07Task\T02StartTask;
use App\Actions\StateMachines\M07Task\T03WaitForNeed;
use App\Actions\StateMachines\M07Task\T04ResolveNeed;
use App\Actions\StateMachines\M07Task\T05CompleteTask;
use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M07Task\T07CancelTask;
use App\Actions\StateMachines\M07Task\T10ClaimTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\AssignTaskRequest;
use App\Http\Requests\Tasks\CancelTaskRequest;
use App\Http\Requests\Tasks\CompleteTaskRequest;
use App\Http\Requests\Tasks\ReassignTaskRequest;
use App\Http\Requests\Tasks\TaskVersionRequest;
use App\Http\Requests\Tasks\WaitTaskRequest;
use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-07 transitions as JSON endpoints. Thin: each calls its Action, which authorizes on the task, locks the order,
 * re-reads the task and writes the change; refusals render as 403/409/422 (docs/p5-engine-api.md).
 */
class TaskActionController extends Controller
{
    public function claim(TaskVersionRequest $request, Task $task, T10ClaimTask $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task));
    }

    public function assign(AssignTaskRequest $request, Task $task, T01AssignTask $action): JsonResponse
    {
        $assignee = $request->filled('assignee_id') ? (int) $request->input('assignee_id') : null;
        $department = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        return $this->done($request, $action->handle($this->actor($request), $task, $assignee, $department, $request->lockVersion()));
    }

    public function reassign(ReassignTaskRequest $request, Task $task, T06ReassignTask $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, (int) $request->input('assignee_id'), (string) $request->input('reason'), $request->lockVersion()));
    }

    public function start(TaskVersionRequest $request, Task $task, T02StartTask $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, $request->lockVersion()));
    }

    public function wait(WaitTaskRequest $request, Task $task, T03WaitForNeed $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, (string) $request->input('need'),
            CarbonImmutable::parse((string) $request->input('follow_up_on')), $request->lockVersion()));
    }

    public function resolve(TaskVersionRequest $request, Task $task, T04ResolveNeed $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, null, $request->lockVersion()));
    }

    public function complete(CompleteTaskRequest $request, Task $task, T05CompleteTask $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, $request->filled('result_note') ? (string) $request->input('result_note') : null, $request->lockVersion()));
    }

    public function cancel(CancelTaskRequest $request, Task $task, T07CancelTask $action): JsonResponse
    {
        return $this->done($request, $action->handle($this->actor($request), $task, (string) $request->input('reason'), $request->lockVersion()));
    }

    private function done(Request $request, Task $task): JsonResponse
    {
        return response()->json(['task' => TaskPresenter::row($task->refresh(), $this->actor($request))]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
