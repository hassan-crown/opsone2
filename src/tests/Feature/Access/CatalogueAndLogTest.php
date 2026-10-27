<?php

use App\Access\Access;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\Audience;
use App\Access\Enums\DenyReason;
use App\Access\Enums\LicenseMode;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Access\PermissionCatalogue;
use App\Models\RoleTemplate;
use App\Models\StateTransition;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Org;

uses(RefreshDatabase::class);

it('catalogues P-01..P-102 with bilingual labels, scopes and default roles', function () {
    expect(Permission::cases())->toHaveCount(102)
        ->and(array_keys(PermissionCatalogue::DEFINITIONS))->toBe(array_map(fn (Permission $p) => $p->value, Permission::cases()));

    foreach (Permission::cases() as $permission) {
        $definition = PermissionCatalogue::definition($permission);
        expect($definition['ar'])->not->toBeEmpty()
            ->and($definition['en'])->not->toBeEmpty();

        if ($permission->audience() === Audience::Staff) {
            expect($definition['roles'])->not->toBeEmpty()->and($definition['scopes'])->not->toBeEmpty();
        }
    }

    expect(Permission::P01->label('en'))->toBe('View facility data and usage')
        ->and(Permission::P07->allowedScopes())->toHaveCount(1);
});

it('seeds the eight default role templates from the matrix', function () {
    $this->seed(DatabaseSeeder::class);

    expect(RoleTemplate::query()->pluck('key')->all())
        ->toEqualCanonicalizing(['owner', 'manager', 'supervisor', 'sales', 'executor', 'finance', 'inventory', 'content']);

    $sales = RoleTemplate::query()->where('key', 'sales')->firstOrFail();
    expect($sales->grants()->where('permission', 'P-94')->value('scope'))->toBe(Scope::Own)
        ->and($sales->grants()->where('permission', 'P-09')->value('scope'))->toBe(Scope::Branches)
        ->and(RoleTemplate::query()->where('key', 'executor')->firstOrFail()->grants()->where('permission', 'P-24')->value('scope'))->toBe(Scope::Assigned);

    // Re-seeding keeps owner edits.
    $sales->grants()->where('permission', 'P-09')->delete();
    $this->seed(DatabaseSeeder::class);
    expect($sales->grants()->where('permission', 'P-09')->exists())->toBeFalse();
});

it('checks the license capability first', function () {
    $org = Org::make();
    app()->forgetScopedInstances();
    app()->instance(LicenseCapabilities::class, new class implements LicenseCapabilities
    {
        public function allows(string $capability): bool
        {
            return $capability !== 'ready_goods';
        }

        public function mode(): LicenseMode
        {
            return LicenseMode::Full;
        }
    });

    expect(Access::inspect($org->owner, 'P-12')->reason)->toBe(DenyReason::License)
        ->and(Access::can($org->owner, 'P-11'))->toBeTrue();
});

it('denies customer, public and platform actions through the staff Gate', function () {
    $org = Org::make();

    foreach (['P-66', 'P-72', 'P-76', 'P-80'] as $code) {
        expect(Access::inspect($org->owner, $code)->reason)->toBe(DenyReason::Audience);
    }
    expect(Access::inspect($org->owner, 'P-999')->reason)->toBe(DenyReason::UnknownPermission);
});

it('records creation transitions T00 with actor and source', function () {
    $org = Org::make();
    $member = $org->member('sales', [$org->a]);

    $log = StateTransition::query()->where('machine', 'M-22')->where('subject_id', Org::membershipOf($member)->id)->sole();

    expect($log->transition)->toBe('T00')
        ->and($log->from_state)->toBeNull()
        ->and($log->to_state)->toBe('active')
        ->and($log->actor_user_id)->toBe($org->owner->id)
        ->and($log->source)->toBe('seeder');
});

it('keeps the transition log append-only', function () {
    $org = Org::make();
    $log = StateTransition::query()->firstOrFail();

    expect(fn () => $log->update(['to_state' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);

    expect($log->fresh()?->to_state)->toBe('active');
});
