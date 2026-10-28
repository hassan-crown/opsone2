<?php

namespace App\Http\Controllers\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Inventory\ManageStockSetup;
use App\Customers\CustomerPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockLocationRequest;
use App\Inventory\StockAccess;
use App\Inventory\StockPresenter;
use App\Models\Branch;
use App\Models\Inventory\StockLocation;
use App\Organization\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * StockLocations (FUL-01): simple locations with an optional branch; managed with P-49 over the location's branch.
 */
class StockLocationController extends Controller
{
    use StockScreens;

    public function index(Request $request): Response|JsonResponse
    {
        $actor = $this->actor($request);
        abort_unless(StockAccess::any($actor), 403);
        $locations = StockAccess::locations($actor, Permission::P48, Permission::P49)->with(['branch', 'manager'])->orderByDesc('is_default')->orderBy('code')->get();

        return $this->screen($request, 'stock/locations', [
            'locations' => $locations->map(fn (StockLocation $l) => StockPresenter::location($l, $actor))->all(),
            'branches' => Branch::query()->where('status', 'active')->orderBy('id')->get()
                ->filter(fn (Branch $b) => Access::can($actor, Permission::P49, new StockLocation(['branch_id' => $b->id])))
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->values()->all(),
            'members' => CustomerPresenter::memberOptions(),
            'can' => [
                'create' => Access::can($actor, Permission::P49),
                'facility_wide' => Access::can($actor, Permission::P49, new StockLocation(['branch_id' => null])),
            ],
        ]);
    }

    public function store(StockLocationRequest $request, ManageStockSetup $setup): JsonResponse
    {
        $location = $setup->saveLocation($this->actor($request), null, $request->locationData());

        return response()->json(['location' => StockPresenter::location($location->load(['branch', 'manager']))], 201);
    }

    public function update(StockLocationRequest $request, StockLocation $location, ManageStockSetup $setup): JsonResponse
    {
        $location = $setup->saveLocation($this->actor($request), $location, $request->locationData(), $request->filled('lock_version') ? $request->integer('lock_version') : null);

        return response()->json(['location' => StockPresenter::location($location->load(['branch', 'manager']))]);
    }
}
