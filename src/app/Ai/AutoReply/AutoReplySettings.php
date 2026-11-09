<?php

namespace App\Ai\AutoReply;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Inbox\InboxSettings;
use App\Models\IntegrationSetting;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The owner's automatic-reply choices (Q-92: the facility OWNER only, holding P-02 — not a manager), kept in the AI settings row (`integration_settings` key `ai`,
 * `config.auto_reply`) next to the feature switches. Everything is off by default: each intent is switched on on its
 * own, to answer always or only outside working hours. Optional: a fixed «a colleague will answer you» note on
 * hand-off, and the approved one-parameter template used when Meta's 24-hour window has closed.
 *
 * The AI feature switch `auto_reply` (AiSettings) and the `ai_plus` license still decide whether anything runs.
 */
final class AutoReplySettings
{
    public const ALWAYS = 'always';

    public const OUTSIDE_HOURS = 'outside_hours';

    public const MODES = [self::ALWAYS, self::OUTSIDE_HOURS];

    public function __construct(private readonly InboxSettings $inbox) {}

    /**
     * Q-92: only the facility's owner decides whether messages are answered without staff — a manager with P-02 does
     * not. Support identities never.
     */
    public static function canManage(?User $user): bool
    {
        if ($user === null || $user->is_support) {
            return false;
        }
        $membership = Membership::query()->where('user_id', $user->id)->first();

        return $membership !== null && $membership->isOwner() && Access::can($user, Permission::P02);
    }

    /**
     * @return array{intents: array<string, array{enabled: bool, mode: string}>, handoff_note: bool, template: array{name: string, language: string}|null}
     */
    public function all(): array
    {
        $saved = IntegrationSetting::query()->where('key', IntegrationSetting::AI)->first()?->config['auto_reply'] ?? [];
        $saved = is_array($saved) ? $saved : [];
        $intents = [];
        foreach (AutoReplyIntent::cases() as $intent) {
            $row = is_array($saved['intents'][$intent->value] ?? null) ? $saved['intents'][$intent->value] : [];
            $mode = in_array($row['mode'] ?? null, self::MODES, true) ? $row['mode'] : self::OUTSIDE_HOURS;
            $intents[$intent->value] = ['enabled' => ($row['enabled'] ?? false) === true, 'mode' => $mode];
        }

        $template = is_array($saved['template'] ?? null) ? $saved['template'] : null;
        $template = $template !== null && is_string($template['name'] ?? null) && is_string($template['language'] ?? null)
            ? ['name' => $template['name'], 'language' => $template['language']]
            : null;

        return ['intents' => $intents, 'handoff_note' => ($saved['handoff_note'] ?? false) === true, 'template' => $template];
    }

    /**
     * @return array{enabled: bool, mode: string}
     */
    public function intent(AutoReplyIntent $intent): array
    {
        return $this->all()['intents'][$intent->value];
    }

    public function anyEnabled(): bool
    {
        return in_array(true, array_column($this->all()['intents'], 'enabled'), true);
    }

    /**
     * The approved template for a closed window, only while it is still on the owner's approved list with exactly one
     * parameter (the filled answer).
     *
     * @return array{name: string, language: string, params: int, label: string}|null
     */
    public function windowTemplate(): ?array
    {
        $chosen = $this->all()['template'];
        $template = $chosen === null ? null : $this->inbox->template($chosen['name'], $chosen['language']);

        return $template !== null && $template['params'] === 1 ? $template : null;
    }

    /**
     * @param  array{intents: array<string, array{enabled: bool, mode: string}>, handoff_note: bool, template: array{name: string, language: string}|null}  $values  validated
     */
    public function save(User $actor, array $values): void
    {
        DB::transaction(function () use ($actor, $values) {
            $row = IntegrationSetting::query()->where('key', IntegrationSetting::AI)->lockForUpdate()->first()
                ?? new IntegrationSetting(['key' => IntegrationSetting::AI, 'enabled' => true]);
            $before = $this->all();
            $after = ['intents' => [], 'handoff_note' => $values['handoff_note'], 'template' => $values['template']];
            foreach (AutoReplyIntent::cases() as $intent) {
                $after['intents'][$intent->value] = $values['intents'][$intent->value] ?? $before['intents'][$intent->value];
            }

            $row->forceFill(['config' => [...($row->config ?? []), 'auto_reply' => $after], 'updated_by' => $actor->id])->save();

            if ($before !== $after) {
                Audit::record(AuditAction::AiSettingsChanged, $row, $actor,
                    before: ['auto_reply' => $before],
                    after: ['auto_reply' => $after],
                    label: 'AI auto reply',
                );
            }
        });
    }
}
