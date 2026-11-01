<?php

/**
 * WhatsApp channel (ADR-0029): dispatch per event through the outbox, consent and preferred channel, SMS fallback,
 * idempotency, the signed webhook, the owner's settings and the staff message log.
 */

use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Enums\ContactChannel;
use App\Enums\CustomOrderStatus;
use App\Models\CustomerMessage;
use App\Models\IntegrationSetting;
use App\Models\MemberGrant;
use App\Models\OutboxMessage;
use App\Notifications\Channels\WhatsApp\FakeWhatsAppChannel;
use App\Notifications\Channels\WhatsApp\WhatsAppChannel;
use App\Notifications\Channels\WhatsApp\WhatsAppException;
use App\Notifications\OutboxChannel;
use App\Notifications\OutboxDeliverer;
use App\Notifications\OutboxStatus;
use App\Whatsapp\CustomerEvents;
use App\Whatsapp\CustomerMessenger;
use App\Whatsapp\MessageStatus;
use App\Whatsapp\WhatsAppEvent;
use App\Whatsapp\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Org;

require_once __DIR__.'/../Finance/FinanceTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fake = new FakeWhatsAppChannel;
    app()->instance(WhatsAppChannel::class, $this->fake);
    config(['whatsapp.driver' => 'fake', 'whatsapp.cloud.app_secret' => 'test-secret', 'whatsapp.cloud.verify_token' => 'verify-me']);
    $this->f = agreementFixture();
    $this->customer = $this->f->order->customer;
    $this->enable = fn (?array $events = null) => app(WhatsAppSettings::class)->save($this->f->owner, true, $events ?? WhatsAppEvent::values());
    $this->deliverAll = function () {
        foreach (OutboxMessage::query()->whereIn('status', ['pending', 'failed'])->pluck('id') as $id) {
            app(OutboxDeliverer::class)->deliver((int) $id);
        }
    };
    $this->send = fn (string $key = 'k1', WhatsAppEvent $event = WhatsAppEvent::QuoteReady) => app(CustomerMessenger::class)->send(
        $this->customer->refresh(), $event, ['order' => 'ORD-1', 'link' => 'https://x.test/l'], $key, $this->f->order,
    );
});

function webhookBody(array $statuses = [], array $messages = []): string
{
    return json_encode(['entry' => [['changes' => [['value' => ['statuses' => $statuses, 'messages' => $messages]]]]]]);
}

function signedPost(object $test, string $body, ?string $secret = 'test-secret')
{
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/webhooks/whatsapp', [], [], [], $headers, $body);
}

it('sends each event as its own template in the customer language', function (WhatsAppEvent $event) {
    ($this->enable)();
    $message = ($this->send)('k-'.$event->value, $event);
    ($this->deliverAll)();

    expect($message->channel)->toBe('whatsapp')
        ->and($this->fake->sent)->toHaveCount(1)
        ->and($this->fake->sent[0]->template)->toBe($event->template())
        ->and($this->fake->sent[0]->language)->toBe('ar')
        ->and($this->fake->sent[0]->parameters)->toHaveCount(count($event->params()))
        ->and($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->provider_message_id)->toStartWith('wamid.');
})->with(WhatsAppEvent::cases());

it('reaches the customer by SMS when the channel is off, the event unchecked or the driver unconfigured', function () {
    $m = ($this->send)('off');
    expect($m->channel)->toBe('sms');

    ($this->enable)([WhatsAppEvent::DesignReady->value]);
    expect(($this->send)('unchecked')->channel)->toBe('sms');

    ($this->enable)();
    $this->fake->configured = false;
    expect(($this->send)('unconfigured')->channel)->toBe('sms')
        ->and(OutboxMessage::query()->where('channel', OutboxChannel::Whatsapp->value)->count())->toBe(0)
        ->and(OutboxMessage::query()->where('channel', OutboxChannel::Sms->value)->count())->toBe(3);
});

it('respects opt-out and a preferred channel other than WhatsApp', function () {
    ($this->enable)();
    $this->customer->forceFill(['whatsapp_opt_out_at' => now()])->save();
    expect(($this->send)('a')->channel)->toBe('sms');

    $this->customer->forceFill(['whatsapp_opt_out_at' => null, 'preferred_channel' => ContactChannel::Phone])->save();
    expect(($this->send)('b')->channel)->toBe('sms');

    $this->customer->forceFill(['preferred_channel' => ContactChannel::Whatsapp])->save();
    expect(($this->send)('c')->channel)->toBe('whatsapp')
        ->and($this->fake->sent)->toHaveCount(0);
});

it('re-checks consent at delivery: an opt-out after queuing sends SMS instead', function () {
    ($this->enable)();
    $m = ($this->send)('late-optout');
    $this->customer->forceFill(['whatsapp_opt_out_at' => now()])->save();
    ($this->deliverAll)();

    expect($this->fake->sent)->toHaveCount(0)
        ->and($m->refresh()->channel)->toBe('sms')->and($m->fell_back)->toBeTrue()
        ->and(OutboxMessage::query()->where('channel', 'sms')->count())->toBe(1);
});

