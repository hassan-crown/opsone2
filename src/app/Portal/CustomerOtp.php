<?php

namespace App\Portal;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Models\Facility;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passwordless sign-in codes for customers (WEB-08, Q-47 a+b): a 6-digit code valid 5 minutes, sent by SMS when the
 * facility has SMS configured, otherwise to the email linked to the account. Only the HMAC of the code is stored; a
 * code works once; a newer code replaces the open one.
 *
 * Every answer is the same whether an account exists (no enumeration): an unknown identifier counts against the same
 * caps and simply receives nothing. Caps: sends per identifier and per address with a resend cooldown; wrong codes
 * per code (the code is burned), per identifier and per address, each locking further codes and checks for the window.
 */
final class CustomerOtp
{
    public const LOGIN = 'login';

    public const ACTIVATION = 'activation';

    public const DIGITS = 6;

    public const TTL_MINUTES = 5;

    /** Wrong codes before one code is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Window of every cap and of the lockout, seconds. */
    public const WINDOW = 900;

    public const SENDS_PER_IDENTIFIER = 3;

    public const SENDS_PER_IP = 10;

    public const RESEND_COOLDOWN = 60;

    public const FAILURES_PER_IDENTIFIER = 10;

    public const FAILURES_PER_IP = 30;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly SmsChannel $sms,
    ) {}

    /**
     * The normalized identifier (E.164 mobile or lower-case email), or null when it is neither.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if (str_contains($raw, '@')) {
            $email = mb_strtolower($raw);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 191 ? $email : null;
        }

        return Phone::normalize($raw);
    }

    public static function identifierHash(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    /**
     * Sends a new code when the identifier belongs to an account with workspace login enabled. Throws OtpThrottled
     * (for any identifier) when the caps are reached.
     */
    public function issue(string $identifier, ?string $ip, string $purpose = self::LOGIN): void
    {
        $hash = self::identifierHash($identifier);
        $ipKey = 'portal-otp:send-ip:'.($ip ?? 'none');
        $limits = [
            "portal-otp:cooldown:{$hash}" => [1, self::RESEND_COOLDOWN],
            "portal-otp:send:{$hash}" => [self::SENDS_PER_IDENTIFIER, self::WINDOW],
            $ipKey => [self::SENDS_PER_IP, self::WINDOW],
        ];
        $this->ensureNotLocked($hash, $ip);
        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $customer = self::account($identifier);
        $channel = $customer === null ? null : $this->channelFor($customer, $identifier);
        if ($customer === null || $channel === null) {
            return;
        }

        $code = self::code();
        DB::transaction(function () use ($customer, $identifier, $hash, $purpose, $channel, $code, $ip) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first(['id']);
            CustomerOtpChallenge::query()->where('identifier_hash', $hash)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $challenge = CustomerOtpChallenge::query()->create([
                'customer_id' => $customer->id,
                'identifier_hash' => $hash,
                'purpose' => $purpose,
                'channel' => $channel,
                'code_hash' => self::codeHash($hash, $code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip' => $ip,
            ]);
            $this->send($customer, $identifier, $channel, $code, "portal-otp:{$challenge->id}");
        });
    }

    /**
     * Checks a code. Returns the account and the channel the code went to, or null for any wrong, expired, used or
     * unknown code (the caller answers the same generic error). Throws OtpThrottled while locked.
     *
     * @return array{customer: Customer, channel: string}|null
     */
    public function verify(string $identifier, string $code, ?string $ip): ?array
    {
        $hash = self::identifierHash($identifier);
        $this->ensureNotLocked($hash, $ip);

        $result = DB::transaction(function () use ($hash, $code) {
            $challenge = CustomerOtpChallenge::query()->where('identifier_hash', $hash)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if ($challenge === null) {
                return null;
            }
            if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1 || ! hash_equals($challenge->code_hash, self::codeHash($hash, $code))) {
                $challenge->attempts++;
                if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return null;
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            $customer = Customer::query()->whereKey($challenge->customer_id)->where('portal_login_enabled', true)->first();

            return $customer === null ? null : ['customer' => $customer, 'channel' => $challenge->channel];
        });

        if ($result === null) {
            $this->fail($identifier, $hash, $ip);

            return null;
        }
        RateLimiter::clear("portal-otp:fail:{$hash}");

        return $result;
    }

    /**
     * Where a code for this account would go, as a masked hint for the activation page, or null if nowhere.
     */
    public function destinationHint(Customer $customer): ?string
    {
        return match ($this->channelFor($customer, $customer->mobile)) {
            'sms' => self::maskMobile($customer->mobile),
            'email' => self::maskEmail((string) $customer->email),
            default => null,
        };
    }

    public static function maskMobile(string $mobile): string
    {
        return substr($mobile, 0, 4).str_repeat('•', max(0, strlen($mobile) - 6)).substr($mobile, -2);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private static function account(string $identifier): ?Customer
    {
        $column = str_contains($identifier, '@') ? 'email' : 'mobile';

        return Customer::query()->where($column, $identifier)->where('portal_login_enabled', true)->first();
    }

    /**
     * SMS when the facility has it configured and the customer typed their mobile; otherwise the account's email.
     * Never an address the customer typed that is not on the account.
     */
    private function channelFor(Customer $customer, string $identifier): ?string
    {
        if (! str_contains($identifier, '@') && $this->sms->isConfigured()) {
            return 'sms';
        }
        $email = (string) $customer->email;

        return $email !== '' && ($identifier === $customer->mobile || $identifier === $email) ? 'email' : null;
    }

    private function send(Customer $customer, string $identifier, string $channel, string $code, string $key): void
    {
        $locale = app()->getLocale();
        $facility = Facility::current();
        $name = $facility === null ? config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
        $params = ['code' => $code, 'facility' => $name, 'minutes' => self::TTL_MINUTES];

        if ($channel === 'sms') {
            $this->outbox->enqueueSms($customer->mobile, __('portal.otp.sms', $params, $locale), "{$key}:sms");

            return;
        }
        $this->outbox->enqueueMail((string) $customer->email, __('portal.otp.mail_subject', $params, $locale), [
            __('portal.otp.mail_line', $params, $locale),
            __('portal.otp.mail_warning', $params, $locale),
        ], null, $locale, "{$key}:mail");
    }

    private function ensureNotLocked(string $hash, ?string $ip): void
    {
        foreach (["portal-otp:fail:{$hash}" => self::FAILURES_PER_IDENTIFIER, 'portal-otp:fail-ip:'.($ip ?? 'none') => self::FAILURES_PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
    }

    private function fail(string $identifier, string $hash, ?string $ip): void
    {
        RateLimiter::hit("portal-otp:fail:{$hash}", self::WINDOW);
        RateLimiter::hit('portal-otp:fail-ip:'.($ip ?? 'none'), self::WINDOW);

        // The account's owner sees the lock in the audit trail; an unknown identifier leaves no trace.
        if (RateLimiter::attempts("portal-otp:fail:{$hash}") === self::FAILURES_PER_IDENTIFIER) {
            $customer = self::account($identifier);
            if ($customer !== null) {
                DB::transaction(fn () => Audit::record(AuditAction::PortalSignInLocked, $customer, null, after: ['ip' => $ip],
                    source: PortalSession::SOURCE, label: $customer->name));
            }
        }
    }

    private static function code(): string
    {
        return str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function codeHash(string $identifierHash, string $code): string
    {
        return hash_hmac('sha256', $identifierHash.'|'.$code, (string) config('app.key'));
    }
}
