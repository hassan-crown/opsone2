<?php

namespace App\Actions\StateMachines\M09Change;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Agreements\AgreementWrite;
use App\Agreements\DiscountCheck;
use App\Agreements\QuoteInput;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ChangeStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\DiscountDecision;
use App\Models\FinanceSettings;
use App\Models\OrderAction;
use App\Models\User;
use App\Workflow\Transition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits an unpresented change (draft, or after a revision request): description, proposed lines/prices/discount,
 * terms, execution days, the affected work (action keys of this order, proposed by the screen from the changed
 * lines and confirmed by the person — the system does not infer engineering impact) and the extra days.
 */
final class UpdateChangeDraft
{
    public function __construct(
        private readonly AgreementWrite $write,
        private readonly DiscountCheck $discounts,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            ...QuoteInput::rules(),
            'description' => ['required', 'string', 'max:1000'],
            'affected' => ['present', 'array', 'max:50'],
            'affected.*' => ['string', 'regex:/^[a-z0-9_]{1,40}$/', 'distinct'],
            'delay_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, AgreementChange $change, array $data, ?int $lockVersion, ?string $idempotencyKey = null, string $source = TransitionRecorder::SOURCE_WEB): AgreementChange
    {
        $this->write->run('change.update', $actor, $idempotencyKey, ['change_id' => $change->id, 'lock_version' => $lockVersion, 'data' => $data],
            function () use ($actor, $change, $data, $lockVersion, $source) {
                [$order, $change] = AgreementLock::change($change, $lockVersion, 'draft');
                Access::authorize($actor, Permission::P36, $order);
                if (! in_array($change->status, [ChangeStatus::Draft, ChangeStatus::NeedsRevision], true)) {
                    Transition::refuse(StateMachine::M09, 'draft', 'not_draft');
                }

                $fields = Validator::make($data, self::rules())->validate();
                $known = OrderAction::query()->where('order_id', $order->id)->pluck('key')->all();
                $unknown = array_diff($fields['affected'], $known);
                if ($unknown !== []) {
                    throw ValidationException::withMessages(['affected' => __('agreements.validation.affected')]);
                }
                $content = QuoteInput::priced(QuoteInput::normalize($order, $data), FinanceSettings::current());
                $this->discounts->contentChanged(DiscountDecision::SUBJECT_CHANGE, $change->id, $content, $actor);

                $change->forceFill([
                    'description' => trim($fields['description']), 'content' => $content, 'affected' => array_values($fields['affected']),
                    'delay_days' => (int) $fields['delay_days'], 'lock_version' => $change->lock_version + 1,
                ])->save();
                Audit::record(AuditAction::ChangeDraftUpdated, $order, $actor, after: [
                    'change' => $change->number, 'total_minor' => $content['totals']['total_minor'], 'affected' => $change->affected,
                ], source: $source, label: $order->number);

                return ['change_id' => $change->id];
            });

        return $change->refresh();
    }
}
