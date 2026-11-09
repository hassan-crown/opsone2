<?php

namespace App\Ai;

use App\Models\Ai\AiSuggestion;
use App\Models\Ai\AiUsage;
use Carbon\CarbonImmutable;

/**
 * The optional `ai` block of the sync heartbeat (central contract `sync/v1`, ADR-0050 §6): monthly totals of the
 * facility's current month, aggregated from `ai_usage` and `ai_suggestions`. Numbers only — never a prompt, an
 * answer, a user, a subject or a driver. The schema carries one period per heartbeat, so only the current month is
 * sent (central keeps the latest report of each month, so the last heartbeat of a month is its final total).
 *
 * Returns null — the block is omitted — when the facility has made no AI call this month: nothing to report, and a
 * facility without the assistant (or an older central) sees an unchanged heartbeat.
 */
final readonly class AiHeartbeat
{
    private const int MAX_COUNT = 4294967295;

    private const int MAX_TOKENS = 1099511627776;

    private const int MAX_MODELS = 20;

    public function __construct(private AiCredits $credits) {}

    /**
     * @return array<string, mixed>|null
     */
    public function block(?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();
        $start = $this->credits->monthStart();
        $end = $start->setTimezone(AiCredits::timezone())->addMonth()->startOfMonth()->utc();
        $period = $start->setTimezone(AiCredits::timezone())->format('Y-m');

        // A month that has not begun anywhere is refused by central (UTC+14 is the most advanced zone).
        if ($period > $now->utc()->addHours(14)->format('Y-m')) {
            return null;
        }

        $rows = AiUsage::query()->where('created_at', '>=', $start)->where('created_at', '<', $end);

        if (! (clone $rows)->exists()) {
            return null;
        }

        $ok = AiOutcome::Ok->value;

        $totals = (clone $rows)->selectRaw('COALESCE(SUM(cost_micro_usd), 0) as cost')->first();
        $credits = (int) (clone $rows)->where('outcome', $ok)->sum('credits');

        $models = (clone $rows)
            ->selectRaw('model, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cache_read_tokens) as cached_tokens')
            ->groupBy('model')
            ->orderBy('model')
            ->get()
            ->filter(fn ($row) => preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', (string) $row->model) === 1 && strlen((string) $row->model) <= 64)
            ->take(self::MAX_MODELS)
            ->map(fn ($row): array => [
                'model' => (string) $row->model,
                'input_tokens' => min(self::MAX_TOKENS, (int) $row->input_tokens),
                'output_tokens' => min(self::MAX_TOKENS, (int) $row->output_tokens),
                'cached_tokens' => min(self::MAX_TOKENS, (int) $row->cached_tokens),
            ])
            ->values()
            ->all();

        $accepted = AiSuggestion::query()
            ->whereIn('status', [AiSuggestionStatus::Accepted->value, AiSuggestionStatus::Edited->value])
            ->where('decided_at', '>=', $start)->where('decided_at', '<', $end)
            ->count();

        return [
            'period' => $period,
            'credits_used' => min(self::MAX_COUNT, $credits),
            'cost_usd_micro' => min(self::MAX_TOKENS, (int) ($totals->cost ?? 0)),
            'suggestions_accepted' => min(self::MAX_COUNT, $accepted),
            'models' => $models,
        ];
    }
}
