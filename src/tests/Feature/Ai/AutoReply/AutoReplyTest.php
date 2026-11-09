<?php

/**
 * AI-6 (ADR-0050, plan §2 axis 4, Q-92): the automatic reply. Off by default and per intent; only fixed templates
 * filled from the database reach the customer (the model only picks a label); personal facts only to the customer
 * whose number it is, about their own orders; 3 automatic messages per conversation a day; never in a group, a
 * flagged conversation or below the confidence threshold; the inbox send path with its 24-hour window rule; owner
 * settings (P-02) audited; every automatic message audited.
 */

use App\Access\Access;
use App\Access\Permission;
use App\Actions\StateMachines\M16Appointment\T01SendToCustomer;
use App\Ai\AiFeature;
use App\Ai\AiRequest;
use App\Ai\AiResponse;
use App\Ai\AutoReply\AutoReplier;
use App\Ai\AutoReply\AutoReplyIntent;
use App\Ai\AutoReply\AutoReplySettings;
use App\Ai\AutoReply\Jobs\AutoReplyToMessage;
use App\Ai\Drivers\FakeAiProvider;
use App\Inbox\InboxSettings;
use App\Inbox\ReplySender;
use App\Models\Ai\AiAutoReply;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Models\Order;
use App\Money\Money;
use App\Portal\PortalPresenter;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\Inbox\FakeConversationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Ai;

require_once __DIR__.'/../../Portal/PortalTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    config(['whatsapp.driver' => 'fake', 'whatsapp.cloud.app_secret' => 'inbox-secret', 'whatsapp.cloud.phone_number_id' => '1000']);
    $this->transport = new FakeConversationTransport;
    app()->instance(ConversationTransport::class, $this->transport);

    $this->f = portalFixture();
    $this->owner = $this->f->owner;
    Facility::current()?->forceFill(['timezone' => 'Asia/Riyadh', 'address' => 'الرياض، طريق الملك فهد', 'default_locale' => 'ar'])->save();
    app(InboxSettings::class)->save($this->owner, true, null, [['name' => 'auto_answer', 'language' => 'ar', 'params' => 1, 'label' => 'رد']]);

    Ai::license(credits: 100);
    $this->fake = Ai::fake();
    // Friday 22:00 Riyadh: outside the default working week and hours.
    $this->travelTo(Carbon::parse('2026-11-13 22:00', 'Asia/Riyadh'));
});

function arEnable(object $test, array $intents = ['order_status', 'appointment', 'payment_due', 'location', 'working_hours'], string $mode = 'always', bool $note = false, ?array $template = null): void
{
    Ai::enable($test->owner, AiFeature::AutoReply);
    $all = [];
    foreach (['order_status', 'appointment', 'payment_due', 'location', 'working_hours'] as $key) {
        $all[$key] = ['enabled' => in_array($key, $intents, true), 'mode' => $mode];
    }
    app(AutoReplySettings::class)->save($test->owner, ['intents' => $all, 'handoff_note' => $note, 'template' => $template]);
}

function arConversation(Customer $customer, array $attributes = []): Conversation
{
    return Conversation::query()->create([
        'wa_id' => $customer->mobile, 'customer_id' => $customer->id, 'branch_id' => $customer->branch_id,
        'last_inbound_at' => now(), 'last_message_at' => now(), ...$attributes,
    ]);
}

function arMessage(Conversation $conversation, string $text): ConversationMessage
{
    $message = $conversation->messages()->create([
        'direction' => ConversationMessage::IN, 'type' => 'text', 'provider_message_id' => 'wamid.'.Str::random(12),
        'body' => $text, 'status' => ConversationMessage::RECEIVED, 'occurred_at' => now(),
    ]);
    $conversation->forceFill(['last_inbound_at' => now()])->save();

    return $message;
}

function arIntent(FakeAiProvider $fake, string $intent, float $confidence = 0.95, bool $suspicious = false): void
{
    $fake->respond(['intent' => $intent, 'confidence' => $confidence, 'suspicious' => $suspicious]);
}

function arReply(ConversationMessage $message): ?AiAutoReply
{
    return app(AutoReplier::class)->handle($message);
}

