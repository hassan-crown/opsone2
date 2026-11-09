<?php

use App\Ai\Onboarding\FlowDraftProposer;
use App\Models\Ai\AiFlowSetup;
use App\Workflow\FlowTemplates;
use Tests\TestCase;

function flowSetupDescription(): string
{
    return 'نزور الموقع أولاً ونصوّر، ثم نقيس ونصمم ونرسل العرض. الدفعة الأولى 60% بعد موافقة العميل على العرض، '
        .'والباقي عند التسليم بعد التركيب. التصنيع عندنا في الورشة ويأخذ 21 يوم.';
}

/**
 * One action in the answer's flat shape, every field present (null when unused).
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function flowSetupAction(string $key, string $type, array $fields = []): array
{
    return [
        'key' => $key, 'type' => $type, 'name' => $fields['name'] ?? 'إجراء '.$key, 'required' => true, 'applies_when' => 'always', 'after' => null,
        'document' => null, 'approval_subject' => null, 'payment_reference' => null, 'workshop_reference' => null, 'workshop_milestone' => null,
        'supply_reference' => null, 'document_type' => null, 'measurement_purpose' => null, 'measurement_kind' => null,
        'requires_attachment' => null, 'task_label' => null, ...$fields,
    ];
}

/**
 * @param  list<array<string, mixed>>  $actions
 * @return array<string, mixed>
 */
function flowSetupStage(string $key, string $name, array $actions, int $days = 3, string $origin = 'description'): array
{
    return ['key' => $key, 'name' => $name, 'customer_name' => null, 'target_days' => $days, 'follow_up' => 'order_assignee', 'origin' => $origin, 'actions' => $actions];
}

/**
 * A valid model answer: the in-house template with a site-visit stage first, 60% at the agreement and the rest at
 * delivery, plus the given rules.
 *
 * @param  list<array<string, mixed>>  $rules
 * @return array<string, mixed>
 */
function flowSetupOutput(array $rules = []): array
{
    $stages = array_map(fn (array $s) => [
        ...$s,
        'origin' => 'template',
        'actions' => array_map(fn (array $a) => flowSetupAction($a['key'], $a['type'], $a), $s['actions']),
    ], FlowDraftProposer::flat(FlowTemplates::definition(FlowTemplates::IN_HOUSE)['stages']));

    array_unshift($stages, flowSetupStage('site_visit', 'زيارة الموقع', [
        flowSetupAction('visit', 'work_task', ['name' => 'زيارة الموقع والتصوير', 'task_label' => 'زيارة الموقع', 'requires_attachment' => true]),
    ], 2));

    return [
        'base_template' => FlowTemplates::IN_HOUSE,
        'summary' => 'أضفنا زيارة الموقع قبل القياس، والدفعة الأولى 60% عند الاتفاق.',
        'stages' => $stages,
        'payment_plan' => ['name' => 'المقدم والباقي عند التسليم', 'lines' => [
            ['key' => 'first', 'name' => 'الدفعة الأولى', 'percent' => 60, 'event' => 'agreement', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
            ['key' => 'final', 'name' => 'الباقي', 'percent' => null, 'event' => 'delivery.completed', 'stage_key' => null, 'offset_days' => 0, 'grace_days' => 0],
        ]],
        'automation' => $rules,
        'questions' => [['question' => 'هل تصوير الموقع إلزامي في كل طلب؟', 'about' => 'site_visit']],
    ];
}

/**
 * Runs a proposal as the owner with the given model answer and returns the request.
 *
 * @param  array<string, mixed>  $output
 */
function readyFlowSetup(TestCase $test, array $output): AiFlowSetup
{
    $test->fake->respond($output);
    $test->actingAs($test->org->owner)->post('/settings/ai-setup', ['description' => flowSetupDescription()]);

    return AiFlowSetup::query()->orderByDesc('id')->firstOrFail();
}
