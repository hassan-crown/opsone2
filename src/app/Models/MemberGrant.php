<?php

namespace App\Models;

use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Enums\MemberGrantEffect;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-member override: `allow` adds a grant, `deny` removes every template grant of that permission.
 *
 * @property int $id
 * @property int $membership_id
 * @property Permission $permission
 * @property MemberGrantEffect $effect
 * @property Scope|null $scope
 * @property list<int>|null $targets
 * @property int|null $granted_by_membership_id
 */
#[Fillable(['membership_id', 'permission', 'effect', 'scope', 'targets', 'granted_by_membership_id'])]
class MemberGrant extends Model
{
    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
            'effect' => MemberGrantEffect::class,
            'scope' => Scope::class,
            'targets' => 'array',
        ];
    }
}
