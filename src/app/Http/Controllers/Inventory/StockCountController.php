<?php

namespace App\Http\Controllers\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Inventory\StockCounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockDecisionRequest;
use App\Http\Requests\Inventory\StockLinesRequest;
use App\Inventory\StockAccess;
use App\Inventory\StockPresenter;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * StockCount (FUL-04): count, submit, then approval by a P-49 holder posts the differences as adjustments.
 */
class StockCountController extends Controller
{
    use StockScreens;

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(StockAccess::any($actor), 403);

        return $this->screen($request, 'stock/counts', StockPresenter::counts($actor));
    }

    public function show(Request $request, StockCount $count): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $count->load(['location', 'creator:id,name', 'decider:id,name']);
        abort_unless(StockAccess::canSeeHistory($actor, $count->location) || Access::can($actor, Permission::P49, $count->location), 403);

        return $this->screen($request, 'stock/count', ['count' => StockPresenter::count($count, $actor), 'items' => StockPresenter::trackable()]);
    }

    public function store(StockLinesRequest $request, StockCounts $counts): JsonResponse
    {
        $location = StockLocation::query()->findOrFail($request->integer('location_id'));
        $count = $counts->create($this->actor($request), $location, (string) $request->validated('reason'), $this->lines($request));

        return response()->json(['count' => ['id' => $count->id, 'number' => $count->number]], 201);
    }

    public function update(StockLinesRequest $request, StockCount $count, StockCounts $counts): JsonResponse
    {
        $counts->update($this->actor($request), $count, $this->lines($request), $request->filled('lock_version') ? $request->integer('lock_version') : null);

        return $this->done($request, $count);
    }

    public function submit(StockDecisionRequest $request, StockCount $count, StockCounts $counts): JsonResponse
    {
        $counts->submit($this->actor($request), $count, $request->version());

        return $this->done($request, $count);
    }

    public function approve(StockDecisionRequest $request, StockCount $count, StockCounts $counts): JsonResponse
    {
        $counts->approve($this->actor($request), $count, $request->version(), $request->filled('note') ? (string) $request->validated('note') : null);

        return $this->done($request, $count);
    }

    public function reject(StockDecisionRequest $request, StockCount $count, StockCounts $counts): JsonResponse
    {
        $counts->reject($this->actor($request), $count, $request->version(), (string) $request->validated('note'));

        return $this->done($request, $count);
    }

    /**
     * @return list<array{item_type: string, item_id: int, counted_milli: int}>
     */
    private function lines(StockLinesRequest $request): array
    {
        return array_map(fn (array $l) => ['item_type' => $l['item_type'], 'item_id' => $l['item_id'], 'counted_milli' => $l['counted_milli']], $request->lines());
    }

    private function done(Request $request, StockCount $count): JsonResponse
    {
        $count = $count->refresh()->load(['location', 'creator:id,name', 'decider:id,name']);

        return response()->json(['count' => StockPresenter::count($count, $this->actor($request))]);
    }
}
