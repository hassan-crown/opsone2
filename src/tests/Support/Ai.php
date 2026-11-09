<?php

namespace Tests\Support;

use App\Ai\AiCall;
use App\Ai\AiFeature;
use App\Ai\AiSettings;
use App\Ai\AiTask;
use App\Ai\Contracts\AiProvider;
use App\Ai\Drivers\FakeAiProvider;
use App\Models\User;

/**
 * AI test helpers (ADR-0050): a fake driver bound in the container, a license with AI capabilities and credits.
 */
final class Ai
{
    public static function fake(): FakeAiProvider
    {
        config(['ai.driver' => 'fake']);
        $fake = new FakeAiProvider;
        app()->instance(AiProvider::class, $fake);

        return $fake;
    }

    /**
     * @param  list<string>  $capabilities
     */
    public static function license(int $credits, array $capabilities = ['ai_core', 'ai_plus']): void
    {
        LicenseFactory::install([
            'capabilities' => ['ready_goods', 'branch_comparisons', 'marketing_reports', ...$capabilities],
            'limits' => ['seats' => 15, 'branches' => 3, 'storage_mb' => 25600, 'ai_credits' => $credits],
        ]);
    }

    public static function enable(User $owner, AiFeature ...$features): void
    {
        app(AiSettings::class)->save($owner, array_fill_keys(array_map(fn (AiFeature $f) => $f->value, $features), true));
    }

    /** A reply suggestion call with a small schema; `facts` hold the trusted values. */
    public static function replyCall(string $instruction = 'اقترح رداً على العميل.', array $facts = [], array $untrusted = [], array $known = []): AiCall
    {
        return new AiCall(
            task: AiTask::ReplySuggest,
            system: 'You suggest a short reply in Arabic. Use only the facts given.',
            instruction: $instruction,
            schema: [
                'type' => 'object',
                'properties' => ['reply' => ['type' => 'string', 'maxLength' => 2000]],
                'required' => ['reply'],
                'additionalProperties' => false,
            ],
            facts: $facts,
            untrusted: $untrusted,
            known: $known,
        );
    }
}
