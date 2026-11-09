<?php

use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\AiSuggestions;
use App\Ai\AiSuggestionStatus;
use App\Ai\AiTask;
use App\Models\Ai\AiSuggestion;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Ai;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->org = Org::make();
    Ai::license(credits: 10);
    Ai::enable($this->org->owner, AiFeature::Inbox);
    Ai::fake()->respond(['reply' => 'أهلاً سارة']);
    $this->result = app(AiGateway::class)->run(Ai::replyCall());
    $this->suggestions = app(AiSuggestions::class);
});

it('stores a draft encrypted and records the edit when a staff member changes it', function () {
    $s = $this->suggestions->propose(AiTask::ReplySuggest, $this->result, $this->result->output, $this->org->owner);

    expect(DB::table('ai_suggestions')->value('payload'))->not->toContain('سارة')
        ->and($s->fresh()->payload)->toBe(['reply' => 'أهلاً سارة'])
        ->and($s->toArray())->not->toHaveKey('payload');

    $decided = $this->suggestions->accept($s, $this->org->owner, ['reply' => 'أهلاً سارة، كيف نخدمك؟']);

    expect($decided->status)->toBe(AiSuggestionStatus::Edited)
        ->and($decided->final_payload)->toBe(['reply' => 'أهلاً سارة، كيف نخدمك؟'])
        ->and(AuditEvent::query()->where('action', 'ai.suggestion_accepted')->sole()->after)->toBe(['task' => 'reply.suggest', 'status' => 'edited']);
});

it('decides a suggestion once and expires the forgotten ones', function () {
    $a = $this->suggestions->propose(AiTask::ReplySuggest, $this->result, ['reply' => 'a']);
    $b = $this->suggestions->propose(AiTask::ReplySuggest, $this->result, ['reply' => 'b']);

    $this->suggestions->reject($a, $this->org->owner);
    expect(fn () => $this->suggestions->accept($a, $this->org->owner))->toThrow(LogicException::class);

    $this->travel(15)->days();
    $this->artisan('ai:expire-suggestions')->assertSuccessful();

    expect($b->fresh()->status)->toBe(AiSuggestionStatus::Expired)
        ->and($a->fresh()->status)->toBe(AiSuggestionStatus::Rejected)
        ->and(AiSuggestion::query()->count())->toBe(2);
});
