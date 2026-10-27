<?php

namespace App\Actions\Organization;

use App\Mail\OrgMessage;
use App\Models\Facility;
use App\Models\Invitation;
use App\Models\OwnerInvitation;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the single-use acceptance link of a staff or owner invitation. The plain token exists only here and in
 * the email; the database holds its hash.
 */
final class SendInvitationLink
{
    public function handle(Invitation|OwnerInvitation $invitation, string $token): void
    {
        $facility = Facility::current();
        $locale = $facility->default_locale ?? 'ar';
        $name = $facility === null ? config('app.name') : ($locale === 'en' && $facility->name_en ? $facility->name_en : $facility->name_ar);
        $url = $invitation instanceof OwnerInvitation
            ? route('org.owner-invite.show', ['token' => $token])
            : route('org.invite.show', ['token' => $token]);

        Mail::to($invitation->email)->send(
            (new OrgMessage(
                title: __('org.mail.invite_subject', ['facility' => $name]),
                lines: [
                    __('org.mail.invite_line', ['facility' => $name, 'days' => Invitation::VALID_DAYS]),
                    __('org.mail.invite_proof'),
                ],
                action: ['label' => __('org.mail.invite_action'), 'url' => $url],
                dir: $locale === 'ar' ? 'rtl' : 'ltr',
            ))->locale($locale),
        );
    }
}