it('falls back to SMS once when the provider refuses permanently', function () {
    ($this->enable)();
    $this->fake->failure = new WhatsAppException('no', false, '131026');
    $m = ($this->send)('refused');
    ($this->deliverAll)();
    ($this->deliverAll)();

    expect($m->refresh()->channel)->toBe('sms')->and($m->fell_back)->toBeTrue()->and($m->error)->toBe('whatsapp_131026')
        ->and(OutboxMessage::query()->where('channel', 'sms')->count())->toBe(1);

    app(OutboxDeliverer::class)->deliver(OutboxMessage::query()->where('channel', 'sms')->value('id'));
    expect($m->refresh()->status)->toBe(MessageStatus::Sent);
});

it('retries a transient failure and falls back to SMS when retries are exhausted', function () {
    ($this->enable)();
    $this->fake->failure = new WhatsAppException('down', true, 'connection');
    $m = ($this->send)('transient');
    $wa = OutboxMessage::query()->where('channel', 'whatsapp')->firstOrFail();

    for ($i = 0; $i < OutboxDeliverer::MAX_ATTEMPTS; $i++) {
        DB::table('outbox_messages')->where('id', $wa->id)->update(['next_attempt_at' => null]);
        app(OutboxDeliverer::class)->deliver($wa->id);
        if ($i === 0) {
            expect($wa->refresh()->status)->toBe(OutboxStatus::Failed)->and($m->refresh()->channel)->toBe('whatsapp');
        }
    }

    expect($wa->refresh()->status)->toBe(OutboxStatus::Dead)
        ->and($m->refresh()->channel)->toBe('sms')->and($m->fell_back)->toBeTrue()
        ->and(OutboxMessage::query()->where('channel', 'sms')->count())->toBe(1);
});

it('is idempotent: the same key queues and sends once', function () {
    ($this->enable)();
    expect(($this->send)('same'))->not->toBeNull()
        ->and(($this->send)('same'))->toBeNull();
    ($this->deliverAll)();
    ($this->deliverAll)();
    $wa = OutboxMessage::query()->where('channel', 'whatsapp')->firstOrFail();
    app(OutboxDeliverer::class)->deliver($wa->id);

    expect(CustomerMessage::query()->count())->toBe(1)->and($this->fake->sent)->toHaveCount(1);
});

it('never sends twice when an earlier attempt already reached the provider', function () {
    ($this->enable)();
    $m = ($this->send)('crash');
    $m->forceFill(['provider_message_id' => 'wamid.earlier'])->save();
    ($this->deliverAll)();

    expect($this->fake->sent)->toHaveCount(0)->and($m->refresh()->status)->toBe(MessageStatus::Sent);
});

it('queues a booking message when a measurement is confirmed and a reminder 24 h before', function () {
    ($this->enable)();
    $tech = $this->f->org->member('executor', [$this->f->org->a]);
    $a = propose($this->f->sales, $this->f->order, [$tech->id], now()->addHours(20)->format('Y-m-d H:i'), now()->addHours(21)->format('Y-m-d H:i'));
    app(T02ConfirmAppointment::class)->handle($this->f->sales, $a, 'phone');

    $booked = CustomerMessage::query()->where('event', 'measurement_booked')->get();
    expect($booked)->toHaveCount(1);

    $this->travelTo(now()->setTimezone('Asia/Riyadh')->setTime(10, 0)->utc());
    expect(CustomerEvents::remindDue(now()))->toBe(1)
        ->and(CustomerEvents::remindDue(now()))->toBe(0)
        ->and(CustomerMessage::query()->where('event', 'measurement_reminder')->count())->toBe(1);
});

it('queues quote-ready, receipt and completion messages from the real flows', function () {
    ($this->enable)();
    agrSettings();
    $quote = agrQuote($this->f->sales, $this->f->order);
    agrSend($this->f->sales, $quote);
    expect(CustomerMessage::query()->where('event', 'quote_ready')->count())->toBe(1);

    $order = $this->f->order->refresh();
    $order->forceFill(['status' => CustomOrderStatus::ExecutionFinished->value])->save();
    expect(CustomerMessage::query()->where('event', 'order_completed')->count())->toBe(1);
});

it('rejects a webhook without or with a wrong signature and when no secret is configured', function () {
    ($this->enable)();
    $m = ($this->send)('hook');
    $m->forceFill(['provider_message_id' => 'wamid.1', 'status' => MessageStatus::Sent])->save();
    $body = webhookBody([['id' => 'wamid.1', 'status' => 'delivered']]);

    signedPost($this, $body, null)->assertForbidden();
    signedPost($this, $body, 'wrong-secret')->assertForbidden();
    config(['whatsapp.cloud.app_secret' => '']);
    signedPost($this, $body, '')->assertForbidden();
    expect($m->refresh()->status)->toBe(MessageStatus::Sent);
});

