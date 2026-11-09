<?php

/**
 * Axis-1 evaluation set (plan §3.6): 30 descriptions across the 9 sectors, built through FlowDraftProposer by the
 * `flow` builder (so the set always runs the real prompt and schema), and graded by FlowCheck/ConfigCheck too.
 */

use App\Ai\Onboarding\FlowDraftProposer;
use App\Ai\Onboarding\FlowEvalBuilder;
use App\Ai\Onboarding\FlowSetupSchema;
use App\Workflow\FlowProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\Ai;
use Tests\Support\Org;

require_once __DIR__.'/FlowSetupHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Org::make();
    app(FlowProvisioner::class)->ensure();
});

it('holds at least 30 examples across the 9 sectors, each building the production call', function () {
    $lines = array_values(array_filter(file(base_path('tests/AiEval/fixtures/flow.jsonl')) ?: [], fn (string $l) => trim($l) !== ''));
    $examples = array_map(fn (string $l) => json_decode($l, true, 64, JSON_THROW_ON_ERROR), $lines);

    expect(count($examples))->toBeGreaterThanOrEqual(30)
        ->and(array_values(array_unique(array_column($examples, 'sector'))))->toHaveCount(9)
        ->and(array_unique(array_column($examples, 'id')))->toHaveCount(count($examples));

    $builder = app(FlowEvalBuilder::class);
    foreach ($examples as $example) {
        $call = $builder->build($example);
        expect($call->schema)->toBe(FlowSetupSchema::schema())
            ->and($call->system)->toBe(FlowDraftProposer::SYSTEM)
            ->and($call->untrusted[0])->toContain($example['description']);
    }
});

it('grades an answer with FlowCheck and ConfigCheck through ai:eval', function () {
    $dir = storage_path('framework/testing/ai-eval-'.uniqid());
    mkdir($dir, 0755, true);
    file_put_contents("{$dir}/flow.jsonl", json_encode([
        'id' => 'sample', 'builder' => 'flow', 'task' => 'flow.propose', 'sector' => 'kitchens', 'description' => flowSetupDescription(),
        'expect' => ['outcome' => 'ok', 'flow_check' => true, 'equals' => ['base_template' => 'in_house', 'payment_plan.lines.0.percent' => 60]],
    ], JSON_UNESCAPED_UNICODE)."\n");
    config(['ai.eval_path' => $dir]);

    $fake = Ai::fake();
    $fake->respond(flowSetupOutput());
    $this->artisan('ai:eval', ['axis' => 'flow', '--limit' => 1])->assertSuccessful();

    $bad = flowSetupOutput();
    $bad['stages'] = [flowSetupStage('pay', 'الدفع', [flowSetupAction('first_payment', 'payment_check', ['payment_reference' => 'first'])])];
    $fake->respond($bad);
    $this->artisan('ai:eval', ['axis' => 'flow', '--limit' => 1])
        ->expectsOutputToContain('flow/config check')
        ->assertFailed();

    File::deleteDirectory($dir);
});
