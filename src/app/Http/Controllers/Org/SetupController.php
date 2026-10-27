<?php

namespace App\Http\Controllers\Org;

use App\Access\Exceptions\InvalidTransition;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Actions\Organization\UpdateFacilityProfile;
use App\Actions\StateMachines\M02Setup\SetupSteps;
use App\Actions\StateMachines\M02Setup\T01SaveSetupStep;
use App\Actions\StateMachines\M02Setup\T02CompleteSetup;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationUnitStatus;
use App\Enums\SetupStatus;
use App\Http\Requests\Org\SetupOrgRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\Region;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The setup wizard (M-02, ORG-08): five steps that can be saved, deferred and resumed at the first step not done.
 * Once completed the wizard is closed for good (M-02-T03); everything stays editable from the settings pages.
 */
class SetupController extends OrgController
{
    public function index(Request $request): RedirectResponse
    {
        $facility = $this->guard($request);

        return $facility === null ? to_route('dashboard') : to_route('org.setup.step', ['step' => SetupSteps::next($facility)]);
    }

    public function show(Request $request, string $step): Response|RedirectResponse
    {
        abort_unless(in_array($step, SetupSteps::ALL, true), 404);
        $facility = $this->guard($request);
        if ($facility === null) {
            return to_route('dashboard');
        }

        $actor = $this->actor($request);

        return Inertia::render('org/setup/'.$step, [
            'facility' => [
                'name' => OrgPresenter::name($facility),
                'name_ar' => $facility->name_ar,
                'name_en' => $facility->name_en,
                'default_locale' => $facility->default_locale,
                'currency' => $facility->currency,
                'currency_locked' => $facility->currency_locked_at !== null,
                'timezone' => $facility->timezone,
            ],
            'steps' => SetupSteps::states($facility),
            'current' => $step,
            'currencies' => SetupOrgRequest::CURRENCIES,
            'timezones' => ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Bahrain', 'Asia/Qatar', 'Asia/Muscat', 'Africa/Cairo', 'UTC'],
            ...$this->stepData($actor, $step),
        ]);
    }

    public function saveOrg(SetupOrgRequest $request, UpdateFacilityProfile $update, T01SaveSetupStep $save): RedirectResponse
    {
        $actor = $this->actor($request);
        $update->handle($actor, $request->validated());
        $save->handle($actor, SetupSteps::ORG, 'done');

        return to_route('org.setup.step', ['step' => SetupSteps::STRUCTURE]);
    }

    public function next(Request $request, string $step, T01SaveSetupStep $save): RedirectResponse
    {
        return $this->mark($request, $step, 'done', $save);
    }

    public function skip(Request $request, string $step, T01SaveSetupStep $save): RedirectResponse
    {
        return $this->mark($request, $step, 'skipped', $save);
    }

    public function complete(Request $request, T02CompleteSetup $complete): RedirectResponse
    {
        try {
            $complete->handle($this->actor($request));
        } catch (InvalidTransition $e) {
            return back()->withErrors(['setup' => __('org.setup.error_'.$e->condition)]);
        }

        $this->toast(__('org.setup.completed'));

        return to_route('dashboard');
    }

    private function mark(Request $request, string $step, string $state, T01SaveSetupStep $save): RedirectResponse
    {
        abort_unless(in_array($step, SetupSteps::ALL, true), 404);
        $save->handle($this->actor($request), $step, $state);

        $order = SetupSteps::ALL;
        $following = $order[min(array_search($step, $order, true) + 1, count($order) - 1)];

        return to_route('org.setup.step', ['step' => $following]);
    }

    /**
     * The facility while the wizard is open; null once it is completed (the caller sends the user away).
     */
    private function guard(Request $request): ?Facility
    {
        $facility = Facility::currentOrFail();
        $this->access->authorize($this->actor($request), Permission::P02, $facility);

        return $facility->setup_status === SetupStatus::Completed ? null : $facility;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepData(User $actor, string $step): array
    {
        $limits = app(LicenseLimits::class);

        return match ($step) {
            SetupSteps::STRUCTURE => [
                'branches' => Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => [
                    'id' => $b->id, 'name_ar' => $b->name_ar, 'name_en' => $b->name_en, 'name' => OrgPresenter::name($b),
                    'code' => $b->code, 'status' => $b->status->value,
                ])->all(),
                'regions' => Region::query()->get()->map(fn ($r) => ['id' => $r->id, 'name' => OrgPresenter::name($r)])->all(),
                'usage' => $this->usage('branches', $limits->activeBranches()),
                'canCreate' => $this->access->can($actor, Permission::P03, new Branch),
            ],
            SetupSteps::TEAM => [
                'invitations' => Invitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())
                    ->orderBy('id')->get()->map(fn (Invitation $i) => [
                        'id' => $i->id, 'name' => $i->name, 'email' => $i->email,
                        'roles' => RoleTemplate::query()->whereIn('id', array_column($i->roles, 'role_template_id'))->get()->map(fn ($t) => $t->name())->all(),
                    ])->all(),
                'roles' => OrgPresenter::assignableRoles(),
                'branches' => Branch::query()->where('status', OrganizationUnitStatus::Active->value)->orderBy('id')->get()
                    ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all(),
                'departments' => Department::query()->where('status', OrganizationUnitStatus::Active->value)->get()
                    ->map(fn ($d) => ['id' => $d->id, 'name' => OrgPresenter::name($d)])->all(),
                'seats' => $this->usage('seats', $limits->usedSeats()),
                'canInvite' => $this->access->can($actor, Permission::P05),
            ],
            SetupSteps::REVIEW => [
                'summary' => [
                    'branches' => $limits->activeBranches(),
                    'invitations' => Invitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())->count(),
                    'members' => $limits->usedSeats() - Invitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '>', now())->count(),
                ],
            ],
            default => [],
        };
    }
}
