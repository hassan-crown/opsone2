<?php

namespace Tests\Support;

use App\Access\TransitionRecorder;
use App\Actions\Organization\EstablishOwner;
use App\Actions\StateMachines\M22Membership\T00CreateMembership;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\Region;
use App\Models\RoleTemplate;
use App\Models\User;
use Database\Seeders\RoleTemplateSeeder;

/**
 * Test organization:
 *  region north: branch A, branch B — region south: branch C
 *  department D1 serves A — department D2 serves C
 */
final class Org
{
    public Facility $facility;

    public User $owner;

    public Region $north;

    public Region $south;

    public Branch $a;

    public Branch $b;

    public Branch $c;

    public Department $d1;

    public Department $d2;

    public static function make(): self
    {
        $org = new self;

        (new RoleTemplateSeeder)->run();
        $org->facility = Facility::query()->create(['name_ar' => 'منشأة الاختبار', 'name_en' => 'Test facility']);

        $org->north = Region::factory()->create();
        $org->south = Region::factory()->create();
        $org->a = Branch::factory()->create(['region_id' => $org->north->id]);
        $org->b = Branch::factory()->create(['region_id' => $org->north->id]);
        $org->c = Branch::factory()->create(['region_id' => $org->south->id]);
        $org->d1 = Department::factory()->create();
        $org->d1->branches()->sync([$org->a->id]);
        $org->d2 = Department::factory()->create();
        $org->d2->branches()->sync([$org->c->id]);

        $org->owner = User::factory()->create();
        app(EstablishOwner::class)->handle($org->owner, TransitionRecorder::SOURCE_SEEDER);
        $org->facility->refresh();

        return $org;
    }

    /**
     * Creates an active member holding one default role template.
     *
     * @param  list<Branch>  $branches
     */
    public function member(?string $role, array $branches = [], ?Department $department = null, ?string $scope = null): User
    {
        $user = User::factory()->create();
        $roles = $role === null ? [] : [[
            'role_template_id' => (int) RoleTemplate::query()->where('key', $role)->value('id'),
            'scope' => $scope,
        ]];

        app(T00CreateMembership::class)->handle(
            $user,
            $department?->id,
            array_map(fn (Branch $b) => $b->id, $branches),
            $roles,
            null,
            $this->owner,
            TransitionRecorder::SOURCE_SEEDER,
        );

        return $user;
    }

    public static function membershipOf(User $user): Membership
    {
        return Membership::query()->where('user_id', $user->id)->firstOrFail();
    }

    public static function templateId(string $key): int
    {
        return (int) RoleTemplate::query()->where('key', $key)->value('id');
    }
}