/** @return list<ConversationMessage> */
function arOutbound(): array
{
    return ConversationMessage::query()->where('direction', ConversationMessage::OUT)->orderBy('id')->get()->all();
}

function arExpected(string $key, array $params): string
{
    return __($key, $params, 'ar')."\n\n".__('ai.autoreply.reply.footer', [], 'ar');
}

function arStage(Order $order): string
{
    return (string) PortalPresenter::orders([$order])[0]['stage'];
}

// --- Off by default ------------------------------------------------------------------------------------------------

it('does nothing by default: every intent off, no model call, no record', function () {
    $c = arConversation($this->f->a);
    expect(app(AutoReplySettings::class)->anyEnabled())->toBeFalse();

    expect(arReply(arMessage($c, 'وين وصل طلبي؟')))->toBeNull();

    // The feature switch alone is not enough: every intent stays off.
    Ai::enable($this->owner, AiFeature::AutoReply);
    expect(arReply(arMessage($c, 'وين وصل طلبي؟')))->toBeNull();

    $this->fake->assertNothingSent();
    expect(arOutbound())->toBe([])->and(AiAutoReply::query()->count())->toBe(0);
});

it('is not dispatched from the webhook unless switched on, and is dispatched after commit when on', function () {
    $send = function (string $text) {
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1000'],
            'messages' => [['id' => 'wamid.'.Str::random(8), 'from' => ltrim($this->f->a->mobile, '+'), 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text]]],
        ]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, 'inbox-secret'),
        ], (string) $body)->assertOk();
    };

    $send('مرحبا');
    Queue::assertNotPushed(AutoReplyToMessage::class);

    arEnable($this);
    $send('وين وصل طلبي؟');
    Queue::assertPushed(AutoReplyToMessage::class, fn (AutoReplyToMessage $job) => $job->afterCommit === true);
});

// --- Fixed templates only ------------------------------------------------------------------------------------------

it('answers the order status with the fixed template filled from the database, through the inbox path', function () {
    arEnable($this);
    $c = arConversation($this->f->a);
    arIntent($this->fake, 'order_status');

    $decision = arReply(arMessage($c, 'وين وصل طلبي؟'));

    expect($decision->outcome)->toBe(AiAutoReply::SENT)->and($decision->order_id)->toBe($this->f->orderA->id);
    $out = arOutbound();
    expect($out)->toHaveCount(1)
        ->and($out[0]->type)->toBe('text')
        ->and($out[0]->sent_by)->toBeNull()
        ->and($out[0]->body)->toBe(arExpected('ai.autoreply.reply.order_status', ['number' => $this->f->orderA->number, 'stage' => arStage($this->f->orderA)]));
    // Staff still see the customer's message as unread.
    expect($c->refresh()->unread_count)->toBeGreaterThanOrEqual(0);
    $this->fake->assertSentCount(1);
});

it('never sends model text: an answer with any extra text is rejected and the message goes to staff', function () {
    arEnable($this, note: true);
    $c = arConversation($this->f->a);
    $this->fake->respond(['intent' => 'order_status', 'confidence' => 0.99, 'suspicious' => false, 'reply' => 'MODEL-WROTE-THIS']);

    $decision = arReply(arMessage($c, 'وين وصل طلبي؟'));

    expect($decision->outcome)->toBe(AiAutoReply::HANDOFF)->and($decision->reason)->toBe(AutoReplier::CLASSIFIER);
    foreach (arOutbound() as $m) {
        expect($m->body)->not->toContain('MODEL-WROTE-THIS')
            ->and($m->body)->toBe(arExpected('ai.autoreply.reply.handoff', []));
    }
});