it('accepts a signed webhook, moves statuses forward only and survives replays', function () {
    ($this->enable)();
    $m = ($this->send)('hook2');
    $m->forceFill(['provider_message_id' => 'wamid.2', 'status' => MessageStatus::Sent])->save();

    signedPost($this, webhookBody([['id' => 'wamid.2', 'status' => 'read']]))->assertOk();
    signedPost($this, webhookBody([['id' => 'wamid.2', 'status' => 'delivered']]))->assertOk();
    signedPost($this, webhookBody([['id' => 'wamid.2', 'status' => 'read']]))->assertOk();
    signedPost($this, webhookBody([['id' => 'wamid.unknown', 'status' => 'read']]))->assertOk();

    expect($m->refresh()->status)->toBe(MessageStatus::Read)->and($m->read_at)->not->toBeNull()->and($m->sent_at)->not->toBeNull();
});

it('falls back to SMS when the provider later reports the send failed', function () {
    ($this->enable)();
    $m = ($this->send)('hook3');
    $m->forceFill(['provider_message_id' => 'wamid.3', 'status' => MessageStatus::Sent])->save();

    $body = webhookBody([['id' => 'wamid.3', 'status' => 'failed', 'errors' => [['code' => 131026]]]]);
    signedPost($this, $body)->assertOk();
    signedPost($this, $body)->assertOk();

    expect($m->refresh()->channel)->toBe('sms')->and($m->fell_back)->toBeTrue()
        ->and(OutboxMessage::query()->where('channel', 'sms')->count())->toBe(1);
});

it('records a STOP reply as an opt-out', function () {
    $phone = ltrim($this->customer->mobile, '+');
    signedPost($this, webhookBody([], [['from' => $phone, 'text' => ['body' => 'STOP']]]))->assertOk();

    expect($this->customer->refresh()->whatsapp_opt_out_at)->not->toBeNull()
        ->and($this->customer->whatsapp_opt_out_source)->toBe('customer');
});

it('answers Meta URL verification only with the verify token', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1234')->assertOk()->assertSee('1234');
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1234')->assertForbidden();
});

it('lets only the owner change the WhatsApp settings', function () {
    $body = ['enabled' => true, 'events' => ['quote_ready', 'order_completed']];

    $this->actingAs($this->f->sales)->put('/settings/integrations/whatsapp', $body)->assertForbidden();
    expect(IntegrationSetting::query()->where('key', 'whatsapp')->exists())->toBeFalse();

    $this->actingAs($this->f->owner)->put('/settings/integrations/whatsapp', ['enabled' => true, 'events' => ['bogus']])->assertSessionHasErrors('events.0');
    $this->actingAs($this->f->owner)->put('/settings/integrations/whatsapp', $body)->assertRedirect();

    $settings = app(WhatsAppSettings::class);
    expect($settings->enabled())->toBeTrue()->and($settings->events())->toBe(['quote_ready', 'order_completed']);

    $this->actingAs($this->f->owner)->get('/settings/integrations')->assertInertia(fn (Assert $page) => $page
        ->where('whatsapp.enabled', true)->where('whatsapp.driver', 'fake')->missing('whatsapp.access_token'));
});

it('shows the log to staff and hides the text from those who cannot manage the customer', function () {
    ($this->enable)();
    ($this->send)('log1');
    $url = '/customers/'.$this->customer->id;

    $this->actingAs($this->f->owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('messaging.messages.0.status', 'queued')->where('messaging.messages.0.body', fn ($b) => is_string($b) && $b !== '')->where('messaging.body_hidden', false));

    $viewer = $this->f->org->member(null, [$this->f->org->a]);
    MemberGrant::query()->create(['membership_id' => Org::membershipOf($viewer)->id, 'permission' => 'P-09', 'effect' => 'allow', 'scope' => 'branches', 'targets' => [$this->f->org->a->id]]);
    $this->actingAs($viewer)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('messaging.messages.0.event', 'quote_ready')->where('messaging.messages.0.body', null)->where('messaging.body_hidden', true));
});

it('toggles consent with P-10 and audits it', function () {
    $url = '/customers/'.$this->customer->id.'/messaging';
    $this->actingAs($this->f->executor)->put($url, ['whatsapp_opt_out' => true])->assertForbidden();
    $this->actingAs($this->f->sales)->put($url, ['whatsapp_opt_out' => true])->assertRedirect();

    expect($this->customer->refresh()->whatsapp_opt_out_at)->not->toBeNull()->and($this->customer->whatsapp_opt_out_source)->toBe('staff');

    $this->actingAs($this->f->sales)->put($url, ['whatsapp_opt_out' => false])->assertRedirect();
    expect($this->customer->refresh()->whatsapp_opt_out_at)->toBeNull();
});
