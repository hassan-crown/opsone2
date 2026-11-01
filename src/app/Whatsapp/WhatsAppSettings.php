<?php

namespace App\Whatsapp;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The facility's WhatsApp switches: on/off and which events send. No credentials live here — they come from the
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
     * @param  list<string>  $events
     */
    public function save(User $actor, bool $enabled, array $events): IntegrationSetting
    {
        $events = array_values(array_intersect(WhatsAppEvent::values(), $events));

        return DB::transaction(function () use ($actor, $enabled, $events) {
            $row = $this->row() ?? new IntegrationSetting(['key' => IntegrationSetting::WHATSAPP]);
            $before = $row->exists ? ['enabled' => (bool) $row->enabled, 'events' => $this->events()] : [];
            $row->forceFill(['enabled' => $enabled, 'config' => ['events' => $events], 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::WhatsappSettingsSaved, $row, $actor, before: $before, after: ['enabled' => $enabled, 'events' => $events], label: 'WhatsApp');

            return $row;
        });
    }
}
