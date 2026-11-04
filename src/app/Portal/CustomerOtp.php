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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passwordless sign-in codes for customers (WEB-08, Q-47 a+b): a 6-digit code valid 5 minutes, sent by SMS when the
 * facility has SMS configured, otherwise to the email linked to the account. Only the HMAC of the code is stored; a
 * code works once; a newer code replaces the open one.
 *
 * Every answer is the same whether an account exists (no enumeration): an unknown identifier counts against the same
 * caps and simply receives nothing. Caps: sends per identifier and per address with a resend cooldown; wrong codes
 * per code (the code is burned), per identifier from one address and per address, each locking further codes and
 * checks for the window. A wrong code locks the identifier everywhere only while a real code is open (so a stranger
 * without codes cannot lock the customer out, L-2) and counts against the account's daily cap (L-3). The checkout code
 * shares these failure caps (M-3). Step-up codes have their own budget per account: sign-in guesses never block signing.
 */
final class CustomerOtp
{
    public const LOGIN = 'login';

    public const ACTIVATION = 'activation';

    /** A fresh code a signed-in customer enters to confirm a sensitive action (signing a document, ADR-0029). */
    public const STEP_UP = 'step_up';

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

    /** Wrong codes per account per day, across sign-in, activation and checkout codes (L-3). */
    public const FAILURES_PER_DAY = 30;

