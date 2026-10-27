<?php

namespace App\Actions\Organization;

use App\Models\Facility;
use App\Models\Invitation;
use App\Models\OwnerInvitation;
use App\Notifications\Outbox;

/**
 * Emails the single-use acceptance link of a staff or owner invitation. The plain token exists only here and in
 * the email; the database holds its hash.
 */
final class SendInvitationLink
{
    public function __construct(private readonly Outbox $outbox) {}

    public function handle(Invitation|OwnerInvitation $invitation, string $token): void
    {
        $facility = Facility::current();
        $locale = $facility->default_locale ?? 'ar';
        $name = $facility === null ? config('app.name') : ($locale === 'en' && $facility->name_en ? $facility->name_en : $facility->name_ar);
        $url = $invitation instanceof OwnerInvitation
            ? route('org.owner-invite.show', ['token' => $token])
            : route('org.invite.show', ['token' => $token]);

        // Queued through the outbox: a mail failure never rolls back the invitation, and the key (one per token)
        // keeps a repeated call from sending the same link twice.
        $this->outbox->enqueueMail(
            $invitation->email,
            __('org.mail.invite_subject', ['facility' => $name], $locale),
            [
                __('org.mail.invite_line', ['facility' => $name, 'days' => Invitation::VALID_DAYS], $locale),
                __('org.mail.invite_proof', [], $locale),
            ],
            ['label' => __('org.mail.invite_action', [], $locale), 'url' => $url],
            $locale,
            'invite:'.class_basename($invitation).':'.$invitation->id.':'.substr(hash('sha256', $token), 0, 16),
        );
    }
}
