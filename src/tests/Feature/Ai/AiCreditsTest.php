<?php

use App\Ai\AiCall;
use App\Ai\AiCredits;
use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\AiOutcome;
use App\Ai\AiTask;
use App\Ai\Exceptions\AiProviderUnavailable;
use App\Models\Ai\AiUsage;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Ai;
use Tests\Support\LicenseFactory;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->org = Org::make();
    $this->fake = Ai::fake();
    Ai::enable($this->org->owner, AiFeature::Inbox);
    $this->gateway = fn () => app(AiGateway::class);
});

it('charges the task weight only for successful calls and stops when the month is used up', function () {
    Ai::license(credits: 2);
    $this->fake->respond(['reply' => 'أهلاً'])->respond(['reply' => 'أهلاً'])->respond(['reply' => 'أهلاً']);

    expect(($this->gateway)()->run(Ai::replyCall())->outcome)->toBe(AiOutcome::Ok);
    expect(($this->gateway)()->run(Ai::replyCall())->outcome)->toBe(AiOutcome::Ok);

    $third = ($this->gateway)()->run(Ai::replyCall());
    expect($third->outcome)->toBe(AiOutcome::NoCredit)
        ->and($third->output)->toBeNull();

    // The third call never reached the provider and was recorded without credits.
    $this->fake->assertSentCount(2);
    expect(app(AiCredits::class)->status())->toMatchArray(['used' => 2, 'limit' => 2, 'remaining' => 0])
        ->and(AiUsage::query()->where('outcome', 'no_credit')->value('credits'))->toBe(0);
});

it('does not charge failed, refused or invalid calls', function () {
    Ai::license(credits: 10);
    $this->fake->fail(new AiProviderUnavailable('down'))
        ->respond(['reply' => 'x'], stopReason: 'refusal')
        ->respond('not json')
        ->respond(['wrong' => true]);

    $outcomes = collect(range(1, 4))->map(fn () => ($this->gateway)()->run(Ai::replyCall())->outcome);

    expect($outcomes->all())->toBe([AiOutcome::Unavailable, AiOutcome::Refused, AiOutcome::Invalid, AiOutcome::Invalid])
        ->and(app(AiCredits::class)->used())->toBe(0)
        ->and(AiUsage::query()->sum('credits'))->toBe(0);
});

it('counts the month in the facility timezone and renews next month', function () {
    $this->org->facility->forceFill(['timezone' => 'Asia/Riyadh'])->save();
    Ai::license(credits: 1);
    $this->fake->respond(['reply' => 'a'])->respond(['reply' => 'b']);

    $this->travelTo(now('Asia/Riyadh')->setDate(2026, 11, 30)->setTime(23, 30)->utc());
    expect(($this->gateway)()->run(Ai::replyCall())->ok())->toBeTrue();
    expect(($this->gateway)()->run(Ai::replyCall())->outcome)->toBe(AiOutcome::NoCredit);

    // 00:30 on the 1st in Riyadh is still November in UTC: the new month has started for the facility.
    $this->travelTo(now('Asia/Riyadh')->setDate(2026, 12, 1)->setTime(0, 30)->utc());
    expect(($this->gateway)()->run(Ai::replyCall())->ok())->toBeTrue();
});

it('lets an old license without ai_credits use only the included tasks', function () {
    LicenseFactory::install(['capabilities' => ['ai_core', 'ai_plus']]); // no limits.ai_credits
    Ai::enable($this->org->owner, AiFeature::Help);
    $this->fake->respond(['answer' => 'من الإعدادات']);

    expect(app(AiCredits::class)->limit())->toBe(0)
        ->and(($this->gateway)()->run(Ai::replyCall())->outcome)->toBe(AiOutcome::NoCredit);

    $help = new AiCall(AiTask::HelpAnswer, 'Answer from the articles.', 'كيف أضيف فرعاً؟', [
        'type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer'], 'additionalProperties' => false,
    ]);
    expect(($this->gateway)()->run($help)->ok())->toBeTrue();
});

it('caps included tasks with the daily fair-use limit', function () {
    Ai::license(credits: 0);
    config(['ai.fair_use_daily' => 2]);
    $help = new AiCall(AiTask::HelpAnswer, 'Answer.', 'سؤال', ['type' => 'object']);
    $this->fake->respond([])->respond([])->respond([]);

    expect(($this->gateway)()->run($help)->ok())->toBeTrue()
        ->and(($this->gateway)()->run($help)->ok())->toBeTrue()
        ->and(($this->gateway)()->run($help)->outcome)->toBe(AiOutcome::NoCredit);
});

it('alerts P-02 holders once a month at 80% of the credits', function () {
    Ai::license(credits: 5);
    foreach (range(1, 5) as $_) {
        $this->fake->respond(['reply' => 'ok']);
    }

    foreach (range(1, 3) as $_) {
        ($this->gateway)()->run(Ai::replyCall());
    }
    expect(UserNotification::query()->where('type', AiCredits::NOTICE)->count())->toBe(0);

    ($this->gateway)()->run(Ai::replyCall()); // 4 of 5 = 80%
    ($this->gateway)()->run(Ai::replyCall());

    expect(UserNotification::query()->where('type', AiCredits::NOTICE)->where('user_id', $this->org->owner->id)->count())->toBe(1);
});

it('ignores a stale pending reservation after the reservation window', function () {
    Ai::license(credits: 1);
    AiUsage::query()->create(['task' => 'reply.suggest', 'model' => 'm', 'driver' => 'fake', 'outcome' => 'pending', 'credits' => 1]);
    expect(app(AiCredits::class)->used())->toBe(1);

    $this->travel(16)->minutes();
    expect(app(AiCredits::class)->used())->toBe(0);
});
