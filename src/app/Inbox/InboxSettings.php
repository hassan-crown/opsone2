<?php

namespace App\Inbox;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Branch;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\RequestCache;
use Illuminate\Support\Facades\DB;

/**
 * The facility's WhatsApp inbox switches (AI-3), edited by the owner with P-02 on Settings → Integrations and audited:
 * on/off, the branch that receives conversations from numbers not linked to a customer, and the templates Meta approved
 * for replying outside the 24-hour window. Nothing secret lives here: credentials stay in the environment (ADR-0030).
 *
 * Off means: inbound messages are not stored (delivery statuses and STOP keep working, ADR-0030), the inbox pages
 * answer 404 and nothing can be sent from them.
 */
final class InboxSettings
{
    public const MAX_TEMPLATES = 30;

    public const MAX_PARAMS = 10;

    private const CACHE_KEY = 'integrations.whatsapp_inbox';

    /** One read per request: the shared navigation, the page and the webhook all ask (ADR-0027). */
    public function row(): ?IntegrationSetting
    {
        return app(RequestCache::class)->remember(self::CACHE_KEY, fn () => IntegrationSetting::query()->where('key', IntegrationSetting::WHATSAPP_INBOX)->first());
    }

    public function enabled(): bool
    {
        return (bool) $this->row()?->enabled;
    }

    public function defaultBranchId(): ?int
    {
        $id = $this->row()?->config['default_branch_id'] ?? null;

        return is_int($id) && Branch::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Approved reply templates: name (as approved at Meta), language code, number of body parameters, and a label.
     *
     * @return list<array{name: string, language: string, params: int, label: string}>
     */
    public function templates(): array
    {
        $templates = $this->row()?->config['templates'] ?? null;
        if (! is_array($templates)) {
            return [];
        }

        $out = [];
        foreach ($templates as $t) {
            if (is_array($t) && is_string($t['name'] ?? null) && is_string($t['language'] ?? null)) {
                $out[] = [
                    'name' => $t['name'],
                    'language' => $t['language'],
                    'params' => (int) ($t['params'] ?? 0),
                    'label' => is_string($t['label'] ?? null) && $t['label'] !== '' ? $t['label'] : $t['name'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, language: string, params: int, label: string}|null
     */
    public function template(string $name, string $language): ?array
    {
        foreach ($this->templates() as $t) {
            if ($t['name'] === $name && $t['language'] === $language) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param  list<array{name: string, language: string, params: int, label?: string|null}>  $templates  validated
     */
    public function save(User $actor, bool $enabled, ?int $defaultBranchId, array $templates): IntegrationSetting
    {
        $templates = array_map(fn (array $t) => [
            'name' => $t['name'],
            'language' => $t['language'],
            'params' => (int) $t['params'],
            'label' => trim((string) ($t['label'] ?? '')),
        ], $templates);

        return DB::transaction(function () use ($actor, $enabled, $defaultBranchId, $templates) {
            $row = $this->row() ?? new IntegrationSetting(['key' => IntegrationSetting::WHATSAPP_INBOX]);
            $before = $row->exists ? ['enabled' => (bool) $row->enabled, 'default_branch_id' => $row->config['default_branch_id'] ?? null, 'templates' => count($this->templates())] : [];
            $row->forceFill([
                'enabled' => $enabled,
                'config' => ['default_branch_id' => $defaultBranchId, 'templates' => $templates],
                'updated_by' => $actor->id,
            ])->save();
            app(RequestCache::class)->flush();
            Audit::record(AuditAction::WhatsappInboxSettingsSaved, $row, $actor, before: $before, after: ['enabled' => $enabled, 'default_branch_id' => $defaultBranchId, 'templates' => count($templates)], label: 'WhatsApp inbox');

            return $row;
        });
    }
}
