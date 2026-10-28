<?php

namespace App\Finance\Gateway\Moyasar;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The facility's Moyasar merchant account (Q-45, ADR-0023; SRS-SET-06 credential sourcing): stored in the encrypted
 * `integration_settings.config`, never serialized to the browser (`view()` carries no secret). TEST mode only in this
 * release: a live key is refused. When the facility saved nothing, the TEST values from `config/services.php` (env) are
 * used for local work.
 */
final class MoyasarSettings
{
    public const KEY = 'moyasar';

    public const MODE = 'test';

    public function row(): ?IntegrationSetting
    {
        return IntegrationSetting::query()->where('key', self::KEY)->first();
    }

    /**
     * @return array{publishable_key: string, secret_key: string, webhook_secret: string}|null
     */
    public function credentials(): ?array
    {
        $row = $this->row();
        $config = is_array($row?->config) ? $row->config : [];
        $values = $row !== null
            ? ['publishable_key' => $config['publishable_key'] ?? null, 'secret_key' => $config['secret_key'] ?? null, 'webhook_secret' => $config['webhook_secret'] ?? null]
            : ['publishable_key' => config('services.moyasar.publishable_key'), 'secret_key' => config('services.moyasar.secret_key'), 'webhook_secret' => config('services.moyasar.webhook_secret')];
        if ($row !== null && ! $row->enabled) {
            return null;
        }
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                return null;
            }
        }
        /** @var array{publishable_key: string, secret_key: string, webhook_secret: string} $values */
        if (! self::isTestKey($values['secret_key'], 'sk') || ! self::isTestKey($values['publishable_key'], 'pk')) {
            return null;
        }

        return $values;
    }

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * What the settings screen may see: never a secret.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $row = $this->row();
        $config = is_array($row?->config) ? $row->config : [];

        return [
            'mode' => self::MODE,
            'publishable_key' => (string) ($config['publishable_key'] ?? ''),
            'has_secret_key' => ! empty($config['secret_key']),
            'has_webhook_secret' => ! empty($config['webhook_secret']),
            'enabled' => (bool) $row?->enabled,
            'configured' => $this->configured(),
            'from_environment' => $row === null && $this->configured(),
            'webhook_url' => route('webhooks.payments', ['gateway' => self::KEY]),
        ];
    }

    /**
     * Saves the account (blank secrets keep the saved ones). Live keys are refused.
     *
     * @param  array{publishable_key: string, secret_key?: string|null, webhook_secret?: string|null, enabled?: bool|null}  $data
     */
    public function save(User $actor, array $data): IntegrationSetting
    {
        return DB::transaction(function () use ($actor, $data) {
            $row = IntegrationSetting::query()->where('key', self::KEY)->lockForUpdate()->first() ?? new IntegrationSetting(['key' => self::KEY]);
            $old = is_array($row->config) ? $row->config : [];
            $new = [
                'mode' => self::MODE,
                'publishable_key' => trim($data['publishable_key']),
                'secret_key' => ($data['secret_key'] ?? '') === '' ? ($old['secret_key'] ?? null) : trim((string) $data['secret_key']),
                'webhook_secret' => ($data['webhook_secret'] ?? '') === '' ? ($old['webhook_secret'] ?? null) : (string) $data['webhook_secret'],
            ];
            if (! self::isTestKey($new['publishable_key'], 'pk')) {
                throw ValidationException::withMessages(['publishable_key' => __('store.payments.validation.test_key_only')]);
            }
            if (! is_string($new['secret_key']) || ! self::isTestKey($new['secret_key'], 'sk')) {
                throw ValidationException::withMessages(['secret_key' => __('store.payments.validation.test_key_only')]);
            }
            if (! is_string($new['webhook_secret']) || mb_strlen($new['webhook_secret']) < 16) {
                throw ValidationException::withMessages(['webhook_secret' => __('store.payments.validation.webhook_secret')]);
            }
            $row->config = $new;
            $row->enabled = (bool) ($data['enabled'] ?? true);
            $row->updated_by = $actor->id;
            $row->save();

            // Never a key or secret in the trail: only what changed in shape.
            Audit::record(AuditAction::PaymentGatewaySettingsSaved, $row, $actor,
                before: $old === [] ? [] : ['mode' => self::MODE, 'has_secret_key' => ! empty($old['secret_key'])],
                after: [
                    'mode' => self::MODE, 'enabled' => $row->enabled,
                    'secret_changed' => ($old['secret_key'] ?? null) !== $new['secret_key'],
                    'webhook_secret_changed' => ($old['webhook_secret'] ?? null) !== $new['webhook_secret'],
                ],
                label: 'Moyasar',
            );

            return $row;
        });
    }

    public static function isTestKey(string $key, string $prefix): bool
    {
        return preg_match('/^'.$prefix.'_test_[A-Za-z0-9]{8,120}$/', $key) === 1;
    }
}