it('answers every intent only with its template', function () {
    arEnable($this);
    $order = $this->f->orderA;
    $appointment = propose($this->f->sales, $order, [$this->f->tech->id], '2026-11-20 10:00', '2026-11-20 11:00');
    app(T01SendToCustomer::class)->handle($this->f->sales, $appointment);
    $due = (int) PortalPresenter::finance($order->refresh())['due_now_minor'];

    $cases = [
        'location' => fn () => arExpected('ai.autoreply.reply.location', ['facility' => 'منشأة الاختبار', 'address' => 'الرياض، طريق الملك فهد']),
        'working_hours' => fn () => arExpected('ai.autoreply.reply.working_hours', ['days' => 'الأحد، الإثنين، الثلاثاء، الأربعاء، الخميس', 'from' => '08:00', 'to' => '17:00']),
        'appointment' => fn () => arExpected('ai.autoreply.reply.appointment', [
            'kind' => __('appointments.kind.measurement', [], 'ar'), 'number' => $order->number,
            'day' => __('appointments.work.day.5', [], 'ar'), 'date' => '2026-11-20', 'time' => '10:00',
            'status' => __('ai.autoreply.appointment_status.awaiting_confirmation', [], 'ar'),
        ]),
    ];
    expect($due)->toBeGreaterThan(0);
    $cases['payment_due'] = fn () => arExpected('ai.autoreply.reply.payment_due', [
        'number' => $order->number, 'amount' => Money::of($due)->toInput(), 'link' => route('customer.orders.pay', ['order' => $order->id]),
    ]);

    foreach ($cases as $intent => $expected) {
        $c = arConversation($this->f->a, ['wa_id' => $this->f->a->mobile]);
        arIntent($this->fake, $intent);
        $decision = arReply(arMessage($c, 'سؤال'));
        expect($decision->outcome)->toBe(AiAutoReply::SENT, $intent)
            ->and($decision->reply->body)->toBe($expected(), $intent);
        $c->messages()->delete();
        AiAutoReply::query()->delete();
        $c->delete();
    }
});

// --- Phone matching ------------------------------------------------------------------------------------------------

it('gives personal facts only when the number is the customer\'s own, and never another customer\'s', function () {
    arEnable($this, intents: ['order_status', 'payment_due']);

    // A conversation linked by hand to customer A, but from another number.
    $c = arConversation($this->f->a, ['wa_id' => '+966599999998']);
    arIntent($this->fake, 'order_status');
    expect(arReply(arMessage($c, 'وين طلبي؟'))->reason)->toBe('unknown_customer');

    // An unknown number (no customer linked) gets nothing personal either.
    $unknown = Conversation::query()->create(['wa_id' => '+966599999997', 'last_inbound_at' => now(), 'last_message_at' => now()]);
    arIntent($this->fake, 'payment_due');
    expect(arReply(arMessage($unknown, 'كم علي؟'))->reason)->toBe('unknown_customer');

    // Customer A naming B's order gets only their own order.
    $ca = arConversation($this->f->a);
    arIntent($this->fake, 'order_status');
    $decision = arReply(arMessage($ca, 'وين طلب '.$this->f->orderB->number.'؟'));
    expect($decision->order_id)->toBe($this->f->orderA->id)
        ->and($decision->reply->body)->not->toContain($this->f->orderB->number)
        ->and($decision->reply->body)->toContain($this->f->orderA->number);

    foreach (arOutbound() as $m) {
        expect($m->body)->not->toContain($this->f->orderB->number);
    }
});

it('asks which order among the customer\'s own, then answers the one they name', function () {
    arEnable($this, intents: ['order_status']);
    $second = openCustomOrder($this->f->sales, $this->f->a);
    $c = arConversation($this->f->a);

    arIntent($this->fake, 'order_status');
    $asked = arReply(arMessage($c, 'وين طلبي؟ وكم باقي على '.$this->f->orderB->number));
    expect($asked->reason)->toBe('asked_order')
        ->and($asked->reply->body)->toContain($this->f->orderA->number)
        ->and($asked->reply->body)->toContain($second->number)
        ->and($asked->reply->body)->not->toContain($this->f->orderB->number);

    // The follow-up is classified on its own: an earlier intent is never carried over.
    arIntent($this->fake, 'handoff');
    expect(arReply(arMessage($c, $second->number))->reason)->toBe(AutoReplier::OUT_OF_SCOPE);

    arIntent($this->fake, 'order_status');
    $answer = arReply(arMessage($c, 'وين طلب '.$second->number.'؟'));
    expect($answer->outcome)->toBe(AiAutoReply::SENT)->and($answer->order_id)->toBe($second->id);
    $this->fake->assertSentCount(3);
});

