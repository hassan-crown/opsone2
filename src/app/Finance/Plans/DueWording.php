<?php

namespace App\Finance\Plans;

use App\Enums\DueEvent;
use App\Models\Flow;

/**
 * How a due's trigger reads for people (PDF schedule, plan lists): «عند بدء مرحلة التركيب» or «بعد التسليم بـ 15 يوماً».
 * The stage name comes from the published flow; an unknown stage shows its key.
 */
final class DueWording
{
    public static function of(DueEvent $event, ?string $stageKey, int $offsetDays, string $locale): string
    {
        $base = __('esign.pdf.due.'.$event->value, ['stage' => $stageKey === null ? '' : self::stageName($stageKey, $locale)], $locale);

        return $offsetDays === 0 ? $base : __('finance.plans.offset', ['when' => $base, 'days' => $offsetDays], $locale);
    }

    private static function stageName(string $key, string $locale): string
    {
        foreach (Flow::current()?->publishedVersion?->definition['stages'] ?? [] as $stage) {
            if (($stage['key'] ?? null) === $key) {
                return (string) ($stage['name'][$locale] ?? $stage['name']['ar'] ?? $key);
            }
        }

        return $key;
    }
}
