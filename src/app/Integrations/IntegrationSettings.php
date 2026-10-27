<?php

namespace App\Integrations;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the facility's integration accounts (SRS-SET-06). Secrets stay inside this class and the
 * encrypted `config` column: callers that feed the UI use `smtpView()`, which never contains the password.
 */
final class IntegrationSettings
{
    /** Connection fields: changing any of them invalidates the last successful test. */
    private const SMTP_CONNECTION = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    public function smtpRow(): ?IntegrationSetting
    {
        return IntegrationSetting::query()->where('key', IntegrationSetting::SMTP)->first();
    }

    /**
     * The usable SMTP account: enabled and complete, otherwise null (the application mailer is used instead).
     *
     * @return array{host: string, port: int, encryption: string, username: string|null, password: string|null, from_address: string, from_name: string|null}|null
     */
    public function smtp(): ?array
    {
        $row = $this->smtpRow();
        $config = $row?->config;

        if ($row === null || ! $row->enabled || ! is_array($config) || empty($config['host']) || empty($config['port']) || empty($config['from_address'])) {
            return null;
        }

        return [
            'host' => (string) $config['host'],
            'port' => (int) $config['port'],
            'encryption' => (string) ($config['encryption'] ?? 'tls'),
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'from_address' => (string) $config['from_address'],
            'from_name' => $config['from_name'] ?? null,
        ];
    }

    /**
     * Saved account for testing, enabled or not.
     *
     * @return array<string, mixed>|null
     */
    public function smtpDraft(): ?array
    {
        $config = $this->smtpRow()?->config;

        return is_array($config) && ! empty($config['host']) && ! empty($config['from_address']) ? $config : null;
    }

    /**
     * What the browser may see: everything but the password.
     *
     * @return array<string, mixed>
     */
    public function smtpView(): array
    {
        $row = $this->smtpRow();
        $config = is_array($row?->config) ? $row->config : [];

        return [
            'host' => $config['host'] ?? '',
            'port' => $config['port'] ?? 587,
            'encryption' => $config['encryption'] ?? 'tls',
            'username' => $config['username'] ?? '',
            'has_password' => ! empty($config['password']),
            'from_address' => $config['from_address'] ?? '',
            'from_name' => $config['from_name'] ?? '',
            'enabled' => (bool) $row?->enabled,
            'configured' => $this->smtpDraft() !== null,
            'tested_at' => $row?->tested_at?->toIso8601String(),
            'test_ok' => $row?->test_ok,
        ];
    }

    /**
     * Stores the account. A blank password keeps the saved one. Any connection change disables the account until
     * it passes a new test.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveSmtp(User $actor, array $data): IntegrationSetting
    {
        return DB::transaction(fn () => $this->writeSmtp($actor, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSmtp(User $actor, array $data): IntegrationSetting
    {
        $row = $this->smtpRow() ?? new IntegrationSetting(['key' => IntegrationSetting::SMTP]);
        $old = is_array($row->config) ? $row->config : [];

        $new = [
            'host' => (string) $data['host'],
            'port' => (int) $data['port'],
            'encryption' => (string) $data['encryption'],
            'username' => ($data['username'] ?? '') === '' ? null : (string) $data['username'],
            'password' => ($data['password'] ?? '') === '' ? ($old['password'] ?? null) : (string) $data['password'],
            'from_address' => (string) $data['from_address'],
            'from_name' => ($data['from_name'] ?? '') === '' ? null : (string) $data['from_name'],
        ];

        $changed = false;
        foreach (self::SMTP_CONNECTION as $field) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                $changed = true;
            }
        }

        $row->config = $new;
        if ($changed || ! $row->exists) {
            $row->enabled = false;
            $row->tested_at = null;
            $row->test_ok = null;
        }
        $row->updated_by = $actor->id;
        $row->save();

        // Never the password or username: only what changed in shape.
        Audit::record(AuditAction::SmtpSettingsSaved, $row, $actor,
            before: $old === [] ? [] : self::auditView($old),
            after: [...self::auditView($new), 'credential_changed' => ($old['password'] ?? null) !== $new['password'], 'enabled' => (bool) $row->enabled],
            label: 'SMTP',
        );

        return $row;
    }

    public function recordSmtpTest(User $actor, bool $ok): void
    {
        DB::transaction(function () use ($actor, $ok) {
            $row = $this->smtpRow();
            if ($row === null) {
                return;
            }
            $row->forceFill(['tested_at' => now(), 'test_ok' => $ok])->save();
            Audit::record(AuditAction::SmtpSettingsTested, $row, $actor, after: ['ok' => $ok], label: 'SMTP');
        });
    }

    /**
     * Enabling needs a successful test of the saved settings; disabling is always allowed (queued messages
     * then use the application mailer).
     */
    public function setSmtpEnabled(User $actor, bool $enabled): bool
    {
        $row = $this->smtpRow();

        if ($row === null || ($enabled && $row->test_ok !== true)) {
            return false;
        }

        DB::transaction(function () use ($row, $actor, $enabled) {
            $was = (bool) $row->enabled;
            $row->forceFill(['enabled' => $enabled, 'updated_by' => $actor->id])->save();
            Audit::record($enabled ? AuditAction::SmtpEnabled : AuditAction::SmtpDisabled, $row, $actor,
                before: ['enabled' => $was], after: ['enabled' => $enabled], label: 'SMTP');
        });

        return true;
    }

    /**
     * Connection facts safe for the audit trail: no credentials.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function auditView(array $config): array
    {
        return [
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'encryption' => $config['encryption'] ?? null,
            'from_address' => $config['from_address'] ?? null,
            'has_username' => ! empty($config['username']),
        ];
    }
}