    public const DAY = 86400;

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
     * The fixed demo code, or null when it is unset, malformed or the environment is not a demo one (PORTAL_DEMO_OTP).
     * Even then it opens only the showcase accounts (isDemoAccount).
     */
    public static function demoCode(): ?string
    {
        $code = config('portal.demo_otp');
        if (! is_string($code) || preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        return app()->environment((array) config('portal.demo_otp_environments')) ? $code : null;
    }

    /**
     * The showcase data's customers: their email is on the reserved demo domain (a `.test` name no real customer can
     * own, M-5). Any other account needs its real code even where the demo code is enabled.
     */
    public static function isDemoAccount(?Customer $customer): bool
    {
        $domain = (string) config('portal.demo_otp_email_domain');
        $email = mb_strtolower((string) $customer?->email);

        return $domain !== '' && $email !== '' && str_ends_with($email, '@'.mb_strtolower($domain));
    }

    private static function isDemoCode(string $code, ?Customer $customer): bool
    {
        $demo = self::demoCode();

        return $demo !== null && hash_equals($demo, $code) && self::isDemoAccount($customer);
    }

    private static function logDemoUse(string $purpose, int $customerId): void
    {
        Log::info('Portal demo OTP used.', ['purpose' => $purpose, 'customer_id' => $customerId]);
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
        self::ensureNotLocked($identifier, $ip);
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
            CustomerOtpChallenge::query()->where('identifier_hash', $hash)->whereIn('purpose', [self::LOGIN, self::ACTIVATION])->whereNull('consumed_at')->update(['consumed_at' => now()]);
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
     * @return array{customer: Customer, channel: string, demo: bool}|null
     */
    public function verify(string $identifier, string $code, ?string $ip): ?array
    {
        $hash = self::identifierHash($identifier);
        self::ensureNotLocked($identifier, $ip);

        $result = DB::transaction(function () use ($hash, $code) {
            $challenge = CustomerOtpChallenge::query()->where('identifier_hash', $hash)->whereIn('purpose', [self::LOGIN, self::ACTIVATION])
                ->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if ($challenge === null) {
                return false;
            }
            $demo = self::isDemoCode($code, Customer::query()->find($challenge->customer_id));
            if (! $demo && (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1 || ! hash_equals($challenge->code_hash, self::codeHash($hash, $code)))) {
                $challenge->attempts++;
                if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return null;
            }

            $challenge->forceFill(['consumed_at' => now()])->save();
            $customer = Customer::query()->whereKey($challenge->customer_id)->where('portal_login_enabled', true)->first();

            return $customer === null ? null : ['customer' => $customer, 'channel' => $challenge->channel, 'demo' => $demo];
        });

        if (! is_array($result)) {
            self::recordFailure($identifier, $ip, $result !== false);

            return null;
        }
        self::clearFailures($identifier, $ip);
        if ($result['demo']) {
            self::logDemoUse(self::LOGIN, $result['customer']->id);
        }

        return $result;
    }

    /**
     * Sends a step-up code to a signed-in customer for one subject (`document:{id}`). Same caps as sign-in codes on the
     * account's mobile and the address; a newer step-up code for the subject replaces the open one. Returns the masked
     * destination, or null when the account has nowhere to receive a code.
     */
    public function issueStepUp(Customer $customer, string $subject, ?string $ip): ?string
    {
        $hash = self::identifierHash($customer->mobile);
        // Step-up codes keep their own budget per account (L-2): sign-in requests by anyone else never use it up.
        $limits = [
            "portal-otp:step-up-cooldown:{$customer->id}:{$subject}" => [1, self::RESEND_COOLDOWN],
            "portal-otp:step-up-send:{$customer->id}" => [self::SENDS_PER_IDENTIFIER, self::WINDOW],
            'portal-otp:send-ip:'.($ip ?? 'none') => [self::SENDS_PER_IP, self::WINDOW],
        ];
        self::ensureStepUpNotLocked($customer, $ip);
        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
        $channel = $this->channelFor($customer, $customer->mobile);
        if ($channel === null) {
            return null;
        }
        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $code = self::code();
        DB::transaction(function () use ($customer, $hash, $subject, $channel, $code, $ip) {
            CustomerOtpChallenge::query()->where('customer_id', $customer->id)->where('purpose', self::STEP_UP)->where('subject', $subject)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $challenge = CustomerOtpChallenge::query()->create([
                'customer_id' => $customer->id,
                'identifier_hash' => $hash,
                'purpose' => self::STEP_UP,
                'subject' => $subject,
                'channel' => $channel,
                'code_hash' => self::codeHash($hash, $code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip' => $ip,
            ]);
            $this->send($customer, $customer->mobile, $channel, $code, "portal-otp:{$challenge->id}", self::STEP_UP);
        });

        return $channel === 'sms' ? self::maskMobile($customer->mobile) : self::maskEmail((string) $customer->email);
    }

    /**
     * Checks a step-up code for the customer and subject and consumes it (in its own transaction: a code works once,
     * even when the action it confirms is refused afterwards). Returns the consumed challenge — the OTP reference a
     * signature keeps — or null for a wrong, expired, used or foreign code. Throws OtpThrottled while locked.
     */
    public function verifyStepUp(Customer $customer, string $subject, string $code, ?string $ip): ?CustomerOtpChallenge
    {
        self::ensureStepUpNotLocked($customer, $ip);

        $demo = self::isDemoCode($code, $customer);
        $challenge = DB::transaction(function () use ($customer, $subject, $code, $demo) {
            $challenge = CustomerOtpChallenge::query()->where('customer_id', $customer->id)->where('purpose', self::STEP_UP)
                ->where('subject', $subject)->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if ($challenge === null) {
                return null;
            }
            if (! $demo && (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1 || ! hash_equals($challenge->code_hash, self::codeHash($challenge->identifier_hash, $code)))) {
                $challenge->attempts++;
                if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return null;
            }
            $challenge->forceFill(['consumed_at' => now()])->save();

            return $challenge;
        });

        if ($challenge === null) {
            self::recordStepUpFailure($customer, $ip);

            return null;
        }
        RateLimiter::clear("portal-otp:step-up-fail:{$customer->id}");
        if ($demo) {
            // Not persisted: lets the caller's audit record that the fixed demo code was used.
            $challenge->setAttribute('demo_used', true);
            self::logDemoUse(self::STEP_UP, $customer->id);
        }

        return $challenge;
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

    private function send(Customer $customer, string $identifier, string $channel, string $code, string $key, string $purpose = self::LOGIN): void
    {
        $locale = app()->getLocale();
        $facility = Facility::current();
        $name = $facility === null ? config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
        $params = ['code' => $code, 'facility' => $name, 'minutes' => self::TTL_MINUTES];

        $prefix = $purpose === self::STEP_UP ? 'esign.otp' : 'portal.otp';
        if ($channel === 'sms') {
            $this->outbox->enqueueSms($customer->mobile, __("{$prefix}.sms", $params, $locale), "{$key}:sms");

            return;
        }
        $this->outbox->enqueueMail((string) $customer->email, __("{$prefix}.mail_subject", $params, $locale), [
            __("{$prefix}.mail_line", $params, $locale),
            __('portal.otp.mail_warning', $params, $locale),
        ], null, $locale, "{$key}:mail");
    }

    /**
     * Throws OtpThrottled while the identifier, the identifier from this address, the address or the account's day is
     * locked. Shared with the checkout code (M-3).
     */
    public static function ensureNotLocked(string $identifier, ?string $ip): void
    {
        $hash = self::identifierHash($identifier);
        $ipKey = $ip ?? 'none';
        $locks = [
            "portal-otp:fail:{$hash}" => self::FAILURES_PER_IDENTIFIER,
            "portal-otp:fail:{$hash}:{$ipKey}" => self::FAILURES_PER_IDENTIFIER,
            "portal-otp:fail-ip:{$ipKey}" => self::FAILURES_PER_IP,
        ];
        $customerId = self::anyAccount($identifier)?->id;
        if ($customerId !== null) {
            $locks["portal-otp:fail-day:{$customerId}"] = self::FAILURES_PER_DAY;
        }
        self::throwWhenLocked($locks);
    }

    /**
     * Counts a wrong code. Every failure counts per identifier from this address and per address; only a failure
     * against an open code (`$challenged`) counts per identifier everywhere and against the account's day, so a stranger
     * without codes cannot lock the customer out (L-2). Shared with the checkout code (M-3).
     */
    public static function recordFailure(string $identifier, ?string $ip, bool $challenged): void
    {
        $hash = self::identifierHash($identifier);
        $ipKey = $ip ?? 'none';
        RateLimiter::hit("portal-otp:fail:{$hash}:{$ipKey}", self::WINDOW);
        RateLimiter::hit("portal-otp:fail-ip:{$ipKey}", self::WINDOW);
        $locked = RateLimiter::attempts("portal-otp:fail:{$hash}:{$ipKey}") === self::FAILURES_PER_IDENTIFIER;
        if ($challenged) {
            RateLimiter::hit("portal-otp:fail:{$hash}", self::WINDOW);
            $locked = $locked || RateLimiter::attempts("portal-otp:fail:{$hash}") === self::FAILURES_PER_IDENTIFIER;
        }
        // The account's owner sees the lock (from one address or everywhere) once in the audit trail; an unknown
        // identifier leaves no trace.
        if ($locked) {
            $customer = self::account($identifier);
            if ($customer !== null) {
                DB::transaction(fn () => Audit::record(AuditAction::PortalSignInLocked, $customer, null, after: ['ip' => $ip],
                    source: PortalSession::SOURCE, label: $customer->name));
            }
        }

        $customer = $challenged ? self::anyAccount($identifier) : null;
        if ($customer === null) {
            return;
        }
        RateLimiter::hit("portal-otp:fail-day:{$customer->id}", self::DAY);
        if (RateLimiter::attempts("portal-otp:fail-day:{$customer->id}") === self::FAILURES_PER_DAY) {
            DB::transaction(fn () => Audit::record(AuditAction::PortalSignInLocked, $customer, null, after: ['ip' => $ip, 'daily' => true],
                source: PortalSession::SOURCE, label: $customer->name));
        }
    }

    /**
     * A right code ends the window locks of the identifier (the daily cap stays: it bounds guesses, not mistakes).
     */
    public static function clearFailures(string $identifier, ?string $ip): void
    {
        $hash = self::identifierHash($identifier);
        RateLimiter::clear("portal-otp:fail:{$hash}");
        RateLimiter::clear("portal-otp:fail:{$hash}:".($ip ?? 'none'));
    }

    private static function ensureStepUpNotLocked(Customer $customer, ?string $ip): void
    {
        self::throwWhenLocked([
            "portal-otp:step-up-fail:{$customer->id}" => self::FAILURES_PER_IDENTIFIER,
            'portal-otp:fail-ip:'.($ip ?? 'none') => self::FAILURES_PER_IP,
        ]);
    }

    private static function recordStepUpFailure(Customer $customer, ?string $ip): void
    {
        RateLimiter::hit("portal-otp:step-up-fail:{$customer->id}", self::WINDOW);
        RateLimiter::hit('portal-otp:fail-ip:'.($ip ?? 'none'), self::WINDOW);
        if (RateLimiter::attempts("portal-otp:step-up-fail:{$customer->id}") === self::FAILURES_PER_IDENTIFIER) {
            DB::transaction(fn () => Audit::record(AuditAction::PortalSignInLocked, $customer, null, after: ['ip' => $ip, 'step_up' => true],
                source: PortalSession::SOURCE, label: $customer->name));
        }
    }

    /**
     * @param  array<string, int>  $locks
     */
    private static function throwWhenLocked(array $locks): void
    {
        foreach ($locks as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OtpThrottled(RateLimiter::availableIn($key));
            }
        }
    }

    /**
     * The account behind an identifier whether or not its workspace is open (daily cap and checkout use it).
     */
    private static function anyAccount(string $identifier): ?Customer
    {
        $column = str_contains($identifier, '@') ? 'email' : 'mobile';

        return Customer::query()->where($column, $identifier)->first();
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
