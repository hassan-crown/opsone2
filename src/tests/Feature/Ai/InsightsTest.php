<?php

/**
 * AI-8 (ADR-0050, plan axis 6): the morning digest and «اسأل بياناتك». Branch A holds an agreement of 18,450.00
 * (1,845,000 halalas); branch B one of 1,000.00 (100,000). The branch-A manager must never see B's figures, in a tool
 * result, in what is sent to the provider, or in a digest.
 */

use App\Ai\AiFeature;
use App\Ai\AiOutcome;
use App\Ai\AiSettings;
use App\Ai\Insights\DailyDigest;
use App\Ai\Insights\DataAnswer;
use App\Ai\Insights\DataQuestion;
use App\Ai\Insights\InvalidToolCall;
use App\Ai\Insights\ReportTools;
use App\Ai\Insights\SendDailyDigest;
use App\Models\Ai\AiUsage;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Ai;

require_once __DIR__.'/../Reports/ReportsTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->f = financeFixture();
    finAgree($this->f, '18450');
    repOrderInB($this->f, '1000');
    Ai::license(credits: 100);
    $this->fake = Ai::fake();
    Ai::enable($this->f->owner, AiFeature::Digest, AiFeature::DataQuestions);
});

function insCall(array $over = []): array
{
    return ['tool' => 'sales', 'period' => 'this_month', ...$over];
}

function insPlan(array $calls, bool $answerable = true): array
{
    return ['answerable' => $answerable, 'calls' => $calls];
}

// ── tools: read-only, scoped, validated ────────────────────────────────────────────────────────────────────────

it('runs a report as the asking user: a branch-A manager gets branch A only, the owner gets both', function () {
    $tools = app(ReportTools::class);

    $manager = $tools->run($this->f->manager, insCall())['result']['metrics'];
    $owner = $tools->run($this->f->owner, insCall())['result']['metrics'];

    expect($manager['sales.orders_created'])->toBe(1)->and($manager['sales.agreed_gross_halalas'])->toBe(1_845_000)
        ->and($owner['sales.orders_created'])->toBe(2)->and($owner['sales.agreed_gross_halalas'])->toBe(1_945_000);
    expect(array_column($tools->branches($this->f->manager), 'id'))->toBe([$this->f->org->a->id]);
});

it('only reads: a tool run issues no write statement', function () {
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|set|show|savepoint|release|rollback)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    app(ReportTools::class)->run($this->f->owner, insCall(['detail' => true]));

    expect($writes)->toBe([]);
});

it('offers only the reports the user may open', function () {
    $tools = app(ReportTools::class);

    // The supervisor reads reports (P-60) but holds no money grant: money-only reports are not tools for them.
    expect(array_keys($tools->available($this->f->supervisor)))->not->toContain('refunds')
        ->and(array_keys($tools->available($this->f->owner)))->toContain('refunds', 'sales');
    expect(fn () => $tools->run($this->f->supervisor, insCall(['tool' => 'refunds'])))->toThrow(InvalidToolCall::class);
    expect(fn () => $tools->run($this->f->executor, insCall()))->toThrow(InvalidToolCall::class);
});

