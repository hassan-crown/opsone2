<?php

namespace App\Actions\Site;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Enums\InterestChannel;
use App\Enums\InterestStatus;
use App\Enums\OrganizationUnitStatus;
use App\Enums\StateMachine;
use App\Models\Branch;
use App\Models\Interest;
use App\Models\SiteLeadSubmission;
use App\Support\LockingTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The public contact form (WEB-02, P-72): creates exactly one Interest per submission key and returns its reference.
 * The same key replayed (double click, retry after a timeout) returns the same interest instead of a duplicate.
 * No account and no price are involved; the interest lands in the first active branch for staff to follow up.
 */
final class SubmitLead
{
    public const SOURCE = 'website';

    public function __construct(private readonly TransitionRecorder $transitions) {}

    /**
     * @param  array{name: string, mobile?: string|null, email?: string|null, need: string}  $data
     */
    public function handle(string $submissionKey, array $data): Interest
    {
        $existing = SiteLeadSubmission::query()->where('submission_key', $submissionKey)->first();
        if ($existing !== null) {
            return Interest::query()->findOrFail($existing->interest_id);
        }

        $branch = self::intakeBranch();

        try {
            return LockingTransaction::run(function () use ($submissionKey, $data, $branch) {
                $interest = $this->open($data, $branch);

                SiteLeadSubmission::query()->create(['submission_key' => $submissionKey, 'interest_id' => $interest->id]);

                return $interest;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won: replay its interest.
            $row = SiteLeadSubmission::query()->where('submission_key', $submissionKey)->firstOrFail();

            return Interest::query()->findOrFail($row->interest_id);
        }
    }

    /**
     * The branch where public submissions land: the first active one (none = the form is unavailable).
     */
    public static function intakeBranch(): Branch
    {
        return Branch::query()->where('status', OrganizationUnitStatus::Active)->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['form' => __('site.public.lead.unavailable')]);
    }

    /**
     * Opens a new website interest (M-03-T00, audited). Must run inside the caller's transaction; the appointment
     * request form (ADR-0039) uses it too.
     *
     * @param  array{name: string, mobile?: string|null, email?: string|null, need: string}  $data
     */
    public function open(array $data, Branch $branch): Interest
    {
        $mobile = isset($data['mobile']) && $data['mobile'] !== '' ? (Phone::normalize($data['mobile']) ?? $data['mobile']) : null;
        $email = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : null;

        $interest = new Interest([
            'name' => $data['name'], 'mobile' => $mobile, 'email' => $email, 'need' => $data['need'],
            'channel' => InterestChannel::Website, 'branch_id' => $branch->id,
        ]);
        $interest->status = InterestStatus::New;
        $interest->save();
        $interest->forceFill(['reference' => sprintf('INT-%04d', $interest->id)])->save();

        $this->transitions->record(StateMachine::M03, 'T00', $interest, null, InterestStatus::New, null, self::SOURCE);
        Audit::record(
            AuditAction::InterestCreated, $interest, null,
            after: $interest->only(['reference', 'channel', 'branch_id']), source: self::SOURCE,
        );

        return $interest;
    }
}
