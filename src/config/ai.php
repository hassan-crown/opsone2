<?php

use App\Ai\Onboarding\FlowEvalBuilder;

/*
| AI assistant (ADR-0050, plan docs/analysis/ai-v1-plan.md §3). Everything here comes from the environment: the
| provider key lives in src/.env of this deployment only (Q-95) — never in the database, the license or the UI.
|
| Nothing leaves the server by default: the driver is `log` (records metadata only, sends nothing). The
| `anthropic` driver additionally refuses to run unless AI_ANTHROPIC_ENABLED=true AND a key is set. Real use on
| real customer data stays blocked until the PDPL review (Q-94) is complete.
*/
return [
    // log (default: nothing leaves the server) | fake (tests) | anthropic (Claude API, official PHP SDK)
    'driver' => env('AI_DRIVER', 'log'),

    'anthropic' => [
        // Explicit second switch (Q-94). Without it the driver refuses every call even when AI_DRIVER=anthropic.
        'enabled' => (bool) env('AI_ANTHROPIC_ENABLED', false),
        // One key per facility workspace with its own spend limit at the provider (Q-95).
        'api_key' => env('AI_ANTHROPIC_API_KEY'),
        'base_url' => env('AI_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),
        // In-request retries on connection errors, 408/409/429 and 5xx (SDK default policy); the circuit breaker
        // stops further calls after repeated failures.
        'retries' => (int) env('AI_RETRIES', 2),
    ],

    // Seconds (plan §3.4). Interactive: TOTAL ceiling — at most one retry, each attempt gets half. Background: per
    // attempt, with AI_RETRIES retries.
    'timeouts' => [
        'interactive' => (int) env('AI_TIMEOUT_INTERACTIVE', 20),
        'background' => (int) env('AI_TIMEOUT_BACKGROUND', 120),
    ],

    // After N consecutive provider failures, no call is attempted for the cool-down (plan §3.4).
    'circuit_breaker' => [
        'failures' => (int) env('AI_CIRCUIT_FAILURES', 5),
        'cooldown_seconds' => (int) env('AI_CIRCUIT_COOLDOWN', 300),
    ],

    // Models per tier (plan §3.1). Change a model with an env variable, no deploy needed.
    'models' => [
        'fast' => env('AI_MODEL_FAST', 'claude-haiku-5-5'),
        'standard' => env('AI_MODEL_STANDARD', 'claude-sonnet-5-5'),
        'deep' => env('AI_MODEL_DEEP', 'claude-opus-5-5'),
    ],

    /*
    | Per task: model tier, effort, max output tokens, interactive or background, credit weight (plan §4.3) and
    | whether it should go through the Batch API once batch tasks are built. Weights are reviewed quarterly from
    | ai_usage. Weight 0 = included, limited by `fair_use_daily` instead.
    */
    'tasks' => [
        'intent.classify' => ['tier' => 'fast', 'effort' => 'low', 'max_tokens' => 512, 'interactive' => true, 'weight' => 0, 'batch' => false],
        'help.answer' => ['tier' => 'fast', 'effort' => 'low', 'max_tokens' => 2048, 'interactive' => true, 'weight' => 0, 'batch' => false],
        'autoreply.intent' => ['tier' => 'fast', 'effort' => 'low', 'max_tokens' => 512, 'interactive' => true, 'weight' => 0, 'batch' => false],
        'reply.suggest' => ['tier' => 'standard', 'effort' => 'low', 'max_tokens' => 4096, 'interactive' => true, 'weight' => 1, 'batch' => false],
        'order.draft' => ['tier' => 'standard', 'effort' => 'medium', 'max_tokens' => 8192, 'interactive' => true, 'weight' => 4, 'batch' => false],
        'lead.qualify' => ['tier' => 'standard', 'effort' => 'low', 'max_tokens' => 4096, 'interactive' => true, 'weight' => 1, 'batch' => false],
        'data.ask' => ['tier' => 'standard', 'effort' => 'medium', 'max_tokens' => 8192, 'interactive' => true, 'weight' => 3, 'batch' => false],
        'quote.followup' => ['tier' => 'standard', 'effort' => 'medium', 'max_tokens' => 4096, 'interactive' => false, 'weight' => 1, 'batch' => true],
        'digest.daily' => ['tier' => 'standard', 'effort' => 'medium', 'max_tokens' => 8192, 'interactive' => false, 'weight' => 1, 'batch' => true],
        'content.draft' => ['tier' => 'standard', 'effort' => 'medium', 'max_tokens' => 8192, 'interactive' => false, 'weight' => 1, 'batch' => true],
        'flow.propose' => ['tier' => 'deep', 'effort' => 'high', 'max_tokens' => 16000, 'interactive' => false, 'weight' => 0, 'batch' => false],
        'lost.analysis' => ['tier' => 'deep', 'effort' => 'high', 'max_tokens' => 16000, 'interactive' => false, 'weight' => 5, 'batch' => true],
        'measure.read' => ['tier' => 'deep', 'effort' => 'high', 'max_tokens' => 8192, 'interactive' => true, 'weight' => 5, 'batch' => false],
    ],

    // Daily cap on weight-0 (included) calls per task, so «free» never means unbounded (plan §4.3).
    'fair_use_daily' => (int) env('AI_FAIR_USE_DAILY', 300),

    // Share of the monthly credits that triggers the owner's alert (plan §3.4).
    'alert_ratio' => 0.8,

    /*
    | USD per million tokens, official price page read 2026-11-08 (plan §4.1). Used for the cost column of
    | ai_usage (micro-dollars). Unknown models are recorded at cost 0 and should be added here.
    */
    'prices' => [
        'claude-haiku-5-5' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
        'claude-sonnet-5-5' => ['input' => 2.00, 'cache_write' => 2.50, 'cache_read' => 0.10, 'output' => 10.00],
        'claude-opus-5-5' => ['input' => 4.00, 'cache_write' => 5.00, 'cache_read' => 0.20, 'output' => 20.00],
    ],

    // AiGateway refuses to run inside a database transaction (the provider call must not hold row locks). Tests that
    // wrap each case in a transaction (RefreshDatabase) set the baseline to 1 in phpunit.xml. Never raise it elsewhere.
    'transaction_baseline' => (int) env('AI_TRANSACTION_BASELINE', 0),

    // AI-6 automatic reply (Q-92): at most `daily_cap` automatic messages per conversation in 24 hours (hand-off notes
    // included), and below `min_confidence` (0–1) the classifier's label is ignored and the message goes to staff.
    'auto_reply' => [
        'daily_cap' => (int) env('AI_AUTO_REPLY_DAILY_CAP', 3),
        'min_confidence' => (float) env('AI_AUTO_REPLY_MIN_CONFIDENCE', 0.8),
    ],

    // A suggestion nobody acted on expires (ai_suggestions.status = expired).
    'suggestion_ttl_days' => (int) env('AI_SUGGESTION_TTL_DAYS', 14),

    // Manual evaluation set (plan §3.6). Runs cost money with a real driver; see `php artisan ai:eval --help`.
    'eval_path' => base_path('tests/AiEval/fixtures'),

    // Fixture lines with `"builder": "<name>"` get their call from the feature itself (App\Ai\Contracts\AiEvalBuilder).
    'eval_builders' => [
        'flow' => FlowEvalBuilder::class,
    ],
];
