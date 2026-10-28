<?php

namespace App\Store;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Enums\CustomerKind;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\Facility;
use App\Models\Store\StoreSettings;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Outbox;
use App\Portal\CustomerOtp;
use App\Portal\OtpThrottled;
use App\Portal\PortalSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Q-48 (a): the light code at checkout. The buyer types a mobile (and a name and, without SMS, an email); a 6-digit
 * code (the P8b policy: hashed, 5 minutes, 5 attempts, send and failure caps — CustomerOtp's limits) proves it, then
 * the order is created for that customer account — linked when the mobile already belongs to one, created otherwise —
 * and the buyer is signed in to their workspace to follow it.
 *
 * Where the code goes: SMS to the typed mobile when the facility has SMS; otherwise an email — for an existing account
 * only the account's own email (a typed address never opens someone else's account), for a new buyer the typed address
 * (which then becomes the account's verified email). Without either the buyer is asked to contact the facility.
 */
final class CheckoutOtp
{
    public const PURPOSE = 'checkout';

    public function __construct(private readonly Outbox $outbox, private readonly SmsChannel $sms) {}

    /**
     * Sends a code. Returns the masked destination. Throws OtpThrottled at the caps, a validation error when no channel
     * can reach the buyer.
     */
    public function issue(string $mobile, string $name, ?string $email, ?string $ip): string
    {
        $hash = CustomerOtp::identifierHash($mobile);
        $this->guard($hash, $ip, true);

        $customer = Customer::query()->where('mobile', $mobile)->first();
        [$channel, $to] = $this->destination($customer, $mobile, $email);
        if ($channel === null) {
            throw ValidationException::withMessages(['email' => __('checkout.otp.no_channel')]);
        }

        $code = str_pad((string) random_int(0, 10 ** CustomerOtp::DIGITS - 1), CustomerOtp::DIGITS, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($customer, $hash, $channel, $to, $code, $ip, $name) {
            CustomerOtpChallenge::query()->where('identifier_hash', $hash)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $challenge = CustomerOtpChallenge::query()->create([
                'customer_id' => $customer?->id,
                'identifier_hash' => $hash,
                'purpose' => self::PURPOSE,
                'channel' => $channel,
                'code_hash' => self::codeHash($hash, $code),
                'expires_at' => now()->addMinutes(CustomerOtp::TTL_MINUTES),
                'ip' => $ip,
            ]);
            $this->send($channel, $to, $code, $name, "store-otp:{$challenge->id}");
        });

        return $channel === 'sms' ? CustomerOtp::maskMobile($to) : CustomerOtp::maskEmail($to);
    }

    /**
     * Checks the code and returns the linked or new customer with the channel the code went to, or null for any wrong,
     * expired or used code.
     *
     * @param  array{mobile: string, name: string, email: string|null}  $buyer
     * @return array{customer: Customer, channel: string}|null
     */
    public function verify(array $buyer, string $code, ?string $ip): ?array
    {
        $hash = CustomerOtp::identifierHash($buyer['mobile']);
        $this->guard($hash, $ip, false);

        $channel = DB::transaction(function () use ($hash, $code) {
            $challenge = CustomerOtpChallenge::query()->where('identifier_hash', $hash)->where('purpose', self::PURPOSE)
                ->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if ($challenge === null) {
                return null;
            }
            if (preg_match('/^\d{'.CustomerOtp::DIGITS.'}$/', $code) !== 1 || ! hash_equals($challenge->code_hash, self::codeHash($hash, $code))) {
                $challenge->attempts++;
                if ($challenge->attempts >= CustomerOtp::MAX_ATTEMPTS) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return null;
            }
            $challenge->forceFill(['consumed_at' => now()])->save();

            return $challenge->channel;
        });

        if ($channel === null) {
            RateLimiter::hit("store-otp:fail:{$hash}", CustomerOtp::WINDOW);
            RateLimiter::hit('store-otp:fail-ip:'.($ip ?? 'none'), CustomerOtp::WINDOW);

            return null;
        }
        RateLimiter::clear("store-otp:fail:{$hash}");

        return ['customer' => $this->account($buyer, $channel), 'channel' => $channel];
    }

    /**
     * The customer account behind a proven mobile: linked (and opened to the workspace) or created in the store's
     * branch. A concurrent creation of the same mobile resolves to the one row (unique mobile).
     *
     * @param  array{mobile: string, name: string, email: string|null}  $buyer
     */
    private function account(array $buyer, string $channel): Customer
    {
        return DB::transaction(function () use ($buyer, $channel) {
            $existing = Customer::query()->where('mobile', $buyer['mobile'])->lockForUpdate()->first();
            if ($existing !== null) {
                if (! $existing->portal_login_enabled) {
                    $existing->forceFill(['portal_login_enabled' => true])->save();
                }

                return $existing;
            }
            $email = $channel === 'email' ? $buyer['email'] : null;
            if ($email !== null && Customer::query()->where('email', $email)->exists()) {
                $email = null;
            }
            try {
                $customer = DB::transaction(fn () => Customer::query()->create([
                    'kind' => CustomerKind::Person->value,
                    'name' => $buyer['name'],
                    'mobile' => $buyer['mobile'],
                    'email' => $email,
                    'portal_login_enabled' => true,
                    'branch_id' => StoreSettings::current()->branch_id,
                ]));
            } catch (UniqueConstraintViolationException) {
                return Customer::query()->where('mobile', $buyer['mobile'])->firstOrFail();
            }
            if ($email !== null) {
                $customer->forceFill(['email_verified_at' => now()])->save();
            }
            Audit::record(AuditAction::CustomerCreated, $customer, null, after: ['name' => $customer->name, 'channel' => 'store'],
                source: PortalSession::SOURCE, label: $customer->name);

            return $customer;
        });
    }

    /**
     * @return array{0: 'sms'|'email'|null, 1: string}
     */
    private function destination(?Customer $customer, string $mobile, ?string $email): array
    {
        if ($this->sms->isConfigured()) {
            return ['sms', $mobile];
        }
        if ($customer !== null) {
            return $customer->email !== null && $customer->email !== '' ? ['email', $customer->email] : [null, ''];
        }
        if ($email === null || Customer::query()->where('email', $email)->exists()) {
            return [null, ''];
        }

        return ['email', $email];
    }

    private function send(string $channel, string $to, string $code, string $name, string $key): void
    {
        $locale = app()->getLocale();
        $facility = Facility::current();
        $facilityName = $facility === null ? config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
        $params = ['code' => $code, 'facility' => $facilityName, 'minutes' => CustomerOtp::TTL_MINUTES, 'name' => $name];

        if ($channel === 'sms') {
            $this->outbox->enqueueSms($to, __('checkout.otp.sms', $params, $locale), "{$key}:sms");

            return;
        }
        $this->outbox->enqueueMail($to, __('checkout.otp.mail_subject', $params, $locale), [
            __('checkout.otp.mail_line', $params, $locale),
            __('portal.otp.mail_warning', $params, $locale),
        ], null, $locale, "{$key}:mail");
    }

    private function guard(string $hash, ?string $ip, bool $sending): void
    {
        $ipKey = $ip ?? 'none';
        $locks = ["store-otp:fail:{$hash}" => CustomerOtp::FAILURES_PER_IDENTIFIER, "store-otp:fail-ip:{$ipKey}" => CustomerOtp::FAILURES_PER_IP];
        foreach ($locks as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
        if (! $sending) {
            return;
        }
        $limits = [
            "store-otp:cooldown:{$hash}" => [1, CustomerOtp::RESEND_COOLDOWN],
            "store-otp:send:{$hash}" => [CustomerOtp::SENDS_PER_IDENTIFIER, CustomerOtp::WINDOW],
            "store-otp:send-ip:{$ipKey}" => [CustomerOtp::SENDS_PER_IP, CustomerOtp::WINDOW],
        ];
        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    private static function codeHash(string $identifierHash, string $code): string
    {
        return hash_hmac('sha256', 'checkout|'.$identifierHash.'|'.$code, (string) config('app.key'));
    }

    public static function normalizeMobile(string $raw): ?string
    {
        return Phone::normalize(trim($raw));
    }
}
