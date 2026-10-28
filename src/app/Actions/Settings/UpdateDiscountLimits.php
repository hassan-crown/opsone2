<?php

namespace App\Actions\Settings;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\DiscountLimit;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * Discount limits per role template (SettingsSales, AGR-02, SAL-09): the owner alone sets the others' limits
 * (matrix P-31/P-35 note); the owner's own role has none. Basis points of the discount base, 0..10000.
 */
final class UpdateDiscountLimits
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $bp = ['required', 'integer', 'min:0', 'max:10000'];

        return [
            'limits' => ['required', 'array', 'max:50'],
            'limits.*.role_template_id' => ['required', 'integer', 'distinct'],
            'limits.*.custom_bp' => $bp,
            'limits.*.ready_bp' => $bp,
            'limits.*.approval_bp' => $bp,
        ];
    }

    public static function canEdit(User $user): bool
    {
        $owner = Facility::current()?->owner_membership_id;

        return $owner !== null && Membership::query()->whereKey($owner)->where('user_id', $user->id)->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        if (! self::canEdit($actor)) {
            throw new AuthorizationException;
        }
        $data = Validator::make($data, self::rules())->validate();
        $templates = RoleTemplate::query()->where('is_owner', false)->pluck('id')->map(fn ($id) => (int) $id)->all();

        LockingTransaction::run(function () use ($actor, $data, $templates, $source) {
            $before = DiscountLimit::query()->whereNotNull('role_template_id')->get()
                ->mapWithKeys(fn (DiscountLimit $l) => [$l->role_template_id => $l->only(['custom_bp', 'ready_bp', 'approval_bp'])])->all();
            $after = [];
            foreach ($data['limits'] as $row) {
                $id = (int) $row['role_template_id'];
                if (! in_array($id, $templates, true)) {
                    continue;
                }
                $values = ['custom_bp' => (int) $row['custom_bp'], 'ready_bp' => (int) $row['ready_bp'], 'approval_bp' => (int) $row['approval_bp']];
                DiscountLimit::query()->updateOrCreate(['role_template_id' => $id], $values);
                $after[$id] = $values;
            }
            Audit::record(AuditAction::DiscountLimitsUpdated, null, $actor, before: ['limits' => $before], after: ['limits' => $after], source: $source);
        });
    }
}
