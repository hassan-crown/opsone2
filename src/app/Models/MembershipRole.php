<?php

namespace App\Models;

use App\Access\Enums\Scope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role template held by a member. `scope`/`targets` set the breadth of the template's organizational
 * grants for this member (e.g. a manager over two branches or the whole facility); null keeps the template's
 * scope with targets taken from the member's department and branches.
 *
 * @property int $id
 * @property int $membership_id
 * @property int $role_template_id
 * @property Scope|null $scope
 * @property list<int>|null $targets
 * @property-read RoleTemplate $template
 */
#[Fillable(['membership_id', 'role_template_id', 'scope', 'targets'])]
class MembershipRole extends Model
{
    /**
     * @return BelongsTo<RoleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class, 'role_template_id');
    }

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
            'scope' => Scope::class,
            'targets' => 'array',
        ];
    }
}