it('rejects invalid tool parameters', function (array $over) {
    expect(fn () => app(ReportTools::class)->run($this->f->owner, insCall($over)))->toThrow(InvalidToolCall::class);
})->with([
    'unknown tool' => [['tool' => 'users']],
    'unknown period' => [['period' => 'forever']],
    'unknown parameter' => [['sql' => 'drop table orders']],
    'dates without custom' => [['from' => '2026-01-01', 'to' => '2026-01-02']],
    'custom without dates' => [['period' => 'custom']],
    'malformed date' => [['period' => 'custom', 'from' => '2026-13-40', 'to' => '2026-12-01']],
    'reversed range' => [['period' => 'custom', 'from' => '2026-05-02', 'to' => '2026-05-01']],
    'range over a year' => [['period' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01']],
    'detail not boolean' => [['detail' => 'yes']],
    'branch not an integer' => [['branch_id' => '1']],
]);

it('rejects a branch outside the user scope', function () {
    $tools = app(ReportTools::class);

    expect(fn () => $tools->run($this->f->manager, insCall(['branch_id' => $this->f->org->b->id])))->toThrow(InvalidToolCall::class);
    expect($tools->run($this->f->manager, insCall(['branch_id' => $this->f->org->a->id]))['result']['branch_id'])->toBe($this->f->org->a->id);
});

// ── DataQuestion ───────────────────────────────────────────────────────────────────────────────────────────────

it('answers from tool results only, charges the answer, and sends the model nothing outside the user scope', function () {
    $this->fake->respond(insPlan([insCall()]))
        ->respond(['answerable' => true, 'answer' => 'بلغت المبيعات المتفق عليها 18450 ريالاً.', 'sources' => ['r1']]);

    $answer = app(DataQuestion::class)->ask($this->f->manager, 'كم بلغت مبيعات هذا الشهر؟');

    expect($answer->status)->toBe(DataAnswer::ANSWERED)
        ->and($answer->answer)->toContain('18450')
        ->and($answer->sources)->toHaveCount(1)->and($answer->sources[0]['tool'])->toBe('sales');
    $this->fake->assertSentCount(2);
    // The second request carries the manager's figure and nobody else's.
    expect($this->fake->sent()[1]->content)->toContain('1845000')->not->toContain('100000')->not->toContain('1945000');
    expect((int) AiUsage::query()->where('task', 'data.ask')->where('outcome', AiOutcome::Ok->value)->sum('credits'))->toBe(3)
        ->and((int) AiUsage::query()->where('task', 'data.plan')->sum('credits'))->toBe(0);
});

it('refuses an answer with a number that is in no tool result, and charges nothing for it', function () {
    $this->fake->respond(insPlan([insCall()]))
        ->respond(['answerable' => true, 'answer' => 'بلغت المبيعات 77777 ريالاً.', 'sources' => ['r1']]);

    $answer = app(DataQuestion::class)->ask($this->f->owner, 'كم بلغت مبيعات هذا الشهر؟');

    expect($answer->status)->toBe(DataAnswer::NO_ANSWER)->and($answer->answer)->toBeNull();
    expect((int) AiUsage::query()->where('task', 'data.ask')->sum('credits'))->toBe(0);
});

it('refuses an answer that cites no result', function () {
    $this->fake->respond(insPlan([insCall()]))
        ->respond(['answerable' => true, 'answer' => 'المبيعات جيدة.', 'sources' => []]);

    expect(app(DataQuestion::class)->ask($this->f->owner, 'كيف المبيعات؟')->status)->toBe(DataAnswer::NO_ANSWER);
});

it('refuses the whole question when the plan asks for a branch the user may not see, without a second model call', function () {
    $this->fake->respond(insPlan([insCall(), insCall(['branch_id' => $this->f->org->b->id])]));

    $answer = app(DataQuestion::class)->ask($this->f->manager, 'قارن مبيعات فرعي بالفرع الآخر');

    expect($answer->status)->toBe(DataAnswer::NO_ANSWER);
    $this->fake->assertSentCount(1);
});

it('refuses a question the model says no tool can answer', function () {
    $this->fake->respond(insPlan([], answerable: false));

    expect(app(DataQuestion::class)->ask($this->f->owner, 'احذف كل الطلبات')->status)->toBe(DataAnswer::NO_ANSWER);
    $this->fake->assertSentCount(1);
});

it('is off without the license capability, without the facility switch, and for a user with no report', function () {
    $q = fn () => app(DataQuestion::class);

    expect($q()->ask($this->f->executor, 'كم الطلبات؟')->status)->toBe(DataAnswer::OFF);

    Ai::license(credits: 100, capabilities: ['ai_core']);
    expect($q()->available($this->f->owner))->toBeFalse()
        ->and($q()->ask($this->f->owner, 'كم الطلبات؟')->status)->toBe(DataAnswer::OFF);

    Ai::license(credits: 100);
    app(AiSettings::class)->save($this->f->owner, ['data_questions' => false]);
    expect($q()->ask($this->f->owner, 'كم الطلبات؟')->status)->toBe(DataAnswer::OFF);

    $this->fake->assertNothingSent();
});

it('never calls the gateway inside a database transaction', function () {
    // The suite wraps each test in one transaction (baseline 1): a second level is a real nested transaction.
    expect(fn () => DB::transaction(fn () => app(DataQuestion::class)->ask($this->f->owner, 'كم الطلبات؟')))->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => app(DailyDigest::class)->sendTo($this->f->owner)))->toThrow(LogicException::class);
    $this->fake->assertNothingSent();
});

it('exposes the question as an endpoint that is 404 when the feature is off', function () {
    $this->fake->respond(insPlan([insCall()]))
        ->respond(['answerable' => true, 'answer' => 'بلغت المبيعات المتفق عليها 18450 ريالاً.', 'sources' => ['r1']]);

    $this->actingAs($this->f->manager)->postJson('/ai/data-questions', ['question' => 'كم المبيعات؟'])
        ->assertOk()->assertJsonPath('status', 'answered');

    $this->actingAs($this->f->executor)->postJson('/ai/data-questions', ['question' => 'كم المبيعات؟'])->assertNotFound();
    $this->actingAs($this->f->owner)->postJson('/ai/data-questions', ['question' => ''])->assertUnprocessable();
});

