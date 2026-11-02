<?php

namespace App\Whatsapp;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The facility's WhatsApp switches: on/off, which events send and, per event, the name of the template Meta approved
 * for this facility (ADR-0037; absent = the environment's name). No credentials live here — they come from the
 * environment (config/whatsapp.php) — so the row holds nothing secret.
 */
final class WhatsAppSettings
{
    public function row(): ?IntegrationSetting
    {
        return IntegrationSetting::query()->where('key', IntegrationSetting::WHATSAPP)->first();
    }

    public function enabled(): bool
    {
        return (bool) $this->row()?->enabled;
    }

    /**
     * @return list<string> event values
     */
    public function events(): array
    {
        $events = $this->row()?->config['events'] ?? null;

        return is_array($events) ? array_values(array_intersect(WhatsAppEvent::values(), $events)) : WhatsAppEvent::values();
    }

    public function allows(WhatsAppEvent $event): bool
    {
        return $this->enabled() && in_array($event->value, $this->events(), true);
    }

    /**
     * Facility template names per event value (only overrides).
     *
     * @return array<string, string>
     */
    public function templateNames(): array
    {
        $names = $this->row()?->config['templates'] ?? null;

        return is_array($names) ? array_filter(array_intersect_key($names, array_flip(WhatsAppEvent::values())), 'is_string') : [];
    }

    /**
     * Saves the approved template names (P-104). An empty name falls back to the environment's.
     *
     * @param  array<string, string|null>  $names
     */
    public function saveTemplateNames(User $actor, array $names): IntegrationSetting
    {
        $names = array_filter(array_map(fn (?string $n) => $n === null ? '' : trim($n), array_intersect_key($names, array_flip(WhatsAppEvent::values()))), fn (string $n) => $n !== '');
        foreach ($names as $event => $name) {
            if (preg_match('/^[a-z0-9_]{1,250}$/', $name) !== 1) {
                throw ValidationException::withMessages(["templates.{$event}" => __('rules.messages.validation.template_name')]);
            }
        }

        return DB::transaction(function () use ($actor, $names) {
            $row = $this->row() ?? new IntegrationSetting(['key' => IntegrationSetting::WHATSAPP, 'enabled' => false]);
            $before = $this->templateNames();
            $row->forceFill(['config' => [...($row->config ?? []), 'templates' => $names], 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::WhatsappTemplateNamesSaved, $row, $actor, before: ['templates' => $before], after: ['templates' => $names], label: 'WhatsApp');

            return $row;
        });
    }

    /**
     * @param  list<string>  $events
     */
    public function save(User $actor, bool $enabled, array $events): IntegrationSetting
    {
        $events = array_values(array_intersect(WhatsAppEvent::values(), $events));

        return DB::transaction(function () use ($actor, $enabled, $events) {
            $row = $this->row() ?? new IntegrationSetting(['key' => IntegrationSetting::WHATSAPP]);
            $before = $row->exists ? ['enabled' => (bool) $row->enabled, 'events' => $this->events()] : [];
            $row->forceFill(['enabled' => $enabled, 'config' => [...($row->config ?? []), 'events' => $events], 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::WhatsappSettingsSaved, $row, $actor, before: $before, after: ['enabled' => $enabled, 'events' => $events], label: 'WhatsApp');

            return $row;
        });
    }
}
