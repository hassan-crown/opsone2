<?php

namespace App\Http\Controllers;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M02Setup\SetupSteps;
use App\Enums\SetupStatus;
use App\Home\HomeWidgets;
use App\Models\Facility;
use App\Tasks\TaskViews;
use App\Workflow\TaskQueries;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, HomeWidgets $widgets): Response
    {
        $facility = Facility::current();
        $showSetup = $facility !== null
            && $facility->setup_status === SetupStatus::Incomplete
            && Access::can($request->user(), Permission::P02, $facility);

        // Both task blocks read the same two sets: once per request.
        $sets = null;
        $loaded = function () use (&$sets, $request): array {
            return $sets ??= ['mine' => TaskQueries::mine($request->user()), 'inbox' => TaskQueries::inbox($request->user())];
        };

        return Inertia::render('dashboard', [
            // HomeSetup checklist: shown to whoever may run the wizard until it is completed.
            'setup' => $showSetup ? [
                'steps' => SetupSteps::states($facility),
                'done' => SetupSteps::doneCount($facility),
                'total' => count(SetupSteps::ALL),
                'next' => SetupSteps::next($facility),
            ] : null,
            // Work-center frame (SHR-01): widgets this user may see, by section; domains fill them later.
            'widgets' => $widgets->visibleFor($request->user()),
            // Counts and next rows of the task cards, scoped by the same Gate checks as the lists (P5).
            // A pure executor's home is the mobile-first day view (ExecHomeMobile).
            'isExecutor' => fn () => $this->isExecutor($request),
            'exec' => fn () => $this->isExecutor($request) ? TaskViews::execHome($request->user(), $loaded()) : null,
            'taskCards' => fn () => TaskViews::homeCards($request->user(), $loaded()),
        ]);
    }

    private function isExecutor(Request $request): bool
    {
        $user = $request->user();

        return Access::can($user, Permission::P24) && ! Access::can($user, Permission::P16) && ! Access::can($user, Permission::P22);
    }
}
