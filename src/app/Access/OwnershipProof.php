<?php

namespace App\Access;

use App\Mail\OrgMessage;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\OwnerInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Ownership-proof code for invitation acceptance (Q-17, default channel: the invited email).
 * A six-digit code, stored only as a keyed hash, single use, valid 15 minutes, five wrong attempts lock it,
 * and a new one cannot be requested within 45 seconds.
 */
final class OwnershipProof
{
    public const VALID_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 45;

    public function send(Invitation|OwnerInvitation $subject): void
    {
        $code = DB::transaction(function () use ($subject): ?string {
            $locked = $this->lock($subject);

            if ($locked->code_sent_at !== null && $locked->code_sent_at->addSeconds(self::RESEND_SECONDS)->isFuture()) {
                return null;
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $locked->forceFill([
                'code_hash' => $this->hash($code),
                'code_sent_at' => now(),
                'code_expires_at' => now()->addMinutes(self::VALID_MINUTES),
                'code_attempts' => 0,
            ])->save();

            return $code;
        });

        if ($code === null) {
            throw ValidationException::withMessages(['code' => __('org.accept.code_wait', ['seconds' => self::RESEND_SECONDS])]);
        }

        $locale = Facility::current()->default_locale ?? 'ar';
        Mail::to($subject->email)->send(
            (new OrgMessage(
                title: __('org.mail.code_subject'),
                lines: [__('org.mail.code_line', ['code' => $code, 'minutes' => self::VALID_MINUTES]), __('org.mail.code_ignore')],
                dir: $locale === 'ar' ? 'rtl' : 'ltr',
            ))->locale($locale),
        );
    }

    /**
     * Checks and consumes the code. A wrong attempt is counted even though the caller then fails.
     *
     * @throws ValidationException
     */
    public function verify(Invitation|OwnerInvitation $subject, string $code): void
    {
        $outcome = DB::transaction(function () use ($subject, $code): string {
            $locked = $this->lock($subject);

            if ($locked->code_hash === null || $locked->code_expires_at === null || $locked->code_expires_at->isPast()) {
                return 'none';
            }
            if ($locked->code_attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
            if (! hash_equals($locked->code_hash, $this->hash(trim($code)))) {
                $locked->forceFill(['code_attempts' => $locked->code_attempts + 1])->save();

                return 'wrong';
            }

            $locked->forceFill(['code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0])->save();

            return 'ok';
        });

        if ($outcome !== 'ok') {
            throw ValidationException::withMessages(['code' => __('org.accept.code_'.$outcome)]);
        }
    }

    private function lock(Invitation|OwnerInvitation $subject): Invitation|OwnerInvitation
    {
        $id = (int) $subject->getKey();

        return $subject instanceof Invitation
            ? Invitation::query()->lockForUpdate()->findOrFail($id)
            : OwnerInvitation::query()->lockForUpdate()->findOrFail($id);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
