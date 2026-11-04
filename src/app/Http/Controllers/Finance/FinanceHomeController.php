<?php

namespace App\Http\Controllers\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Finance\CollectionReminders;
use App\Finance\FinanceWorkList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReminderRequest;
use App\Models\User;
use App\Support\PropRows;
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
    private const PER_PAGE = 25;

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(Access::can($actor, Permission::P37), 403);
        $list = FinanceWorkList::for($actor);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($list);
        }

        // The page shows one tab at a time, a page of rows at a time; «all» leaves out unallocated money (settlement),
        // which has its own tab so the overdue rows are not buried under it. The JSON list above stays whole.
        $kind = in_array($request->query('kind'), FinanceWorkList::KINDS, true) ? (string) $request->query('kind') : 'all';
        $rows = array_values(array_filter($list['items'], fn (array $item) => $kind === 'all' ? $item['kind'] !== 'settlement' : $item['kind'] === $kind));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($lastPage, max(1, (int) $request->query('page', '1')));
        $offset = ($page - 1) * self::PER_PAGE;

        return Inertia::render('finance/index', [
            'items' => PropRows::of(array_slice($rows, $offset, self::PER_PAGE)),
            'counts' => $list['counts'],
            'kind' => $kind,
            'meta' => [
                'page' => $page, 'last_page' => $lastPage, 'total' => $total, 'per_page' => self::PER_PAGE,
                'from' => $total === 0 ? null : $offset + 1, 'to' => $total === 0 ? null : min($total, $offset + self::PER_PAGE),
            ],
            'can_report' => Access::can($actor, Permission::P60),
            'can_invoices' => Access::can($actor, Permission::P44),
        ]);
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
