<?php

namespace App\Http\Controllers;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M02Setup\SetupSteps;
use App\Enums\SetupStatus;
use App\Models\Facility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $facility = Facility::current();
        $showSetup = $facility !== null
            && $facility->setup_status === SetupStatus::Incomplete
            && Access::can($request->user(), Permission::P02, $facility);

        return Inertia::render('dashboard', [
            // HomeSetup checklist: shown to whoever may run the wizard until it is completed.
            'setup' => $showSetup ? [
                'steps' => SetupSteps::states($facility),
                'done' => SetupSteps::doneCount($facility),
                'total' => count(SetupSteps::ALL),
                'next' => SetupSteps::next($facility),
            ] : null,
        ]);
    }
}