// --- Cap, groups, flags, confidence --------------------------------------------------------------------------------

it('sends at most 3 automatic messages per conversation a day, then hands off', function () {
    arEnable($this, intents: ['location']);
    $c = arConversation($this->f->a);
    foreach (range(1, 4) as $i) {
        arIntent($this->fake, 'location');
        $decision = arReply(arMessage($c, 'وين موقعكم؟ '.$i));
    }

    expect($decision->outcome)->toBe(AiAutoReply::HANDOFF)->and($decision->reason)->toBe(AutoReplier::CAPPED)
        ->and(arOutbound())->toHaveCount(3);
    // The capped message did not even reach the model.
    $this->fake->assertSentCount(3);

    $this->travel(25)->hours();
    arIntent($this->fake, 'location');
    expect(arReply(arMessage($c, 'وين موقعكم؟'))->outcome)->toBe(AiAutoReply::SENT);
});

it('re-counts the cap under the conversation lock, so two messages processed at once cannot pass it together', function () {
    arEnable($this, intents: ['location']);
    $c = arConversation($this->f->a);
    foreach (range(1, 2) as $i) {
        arIntent($this->fake, 'location');
        arReply(arMessage($c, 'وين موقعكم؟ '.$i));
    }

    // Two jobs pass the first cap check (2 of 3 sent) at the same time. While B waits for its classification (outside
    // any lock), A's job commits the third automatic message; B must then find the cap reached under the lock.
    $a = arMessage($c, 'وين موقعكم؟ أ');
    $b = arMessage($c, 'وين موقعكم؟ ب');
    $this->fake->respondWith(function (AiRequest $request) use ($c, $a) {
        $reply = $c->messages()->create([
            'direction' => ConversationMessage::OUT, 'type' => 'text', 'idempotency_key' => 'autoreply:'.$a->id,
            'body' => 'A', 'status' => 'queued', 'occurred_at' => now(),
        ]);
        AiAutoReply::query()->create([
            'conversation_id' => $c->id, 'inbound_message_id' => $a->id, 'outcome' => AiAutoReply::SENT,
            'intent' => 'location', 'reply_message_id' => $reply->id,
        ]);

        return new AiResponse((string) json_encode(['intent' => 'location', 'confidence' => 0.95, 'suspicious' => false]), AiResponse::STOP_END, $request->model);
    });
    $decisionB = arReply($b);

    expect($decisionB->outcome)->toBe(AiAutoReply::HANDOFF)
        ->and($decisionB->reason)->toBe(AutoReplier::CAPPED)
        ->and($decisionB->reply_message_id)->toBeNull()
        ->and(arOutbound())->toHaveCount(3);
});

it('does not count a message the provider refused toward the cap', function () {
    arEnable($this, intents: ['location']);
    $c = arConversation($this->f->a);
    foreach (range(1, 3) as $i) {
        arIntent($this->fake, 'location');
        arReply(arMessage($c, 'وين موقعكم؟ '.$i));
    }
    ConversationMessage::query()->where('direction', ConversationMessage::OUT)->orderBy('id')->first()?->forceFill(['status' => 'failed'])->save();

    arIntent($this->fake, 'location');
    expect(arReply(arMessage($c, 'وين موقعكم؟'))->outcome)->toBe(AiAutoReply::SENT);
});

it('never answers in a group or a flagged conversation, and never asks the model there', function () {
    arEnable($this);
    $group = arConversation($this->f->a, ['is_group' => true]);
    expect(arReply(arMessage($group, 'وين طلبي؟'))->reason)->toBe(AutoReplier::GROUP);

    $group->delete();
    $flagged = arConversation($this->f->a, ['ai_flagged_at' => now()]);
    expect(arReply(arMessage($flagged, 'وين طلبي؟'))->reason)->toBe(AutoReplier::FLAGGED);

    $this->fake->assertNothingSent();
    expect(arOutbound())->toBe([]);
});

