<?php

namespace App\Http\Controllers\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Finance\CollectionReminders;
use App\Finance\FinanceWorkList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReminderRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Finance: follow-up» (FinHome, FIN-11) and the bulk reminder (FinReminder). Needs P-37 in some scope; every row is
 * narrowed to the orders the viewer may see the money of.
 */
class FinanceHomeController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P37), 403);
        $list = FinanceWorkList::for($actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($list);
        }

        return Inertia::render('finance/index', [...$list, 'can_report' => Access::can($actor, Permission::P60), 'can_invoices' => Access::can($actor, Permission::P44)]);
    }

    public function previewReminders(ReminderRequest $request, CollectionReminders $reminders): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P37), 403);

        return response()->json(['recipients' => $reminders->preview($actor, $request->dueIds())]);
    }

    public function sendReminders(ReminderRequest $request, CollectionReminders $reminders): JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P37), 403);

        return response()->json(['sent' => $reminders->send($actor, $request->dueIds())]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
