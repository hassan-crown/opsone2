<?php

namespace App\Portal;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Staff invite a customer they created to their workspace (ActivateAccount, WEB-08): an activation link by SMS and, when
 * the account has one, email. The link only opens the activation page; access still needs a one-time code sent to the
 * account's own mobile or email, so a forwarded link alone opens nothing. Needs P-10 over the customer.
 */
final class PortalInvitation
{
    public const LINK_DAYS = 7;

    public function __construct(private readonly Outbox $outbox) {}

    public static function link(Customer $customer): string
    {
        return URL::temporarySignedRoute('customer.activate', now()->addDays(self::LINK_DAYS), ['customer' => $customer->id]);
    }

    public function send(User $actor, Customer $customer): void
    {
        Access::authorize($actor, Permission::P10, $customer);
        if (! $customer->portal_login_enabled) {
            throw ValidationException::withMessages(['portal' => __('portal.invite.login_disabled')]);
        }

        DB::transaction(function () use ($actor, $customer) {
            $facility = Facility::current();
            $locale = $facility === null ? 'ar' : $facility->default_locale;
            $name = $facility === null ? (string) config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
            $params = ['facility' => $name, 'link' => self::link($customer), 'days' => self::LINK_DAYS];
            $stamp = now()->format('YmdHi');

            $this->outbox->enqueueSms($customer->mobile, __('portal.invite.sms', $params, $locale), "portal-invite:{$customer->id}:{$stamp}:sms");
            if ($customer->email !== null && $customer->email !== '') {
                $this->outbox->enqueueMail($customer->email, __('portal.invite.mail_subject', $params, $locale), [__('portal.invite.mail_line', $params, $locale)],
                    ['label' => __('portal.invite.mail_action', [], $locale), 'url' => $params['link']], $locale, "portal-invite:{$customer->id}:{$stamp}:mail");
            }
            Audit::record(AuditAction::PortalInvited, $customer, $actor, label: $customer->name);
        });
    }
}