// ── DailyDigest ────────────────────────────────────────────────────────────────────────────────────────────────

it('digests only to owners and managers, built with each recipient identity', function () {
    $ids = app(DailyDigest::class)->recipients()->pluck('id')->all();

    expect($ids)->toContain($this->f->owner->id, $this->f->manager->id)
        ->not->toContain($this->f->executor->id)->not->toContain($this->f->supervisor->id);
});

it('builds the branch manager digest from branch A only and the owner digest from both', function () {
    $digest = app(DailyDigest::class);

    $manager = json_encode($digest->facts($this->f->manager));
    $owner = json_encode($digest->facts($this->f->owner));

    expect($manager)->toContain('1845000')->not->toContain('100000')->not->toContain('1945000')
        ->and($owner)->toContain('1945000');
});

it('sends one digest per recipient per day, charged once each, to the recipient only', function () {
    $this->fake->respond(['headline' => 'ملخص الصباح', 'bullets' => ['طلبان مفتوحان في المنشأة.']])
        ->respond(['headline' => 'ملخص الصباح', 'bullets' => ['طلب واحد مفتوح في فرعك.']]);
    $digest = app(DailyDigest::class);

    expect($digest->sendTo($this->f->owner))->toBe(DailyDigest::SENT)
        ->and($digest->sendTo($this->f->manager))->toBe(DailyDigest::SENT)
        ->and($digest->sendTo($this->f->owner))->toBe(DailyDigest::DUPLICATE);

    $this->fake->assertSentCount(2);
    expect($this->fake->sent()[1]->content)->not->toContain('100000');
    $note = UserNotification::query()->where('user_id', $this->f->manager->id)->where('type', DailyDigest::NOTICE)->first();
    expect($note)->not->toBeNull()->and($note->data['body'])->toBe('ai.notify.digest.body')->and($note->data['params']['text'])->toContain('فرعك');
    expect(UserNotification::query()->where('type', DailyDigest::NOTICE)->count())->toBe(2);
    expect((int) AiUsage::query()->where('task', 'digest.daily')->where('outcome', AiOutcome::Ok->value)->sum('credits'))->toBe(2);
});

it('does not send a digest with a number that is in no report figure', function () {
    $this->fake->respond(['headline' => 'ملخص الصباح', 'bullets' => ['المبيعات 98765 ريالاً.']]);

    expect(app(DailyDigest::class)->sendTo($this->f->owner))->toBe(DailyDigest::FAILED);
    expect(UserNotification::query()->where('type', DailyDigest::NOTICE)->count())->toBe(0)
        ->and((int) AiUsage::query()->where('task', 'digest.daily')->sum('credits'))->toBe(0);
});

it('is off without the license capability or the facility switch, and calls no model', function () {
    $digest = fn () => app(DailyDigest::class);

    Ai::license(credits: 100, capabilities: []);
    expect($digest()->sendTo($this->f->owner))->toBe(DailyDigest::OFF)->and($digest()->dispatchAll())->toBe(0);

    Ai::license(credits: 100);
    app(AiSettings::class)->save($this->f->owner, ['digest' => false]);
    expect($digest()->sendTo($this->f->owner))->toBe(DailyDigest::OFF)->and($digest()->dispatchAll())->toBe(0);

    $this->fake->assertNothingSent();
});

it('queues one job per recipient, and the job re-checks the recipient when it runs', function () {
    Queue::fake();
    $queued = app(DailyDigest::class)->dispatchAll();
    Queue::assertPushed(SendDailyDigest::class, $queued);
    expect($queued)->toBeGreaterThanOrEqual(2);

    // A user who is no longer a recipient when the job runs gets nothing.
    (new SendDailyDigest($this->f->executor->id))->handle(app(DailyDigest::class));
    $this->fake->assertNothingSent();
});

it('stays silent on a quiet day without a model call', function () {
    // Branch C has no orders: every figure in this manager's scope is zero.
    $quiet = $this->f->org->member('manager', [$this->f->org->c]);

    expect(app(DailyDigest::class)->sendTo($quiet))->toBe(DailyDigest::NOTHING_TO_REPORT);
    $this->fake->assertNothingSent();
});

it('runs from the command and is a no-op when off', function () {
    Queue::fake();
    $this->artisan('ai:digest')->assertSuccessful();
    Queue::assertPushed(SendDailyDigest::class);

    Ai::license(credits: 100, capabilities: []);
    Queue::fake();
    $this->artisan('ai:digest')->assertSuccessful();
    Queue::assertNothingPushed();
});
