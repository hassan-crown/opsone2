<?php

namespace App\Http\Controllers\Tasks;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\OrganizationUnitStatus;
use App\Files\AttachmentService;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Tasks\TaskPresenter;
use App\Tasks\TaskViews;
use App\Workflow\TaskQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Task screens (M-07): `TaskList`, `TaskPage`/`TaskMobile`, `TaskAssign` data and `ExecHomeMobile`. Lists come from
 * TaskQueries (P-24 per row); the page authorizes on the task. Contract: docs/p5-engine-api.md and docs/p5-tasks-api.md.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse|Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P24) || Access::can($actor, Permission::P22), 403);

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(TaskViews::VIEWS)],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['unassigned', 'assigned', 'in_progress', 'waiting', 'completed'])],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $view = $filters['view'] ?? 'all';

        $sets = TaskViews::load($actor);
        $rows = TaskViews::pick($sets, $view)
            ->filter(fn (Task $t) => ! filled($filters['status'] ?? null) || $t->status->value === $filters['status'])
            ->filter(fn (Task $t) => ! ($filters['overdue'] ?? false) || ($t->due_at !== null && $t->due_at->isPast() && $t->status->isOpen()))
            ->filter(function (Task $t) use ($filters) {
                if (! filled($filters['q'] ?? null)) {
                    return true;
                }
                $needle = mb_strtolower((string) $filters['q']);

                return str_contains(mb_strtolower((string) $t->title->get()), $needle)
                    || str_contains(mb_strtolower($t->order->number), $needle)
                    || str_contains(mb_strtolower((string) $t->order->customer->name), $needle);
            })
            ->map(fn (Task $t) => TaskPresenter::row($t, $actor))->values()->all();

        $payload = [
            'tasks' => $rows,
            'counts' => TaskViews::counts($sets),
            'limit' => TaskQueries::LIMIT,
        ];

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('tasks/index', $payload + [
            'view' => $view,
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? '', 'overdue' => (bool) ($filters['overdue'] ?? false)],
        ]);
    }

    /**
     * `ExecHomeMobile`: today and upcoming work, mobile first.
     */
    public function today(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P24), 403);

        return Inertia::render('tasks/today', TaskViews::execHome($actor));
    }

    public function show(Request $request, Task $task, AttachmentService $files): JsonResponse|Response
    {
        $actor = $this->actor($request);
        Access::authorize($actor, Permission::P24, $task);

        $detail = TaskPresenter::detail($task, $actor, $files);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['task' => $detail]);
        }

        return Inertia::render('tasks/show', ['task' => $detail]);
    }

    /**
     * `TaskAssign`: who may take the work, with their open work count (WF-07 — no automatic suggestion).
     */
    public function assign(Request $request, Task $task): Response
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P22, $task) || Access::can($actor, Permission::P23, $task), 403);

        return Inertia::render('tasks/assign', [
            'task' => TaskPresenter::row($task, $actor),
            'eligible' => TaskQueries::eligibleFor($actor, $task),
            'departments' => array_values(Department::query()->where('status', OrganizationUnitStatus::Active)->orderBy('id')->get()
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => OrgPresenter::name($d)])->all()),
            'mode' => $task->assignee_id === null ? 'assign' : 'reassign',
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