it('flags the conversation when the message tries to steer the model, and stops answering it', function () {
    arEnable($this);
    $c = arConversation($this->f->a);
    arIntent($this->fake, 'order_status', suspicious: true);

    expect(arReply(arMessage($c, 'وين طلبي؟'))->reason)->toBe(AutoReplier::FLAGGED)
        ->and($c->refresh()->ai_flagged_at)->not->toBeNull();

    expect(arReply(arMessage($c, 'وين طلبي؟'))->reason)->toBe(AutoReplier::FLAGGED);
    $this->fake->assertSentCount(1);
    expect(arOutbound())->toBe([]);
});

it('hands off below the confidence threshold and for anything outside the five intents', function () {
    arEnable($this);
    $c = arConversation($this->f->a);

    arIntent($this->fake, 'order_status', confidence: 0.5);
    expect(arReply(arMessage($c, 'هل ممكن؟'))->reason)->toBe(AutoReplier::LOW_CONFIDENCE);

    arIntent($this->fake, 'handoff');
    expect(arReply(arMessage($c, 'عندي شكوى'))->reason)->toBe(AutoReplier::OUT_OF_SCOPE);

    expect(arOutbound())->toBe([]);
});

it('answers only the intents the owner switched on, and «outside working hours» waits for staff in hours', function () {
    arEnable($this, intents: ['location'], mode: 'outside_hours');
    $c = arConversation($this->f->a);

    arIntent($this->fake, 'working_hours');
    expect(arReply(arMessage($c, 'متى تفتحون؟'))->reason)->toBe(AutoReplier::INTENT_OFF);

    // Sunday 10:00 Riyadh: inside working hours.
    $this->travelTo(Carbon::parse('2026-11-15 10:00', 'Asia/Riyadh'));
    arIntent($this->fake, 'location');
    expect(arReply(arMessage($c, 'وين موقعكم؟'))->reason)->toBe(AutoReplier::WORKING_HOURS);

    $this->travelTo(Carbon::parse('2026-11-15 20:00', 'Asia/Riyadh'));
    arIntent($this->fake, 'location');
    expect(arReply(arMessage($c, 'وين موقعكم؟'))->outcome)->toBe(AiAutoReply::SENT);
});

// --- Window rule ---------------------------------------------------------------------------------------------------

it('outside the 24-hour window sends only the approved template, or nothing', function () {
    arEnable($this, intents: ['location']);
    $c = arConversation($this->f->a);
    $message = arMessage($c, 'وين موقعكم؟');
    $c->forceFill(['last_inbound_at' => now()->subHours(25)])->save();

    arIntent($this->fake, 'location');
    expect(arReply($message)->reason)->toBe(AutoReplier::NOT_SENT)->and(arOutbound())->toBe([]);

    arEnable($this, intents: ['location'], template: ['name' => 'auto_answer', 'language' => 'ar']);
    $message = arMessage($c, 'وين موقعكم؟');
    $c->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
    arIntent($this->fake, 'location');
    $decision = arReply($message);

    expect($decision->outcome)->toBe(AiAutoReply::SENT)
        ->and($decision->reply->type)->toBe('template')
        ->and($decision->reply->template)->toBe('auto_answer')
        ->and(json_decode((string) $decision->reply->body, true)['params'])->toBe([
            // The answer alone, without the footer (the approved template holds its own wording).
            __('ai.autoreply.reply.location', ['facility' => 'منشأة الاختبار', 'address' => 'الرياض، طريق الملك فهد'], 'ar'),
        ]);
});

it('flattens a closed-window template parameter to one line Meta accepts', function () {
    arEnable($this, intents: ['order_status'], template: ['name' => 'auto_answer', 'language' => 'ar']);
    // Several open orders: the «which order» answer; and a stage name typed with a line break and spaces.
    openCustomOrder($this->f->sales, $this->f->a);
    $c = arConversation($this->f->a);
    $message = arMessage($c, "وين\nطلبي؟");
    $c->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
    arIntent($this->fake, 'order_status');

    $param = json_decode((string) arReply($message)->reply->body, true)['params'][0];

    expect($param)->not->toMatch('/[\r\n\t]/')->and($param)->not->toMatch('/ {2,}/')->and($param)->not->toBe('')
        ->and(ReplySender::templateParam("أ\n\n\tب    ج "))->toBe('أ ب ج');
});

// --- Hand-off note, audit, idempotency -----------------------------------------------------------------------------

