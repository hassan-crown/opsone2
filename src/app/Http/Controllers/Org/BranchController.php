<?php

namespace App\Http\Controllers\Org;

use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Actions\Organization\CreateBranch;
use App\Actions\Organization\DisableBranch;
use App\Actions\Organization\ReactivateBranch;
use App\Actions\Organization\UpdateBranch;
use App\Enums\MembershipStatus;
use App\Http\Requests\Org\BranchRequest;
use App\Models\Branch;
use App\Models\Region;
use App\Organization\OrgPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches (ORG-02, P-03): list, create, edit, disable and reactivate within the plan's active-branch limit.
 */
class BranchController extends OrgController
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        // Listing survives read-only license phases; every change below still authorizes through the Gate.
        abort_unless($this->access->canRead($actor, Permission::P03), 403);

        $members = DB::table('branch_membership')
            ->join('memberships', 'memberships.id', '=', 'branch_membership.membership_id')
            ->where('memberships.status', MembershipStatus::Active->value)
            ->selectRaw('branch_membership.branch_id as branch_id, count(*) as total')
            ->groupBy('branch_membership.branch_id')->pluck('total', 'branch_id');

        $branches = Branch::query()->with('region')->orderBy('id')->get()
            ->filter(fn (Branch $b) => $this->access->canRead($actor, Permission::P03, $b))
            ->map(fn (Branch $b) => [
                'id' => $b->id,
                'name_ar' => $b->name_ar,
                'name_en' => $b->name_en,
                'name' => OrgPresenter::name($b),
                'code' => $b->code,
                'region_id' => $b->region_id,
                'region' => $b->region === null ? null : OrgPresenter::name($b->region),
                'status' => $b->status->value,
                'members' => (int) ($members[$b->id] ?? 0),
            ])->values()->all();

        return Inertia::render('org/branches', [
            'branches' => $branches,
            'regions' => Region::query()->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'name' => OrgPresenter::name($r)])->all(),
            'usage' => $this->usage('branches', app(LicenseLimits::class)->activeBranches()),
            'canCreate' => $this->access->can($actor, Permission::P03, new Branch),
            'canManage' => $this->access->can($actor, Permission::P03),
        ]);
    }

    public function store(BranchRequest $request, CreateBranch $create): RedirectResponse
    {
        $branch = $create->handle($this->actor($request), [
            'name_ar' => (string) $request->validated('name_ar'),
            'name_en' => $request->validated('name_en'),
            'code' => $request->validated('code'),
            'region_id' => $request->validated('region_id') === null ? null : (int) $request->validated('region_id'),
        ]);
        $this->toast(__('org.branches.created', ['name' => OrgPresenter::name($branch)]));

        return $request->validated('from') === 'wizard' ? back() : to_route('org.branches.index');
    }

    public function update(BranchRequest $request, Branch $branch, UpdateBranch $update): RedirectResponse
    {
        $update->handle($this->actor($request), $branch, $request->safe()->only(['name_ar', 'name_en', 'code']));
        $this->toast(__('org.branches.updated'));

        return $request->validated('from') === 'wizard' ? back() : to_route('org.branches.index');
    }

    public function disable(Request $request, Branch $branch, DisableBranch $disable): RedirectResponse
    {
        $disable->handle($this->actor($request), $branch);
        $this->toast(__('org.branches.disabled', ['name' => OrgPresenter::name($branch)]));

        return to_route('org.branches.index');
    }

    public function reactivate(Request $request, Branch $branch, ReactivateBranch $reactivate): RedirectResponse
    {
        $reactivate->handle($this->actor($request), $branch);
        $this->toast(__('org.branches.reactivated', ['name' => OrgPresenter::name($branch)]));

        return to_route('org.branches.index');
    }
}
