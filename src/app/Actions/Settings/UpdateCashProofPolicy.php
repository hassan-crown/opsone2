<?php

namespace App\Actions\Settings;

use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\FinanceSettings;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * Product-owner decision (2026-10-28, ADR-0014): the facility owner alone decides whether a cash collection needs a
 * proof file (default: required). Off, a cash collection may be recorded without a file but still with its collector
 * (the recording member) and a note; a transfer, card or cheque always needs its proof. Audited.
 */
final class UpdateCashProofPolicy
{
    public static function canEdit(User $user): bool
    {
        return UpdateDiscountLimits::canEdit($user);
    }

    /**
     * @param  array<string, mixed>  $data  {cash_proof_required: bool}
     */
    public function handle(User $actor, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): FinanceSettings
    {
        if (! self::canEdit($actor)) {
            throw new AuthorizationException;
        }
        /** @var array{cash_proof_required: bool} $data */
        $data = Validator::make($data, ['cash_proof_required' => ['required', 'boolean']])->validate();

        return LockingTransaction::run(function () use ($actor, $data, $lockVersion, $source) {
            $settings = FinanceSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new FinanceSettings(FinanceSettings::DEFAULTS);
            AgreementLock::checkVersion($settings->lock_version, $lockVersion);
            $before = $settings->cashProofRequired();
            $settings->fill(['cash_proof_required' => (bool) $data['cash_proof_required'], 'updated_by' => $actor->id]);
            $settings->lock_version = $settings->exists ? $settings->lock_version + 1 : 1;
            $settings->save();

            // Not `…proof…`: the audit recorder redacts keys that look like proofs/secrets.
            Audit::record(AuditAction::CashProofPolicyUpdated, $settings, $actor,
                before: ['cash_evidence_required' => $before], after: ['cash_evidence_required' => $settings->cashProofRequired()], source: $source);

            return $settings;
        });
    }
}