it('sends the fixed hand-off note once a day when the owner chose it, and audits every automatic message', function () {
    arEnable($this, intents: ['location'], note: true);
    $c = arConversation($this->f->a);

    arIntent($this->fake, 'handoff');
    $first = arReply(arMessage($c, 'عندي مشكلة'));
    arIntent($this->fake, 'handoff');
    $second = arReply(arMessage($c, 'عندي مشكلة ثانية'));

    expect($first->reply_message_id)->not->toBeNull()
        ->and($first->reply->body)->toBe(arExpected('ai.autoreply.reply.handoff', []))
        ->and($second->reply_message_id)->toBeNull()
        ->and(arOutbound())->toHaveCount(1);

    $audit = AuditEvent::query()->where('action', 'ai.autoreply_sent')->sole();
    expect($audit->actor_user_id)->toBeNull()
        ->and($audit->source)->toBe('system')
        ->and(json_encode($audit->after))->not->toContain('مشكلة');
});

it('decides once per inbound message', function () {
    arEnable($this, intents: ['location']);
    $c = arConversation($this->f->a);
    $message = arMessage($c, 'وين موقعكم؟');
    arIntent($this->fake, 'location');

    $first = arReply($message);
    $again = arReply($message);

    expect($again->id)->toBe($first->id)->and(arOutbound())->toHaveCount(1);
    $this->fake->assertSentCount(1);
});

// --- Settings ------------------------------------------------------------------------------------------------------

it('lets only the facility owner see and change the settings (not a P-02 manager), off by default, audited', function () {
    $this->get('/settings/ai/auto-reply')->assertRedirect();
    $this->actingAs($this->f->sales)->get('/settings/ai/auto-reply')->assertForbidden();
    $this->actingAs($this->f->sales)->put('/settings/ai/auto-reply', ['intents' => [], 'handoff_note' => true])->assertForbidden();
    // Q-92: a manager holds P-02 but is not the owner.
    expect(Access::can($this->f->manager, Permission::P02))->toBeTrue();
    $this->actingAs($this->f->manager)->get('/settings/ai/auto-reply')->assertForbidden();
    $this->actingAs($this->f->manager)->put('/settings/ai/auto-reply', ['intents' => [], 'handoff_note' => true])->assertForbidden();
    $this->actingAs($this->f->manager)->get('/settings/ai')->assertOk()
        ->assertInertia(fn ($p) => $p->where('canManageAutoReply', false));
    $this->actingAs($this->owner)->get('/settings/ai')->assertOk()
        ->assertInertia(fn ($p) => $p->where('canManageAutoReply', true));
    expect(app(AutoReplySettings::class)->all()['handoff_note'])->toBeFalse();

    $this->actingAs($this->owner)->get('/settings/ai/auto-reply')->assertOk()
        ->assertInertia(fn ($p) => $p->component('account/settings-ai-auto-reply')
            ->where('intents.0.enabled', false)->where('intents.0.mode', 'outside_hours')
            ->where('featureEnabled', false)->where('templates.0.name', 'auto_answer'));

    $this->actingAs($this->owner)->put('/settings/ai/auto-reply', [
        'intents' => ['order_status' => ['enabled' => true, 'mode' => 'always']],
        'handoff_note' => false,
        'template' => ['name' => 'unknown', 'language' => 'ar'],
    ])->assertSessionHasErrors('template');

    $this->actingAs($this->owner)->put('/settings/ai/auto-reply', [
        'intents' => ['order_status' => ['enabled' => true, 'mode' => 'always']],
        'handoff_note' => false,
        'template' => null,
    ])->assertSessionHasNoErrors();

    expect(app(AutoReplySettings::class)->intent(AutoReplyIntent::OrderStatus))->toBe(['enabled' => true, 'mode' => 'always'])
        ->and(AuditEvent::query()->where('action', 'ai.settings_changed')->where('subject_label', 'AI auto reply')->count())->toBe(1);
    // Saving the feature switches keeps the auto-reply choices.
    Ai::enable($this->owner, AiFeature::AutoReply);
    expect(app(AutoReplySettings::class)->anyEnabled())->toBeTrue();
});
