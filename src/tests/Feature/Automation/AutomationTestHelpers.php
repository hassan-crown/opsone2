<?php

use App\Actions\Rules\SaveAutomationRule;
use App\Models\Automation\AutomationRule;
use App\Models\Automation\AutomationRun;
use App\Models\User;

require_once __DIR__.'/../Rules/RulesTestHelpers.php';

/**
 * Drafts a rule through the owner's action (P-104); publish with publishRules() for it to act.
 *
 * @param  list<array<string, mixed>>  $actions
 * @param  list<array<string, mixed>>  $conditions
 * @param  array<string, int>  $params
 */
function autoRule(User $actor, string $key, string $trigger, array $actions, array $conditions = [], array $params = [], bool $enabled = true, string $match = 'all'): AutomationRule
{
    return app(SaveAutomationRule::class)->handle($actor, null, [
        'key' => $key, 'name' => ['ar' => 'قاعدة '.$key, 'en' => null], 'trigger' => $trigger, 'params' => $params,
        'conditions' => ['match' => $match, 'items' => $conditions], 'actions' => $actions, 'enabled' => $enabled,
    ], null);
}

/** @return array<string, mixed> */
function noteAction(string $text = 'ملاحظة آلية على {order}'): array
{
    return ['type' => 'add_note', 'body' => ['ar' => $text, 'en' => null]];
}

/** @return list<string> outcome[:reason] per run of a rule, oldest first */
function runsOf(string $key): array
{
    return AutomationRun::query()->where('rule_key', $key)->orderBy('id')->get()
        ->map(fn (AutomationRun $r) => $r->outcome.($r->reason === null ? '' : ':'.$r->reason))->all();
}
