<?php

use App\Jobs\DeliverOutboxMessage;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Outbox;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\Org;

uses(RefreshDatabase::class);

/** Mailer whose server is down. */
function breakMail(): void
{
    Mail::extend('boom', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Connection could not be established with host smtp.example.com for ops@example.com');
        }

        public function __toString(): string
        {
            return 'boom';
        }
    });
    config(['mail.default' => 'boom', 'mail.mailers.boom' => ['transport' => 'boom']]);
    Mail::purge('boom');
}

function repairMail(): void
{
    config(['mail.default' => 'array']);
    Mail::purge('array');
}

function mailSent(): int
{
    return count(Mail::mailer('array')->getSymfonyTransport()->messages());
}

beforeEach(function () {
    Org::make();
    $this->outbox = app(Outbox::class);
    $this->mail = fn (string $key = 'k1') => $this->outbox->enqueueMail('to@example.com', 'Subject', ['Line'], null, 'en', $key);
});

it('does not send the same message twice', function () {
    repairMail();

    $first = ($this->mail)('same-key');
    $second = ($this->mail)('same-key');

    expect($first)->not->toBeNull()->and($second)->toBeNull()
        ->and(OutboxMessage::query()->count())->toBe(1)
        ->and(mailSent())->toBe(1);

    // A repeated or racing delivery attempt of a sent message does nothing.
    app(OutboxDeliverer::class)->deliver($first->id);
    app(OutboxDeliverer::class)->deliver($first->id);

    expect(mailSent())->toBe(1)
        ->and($first->fresh()->status)->toBe(OutboxStatus::Sent)
        ->and($first->fresh()->payload)->toBeNull();
});

it('does not claim a message that another worker is already sending', function () {
    repairMail();
    Queue::fake();
    $message = ($this->mail)();
    DB::table('outbox_messages')->where('id', $message->id)->update(['status' => 'sending', 'claimed_at' => now()]);

    app(OutboxDeliverer::class)->deliver($message->id);

    expect(mailSent())->toBe(0)->and($message->fresh()->attempts)->toBe(0);
});

it('keeps the business change when the mail server fails and retries later', function () {
    breakMail();

    DB::transaction(function () {
        Branch::factory()->create(['name_ar' => 'فرع جديد']);
        ($this->mail)('failing');
    });

    $message = OutboxMessage::query()->firstOrFail();
    expect(Branch::query()->where('name_ar', 'فرع جديد')->exists())->toBeTrue()
        ->and($message->status)->toBe(OutboxStatus::Failed)
        ->and($message->attempts)->toBe(1)
        ->and($message->next_attempt_at)->not->toBeNull()
        // Provider text is scrubbed of addresses and never holds the payload.
        ->and($message->last_error)->not->toContain('ops@example.com');

    // Too early: the backoff has not passed.
    repairMail();
    app(OutboxDeliverer::class)->deliver($message->id);
    expect(mailSent())->toBe(0);

    $this->travel(2)->minutes();
    app(OutboxDeliverer::class)->deliver($message->id);

    expect($message->fresh()->status)->toBe(OutboxStatus::Sent)
        ->and($message->fresh()->attempts)->toBe(2)
        ->and(mailSent())->toBe(1);
});

it('gives up after the attempt limit and keeps the failure visible', function () {
    breakMail();
    $message = ($this->mail)('doomed');

    for ($i = 1; $i < OutboxDeliverer::MAX_ATTEMPTS; $i++) {
        $this->travel(3)->hours();
        app(OutboxDeliverer::class)->deliver($message->id);
    }

    expect($message->fresh()->status)->toBe(OutboxStatus::Dead)
        ->and($message->fresh()->attempts)->toBe(OutboxDeliverer::MAX_ATTEMPTS)
        ->and($message->fresh()->next_attempt_at)->toBeNull();
});

it('writes and sends nothing when the business transaction rolls back', function () {
    repairMail();

    try {
        DB::transaction(function () {
            ($this->mail)('rolled-back');
            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::query()->count())->toBe(0)->and(mailSent())->toBe(0);
});

it('delivers only after the transaction commits', function () {
    repairMail();

    DB::transaction(function () {
        ($this->mail)('after-commit');
        expect(mailSent())->toBe(0);
    });

    expect(mailSent())->toBe(1);

    Queue::fake();
    ($this->mail)('flag');
    Queue::assertPushed(DeliverOutboxMessage::class, fn (DeliverOutboxMessage $job) => $job->afterCommit === true);
});

it('encrypts the payload at rest and clears it once sent', function () {
    Queue::fake();
    $message = $this->outbox->enqueueMail('to@example.com', 'Code', ['Your code is 123456'], null, 'en', 'secret');

    expect(DB::table('outbox_messages')->where('id', $message->id)->value('payload'))->not->toContain('123456');

    repairMail();
    app(OutboxDeliverer::class)->deliver($message->id);

    expect(DB::table('outbox_messages')->where('id', $message->id)->value('payload'))->toBeNull();
});

it('re-queues due messages whose job was lost', function () {
    Queue::fake();
    $message = ($this->mail)('lost');
    $this->travel(5)->minutes();

    expect(app(OutboxDeliverer::class)->redispatchDue())->toBe(1);
    Queue::assertPushed(DeliverOutboxMessage::class, 2);

    $this->artisan('outbox:process')->assertSuccessful();
    expect($message->fresh()->status)->toBe(OutboxStatus::Pending);
});

it('skips sms instead of pretending it was sent when no provider is configured', function () {
    app()->bind(SmsChannel::class, fn () => new class implements SmsChannel
    {
        public function isConfigured(): bool
        {
            return false;
        }

        public function send(string $to, string $text): void
        {
            throw new LogicException('must not be called');
        }
    });

    $message = $this->outbox->enqueueSms('+966500000000', 'hello', 'sms-1');

    expect($message->fresh()->status)->toBe(OutboxStatus::Skipped)
        ->and($message->fresh()->payload)->toBeNull();
});
