<?php

/**
 * WhatsApp inbox (AI-3, ADR-0030 amendment): the signed webhook (valid / wrong / missing signature), idempotent inbound
 * processing, the 24-hour window (free text inside, approved template outside — checked on queuing and again at
 * delivery), media type/size checks into private storage, P-108 with branch isolation for lists, pages and writes,
 * assignment, customer linking, and the owner's off switch.
 */

use App\Inbox\InboxSettings;
use App\Inbox\Jobs\SendConversationMessage;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Inbox\Conversation;
use App\Models\Inbox\ConversationMessage;
use App\Whatsapp\Inbox\ConversationTransport;
use App\Whatsapp\Inbox\FakeConversationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Org;

uses(RefreshDatabase::class);

const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

beforeEach(function () {
    Storage::fake('attachments');
    $this->withoutVite();
    config(['whatsapp.driver' => 'fake', 'whatsapp.cloud.app_secret' => 'inbox-secret', 'whatsapp.cloud.phone_number_id' => '1000']);
    $this->transport = new FakeConversationTransport;
    app()->instance(ConversationTransport::class, $this->transport);

    $this->org = Org::make();
    $this->managerA = $this->org->member('manager', [$this->org->a]);
    $this->managerB = $this->org->member('manager', [$this->org->b]);
    $this->salesA = $this->org->member('sales', [$this->org->a]);
    $this->executorA = $this->org->member('executor', [$this->org->a], $this->org->d1);
    $this->customerA = Customer::factory()->create(['branch_id' => $this->org->a->id, 'mobile' => '+966500000001']);

    $this->enable = fn (bool $on = true, ?int $branch = null, array $templates = [['name' => 'follow_up', 'language' => 'ar', 'params' => 1, 'label' => 'متابعة']]) => app(InboxSettings::class)->save($this->org->owner, $on, $branch, $templates);
    ($this->enable)();
});

function inboxPayload(array $messages, string $phoneNumberId = '1000', array $statuses = []): string
{
    return json_encode(['entry' => [['changes' => [['value' => [
        'metadata' => ['phone_number_id' => $phoneNumberId],
        'contacts' => [['wa_id' => '966500000001', 'profile' => ['name' => 'Ahmad']]],
        'messages' => $messages,
        'statuses' => $statuses,
    ]]]]]]);
}

function inboxText(string $id = 'wamid.in1', string $from = '966500000001', string $text = 'السلام عليكم، متى موعد التركيب؟', ?int $ts = null): array
{
    return ['id' => $id, 'from' => $from, 'type' => 'text', 'timestamp' => (string) ($ts ?? now()->timestamp), 'text' => ['body' => $text]];
}

function inboxPost(object $test, string $body, ?string $secret = 'inbox-secret')
{
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/webhooks/whatsapp', [], [], [], $headers, $body);
}

function inboxConversation(): Conversation
{
    return Conversation::query()->where('wa_id', '+966500000001')->firstOrFail();
}

// --- Webhook signature -------------------------------------------------------------------------------------------

it('stores a correctly signed inbound message in a conversation linked to the customer by number', function () {
    inboxPost($this, inboxPayload([inboxText()]))->assertOk()->assertJsonPath('inbox', 1);

    $conversation = inboxConversation();
    $message = $conversation->messages()->sole();
    expect($conversation->customer_id)->toBe($this->customerA->id)
        ->and($conversation->branch_id)->toBe($this->org->a->id)
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->contact_name)->toBe('Ahmad')
        ->and($conversation->windowOpen())->toBeTrue()
        ->and($message->direction)->toBe('in')
        ->and($message->body)->toBe('السلام عليكم، متى موعد التركيب؟');

    // Encrypted at rest.
    $raw = (string) DB::table('conversation_messages')->value('body');
    expect($raw)->not->toContain('السلام');
});

it('refuses a wrong signature, a missing signature and a missing app secret before reading the body', function () {
    $body = inboxPayload([inboxText()]);

    inboxPost($this, $body, 'not-the-secret')->assertForbidden();
    inboxPost($this, $body, null)->assertForbidden();
    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='], $body)->assertForbidden();

    config(['whatsapp.cloud.app_secret' => null]);
    inboxPost($this, $body, '')->assertForbidden();

    expect(Conversation::query()->count())->toBe(0)->and(ConversationMessage::query()->count())->toBe(0);
});

it('ignores a change addressed to another phone number of the same Meta app', function () {
    inboxPost($this, inboxPayload([inboxText()], '9999'))->assertOk()->assertJsonPath('inbox', 0);

    expect(ConversationMessage::query()->count())->toBe(0);
});

// --- Idempotency ---------------------------------------------------------------------------------------------------

it('processes a replayed webhook only once (dedupe by message id)', function () {
    $body = inboxPayload([inboxText(), inboxText()]);
    inboxPost($this, $body)->assertOk()->assertJsonPath('inbox', 1);
    inboxPost($this, $body)->assertOk()->assertJsonPath('inbox', 0);

    expect(ConversationMessage::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(inboxConversation()->unread_count)->toBe(1);
});

it('does not send a reply twice when the composer submits the same key twice', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();
    $key = (string) Str::uuid();

    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => $key, 'body' => 'أهلاً'])->assertRedirect();
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => $key, 'body' => 'أهلاً'])->assertRedirect();

    expect($this->transport->texts)->toHaveCount(1)
        ->and(ConversationMessage::query()->where('direction', 'out')->count())->toBe(1);
});

// --- 24-hour window ------------------------------------------------------------------------------------------------

it('allows free text inside the window and sends it to the contact', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();

    $this->actingAs($this->salesA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'الموعد غداً الساعة 10'])
        ->assertRedirect("/inbox/{$conversation->id}")->assertSessionHasNoErrors();

    $reply = $conversation->messages()->where('direction', 'out')->sole();
    expect($this->transport->texts)->toBe([['to' => '+966500000001', 'body' => 'الموعد غداً الساعة 10']])
        ->and($reply->status)->toBe('sent')
        ->and($reply->provider_message_id)->toStartWith('wamid.inbox')
        ->and($reply->sent_by)->toBe($this->salesA->id);
});

it('refuses free text outside the window and accepts only an approved template with its parameters', function () {
    inboxPost($this, inboxPayload([inboxText(ts: now()->subHours(25)->timestamp)]));
    $conversation = inboxConversation();
    expect($conversation->windowOpen())->toBeFalse();
    $as = $this->actingAs($this->managerA);

    $as->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'مرحباً'])->assertSessionHasErrors('body');
    $as->post("/inbox/{$conversation->id}/replies", ['kind' => 'template', 'key' => (string) Str::uuid(), 'template' => 'not_approved', 'language' => 'ar', 'params' => ['x']])->assertSessionHasErrors('template');
    $as->post("/inbox/{$conversation->id}/replies", ['kind' => 'template', 'key' => (string) Str::uuid(), 'template' => 'follow_up', 'language' => 'ar', 'params' => []])->assertSessionHasErrors('params');
    expect($this->transport->texts)->toBe([])->and($this->transport->templates)->toBe([])
        ->and(ConversationMessage::query()->where('direction', 'out')->count())->toBe(0);

    $as->post("/inbox/{$conversation->id}/replies", ['kind' => 'template', 'key' => (string) Str::uuid(), 'template' => 'follow_up', 'language' => 'ar', 'params' => ['أحمد']])->assertSessionHasNoErrors();
    expect($this->transport->templates)->toHaveCount(1)
        ->and($this->transport->templates[0]->template)->toBe('follow_up')
        ->and($this->transport->templates[0]->parameters)->toBe(['أحمد'])
        ->and($conversation->messages()->where('direction', 'out')->sole()->status)->toBe('sent');
});

it('re-checks the window at delivery: a text queued just before it closed is not sent', function () {
    Queue::fake();
    inboxPost($this, inboxPayload([inboxText(ts: now()->subHours(23)->subMinutes(59)->timestamp)]));
    $conversation = inboxConversation();
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'رد متأخر'])->assertSessionHasNoErrors();
    $reply = $conversation->messages()->where('direction', 'out')->sole();

    $this->travel(5)->minutes();
    app()->call([new SendConversationMessage($reply->id), 'handle']);

    expect($this->transport->texts)->toBe([])
        ->and($reply->refresh()->status)->toBe('failed')
        ->and($reply->error)->toBe('window_closed');
});

it('does not reply to a customer who sent STOP', function () {
    inboxPost($this, inboxPayload([inboxText(text: 'إيقاف')]));
    $conversation = inboxConversation();
    expect($this->customerA->refresh()->whatsapp_opt_out_at)->not->toBeNull();

    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'تم'])->assertSessionHasErrors('body');
    expect($this->transport->texts)->toBe([]);
});

it('moves an outbound reply forward with delivery statuses only', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'تم']);
    $reply = $conversation->messages()->where('direction', 'out')->sole();

    inboxPost($this, inboxPayload([], statuses: [['id' => $reply->provider_message_id, 'status' => 'read']]))->assertOk();
    inboxPost($this, inboxPayload([], statuses: [['id' => $reply->provider_message_id, 'status' => 'delivered']]))->assertOk();

    expect($reply->refresh()->status)->toBe('read');
});

// --- Media ---------------------------------------------------------------------------------------------------------

it('stores an allowed image as a private attachment of the conversation', function () {
    $this->transport->media['media-1'] = ['bytes' => PNG_BYTES, 'mime' => 'image/png'];
    inboxPost($this, inboxPayload([['id' => 'wamid.img', 'from' => '966500000001', 'type' => 'image', 'timestamp' => (string) now()->timestamp, 'image' => ['id' => 'media-1', 'mime_type' => 'image/png', 'caption' => 'المقاس']]]))->assertOk();

    $message = ConversationMessage::query()->sole();
    $attachment = Attachment::query()->sole();
    expect($message->media_status)->toBe('stored')
        ->and($message->attachment_id)->toBe($attachment->id)
        ->and($message->body)->toBe('المقاس')
        ->and($attachment->attachable_type)->toBe((new Conversation)->getMorphClass())
        ->and($attachment->mime)->toBe('image/png');
    Storage::disk('attachments')->assertExists($attachment->path);
});

it('rejects a disallowed type without downloading it, and an oversized or disguised file', function () {
    $this->transport->media['exe'] = ['bytes' => 'MZ...', 'mime' => 'application/x-msdownload'];
    $this->transport->media['big'] = ['bytes' => PNG_BYTES, 'mime' => 'image/png', 'size' => 100 * 1024 * 1024];
    $this->transport->media['fake'] = ['bytes' => '<?php echo 1;', 'mime' => 'image/png'];
    $msg = fn (string $id, string $media, string $mime) => ['id' => $id, 'from' => '966500000001', 'type' => 'document', 'timestamp' => (string) now()->timestamp, 'document' => ['id' => $media, 'mime_type' => $mime]];

    inboxPost($this, inboxPayload([$msg('wamid.a', 'exe', 'application/x-msdownload'), $msg('wamid.b', 'big', 'image/png'), $msg('wamid.c', 'fake', 'image/png')]))->assertOk();

    $statuses = ConversationMessage::query()->orderBy('id')->get()->map(fn ($m) => [$m->media_status, $m->error])->all();
    expect($statuses)->toBe([['rejected', 'type'], ['rejected', 'size'], ['rejected', 'type']])
        ->and($this->transport->downloaded)->not->toContain('exe')
        ->and(Attachment::query()->count())->toBe(0);
});

// --- Permissions and branch isolation -------------------------------------------------------------------------------

it('lists and opens conversations only inside the P-108 branch scope', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();

    $this->actingAs($this->managerA)->get('/inbox')->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/index')->has('conversations.data', 1));
    $this->actingAs($this->managerA)->get("/inbox/{$conversation->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/show')
            ->where('conversation.messages.0.body', 'السلام عليكم، متى موعد التركيب؟')
            ->where('conversation.can.reply', true));
    expect($conversation->refresh()->unread_count)->toBe(0);

    $this->actingAs($this->managerB)->get('/inbox')->assertOk()
        ->assertInertia(fn ($page) => $page->has('conversations.data', 0));
    $this->actingAs($this->managerB)->get("/inbox/{$conversation->id}")->assertForbidden();
    $this->actingAs($this->managerB)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'x'])->assertForbidden();
    $this->actingAs($this->managerB)->post("/inbox/{$conversation->id}/assign", ['assignee_id' => $this->managerB->id])->assertForbidden();

    // No P-108 at all.
    $this->actingAs($this->executorA)->get('/inbox')->assertForbidden();
    $this->actingAs($this->executorA)->get("/inbox/{$conversation->id}")->assertForbidden();

    expect($this->transport->texts)->toBe([]);
});

it('shows an unknown number to the default inbox branch, or only to facility-wide grants without one', function () {
    inboxPost($this, inboxPayload([inboxText(from: '966511111111')]));
    $unknown = Conversation::query()->where('wa_id', '+966511111111')->firstOrFail();
    expect($unknown->customer_id)->toBeNull()->and($unknown->branch_id)->toBeNull();

    $this->actingAs($this->managerA)->get("/inbox/{$unknown->id}")->assertForbidden();
    $this->actingAs($this->org->owner)->get("/inbox/{$unknown->id}")->assertOk();

    ($this->enable)(true, $this->org->a->id);
    inboxPost($this, inboxPayload([inboxText(id: 'wamid.x2', from: '966522222222')]));
    $routed = Conversation::query()->where('wa_id', '+966522222222')->firstOrFail();
    expect($routed->branch_id)->toBe($this->org->a->id);
    $this->actingAs($this->managerA)->get("/inbox/{$routed->id}")->assertOk();
    $this->actingAs($this->managerB)->get("/inbox/{$routed->id}")->assertForbidden();
});

it('assigns only to staff who may open the conversation, and audits it', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();

    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/assign", ['assignee_id' => $this->managerB->id])->assertSessionHasErrors('assignee_id');
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/assign", ['assignee_id' => $this->executorA->id])->assertSessionHasErrors('assignee_id');
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/assign", ['assignee_id' => $this->salesA->id])->assertSessionHasNoErrors();

    expect($conversation->refresh()->assignee_id)->toBe($this->salesA->id)
        ->and(AuditEvent::query()->where('action', 'conversation.assigned')->count())->toBe(1);

    $this->actingAs($this->salesA)->get('/inbox?view=mine')->assertInertia(fn ($page) => $page->has('conversations.data', 1));
});

it('links an unknown contact to a visible customer, after which the conversation follows the customer branch', function () {
    inboxPost($this, inboxPayload([inboxText(from: '966533333333')]));
    $conversation = Conversation::query()->where('wa_id', '+966533333333')->firstOrFail();
    $customerB = Customer::factory()->create(['branch_id' => $this->org->b->id]);

    $this->actingAs($this->org->owner)->post("/inbox/{$conversation->id}/link", ['customer_id' => $customerB->id])->assertRedirect();
    expect($conversation->refresh()->customer_id)->toBe($customerB->id)
        ->and($conversation->branch_id)->toBe($this->org->b->id);
    $this->actingAs($this->managerB)->get("/inbox/{$conversation->id}")->assertOk();

    $customerB->forceFill(['branch_id' => $this->org->a->id])->save();
    expect($conversation->refresh()->branch_id)->toBe($this->org->a->id);
    $this->actingAs($this->managerB)->get("/inbox/{$conversation->id}")->assertForbidden();
});

// --- Off switch -------------------------------------------------------------------------------------------------------

it('when switched off stores no inbound message, hides the pages and sends nothing, while STOP keeps working', function () {
    inboxPost($this, inboxPayload([inboxText()]));
    $conversation = inboxConversation();
    ($this->enable)(false);

    inboxPost($this, inboxPayload([inboxText(id: 'wamid.off', text: 'STOP')]))->assertOk()->assertJsonPath('inbox', 0)->assertJsonPath('stops', 1);
    expect(ConversationMessage::query()->count())->toBe(1);

    $this->actingAs($this->managerA)->get('/inbox')->assertNotFound();
    $this->actingAs($this->managerA)->get("/inbox/{$conversation->id}")->assertNotFound();
    $this->actingAs($this->managerA)->post("/inbox/{$conversation->id}/replies", ['kind' => 'text', 'key' => (string) Str::uuid(), 'body' => 'x'])->assertNotFound();
    expect($this->transport->texts)->toBe([]);
});

it('lets only P-02 holders change the inbox settings, audited', function () {
    $this->actingAs($this->salesA)->put('/settings/integrations/whatsapp-inbox', ['enabled' => false])->assertForbidden();
    $this->actingAs($this->org->owner)->put('/settings/integrations/whatsapp-inbox', [
        'enabled' => true,
        'default_branch_id' => $this->org->b->id,
        'templates' => [['name' => 'Bad Name', 'language' => 'ar', 'params' => 0]],
    ])->assertSessionHasErrors('templates.0.name');
    $this->actingAs($this->org->owner)->put('/settings/integrations/whatsapp-inbox', [
        'enabled' => true,
        'default_branch_id' => $this->org->b->id,
        'templates' => [['name' => 'payment_link', 'language' => 'en', 'params' => 2, 'label' => 'Payment']],
    ])->assertSessionHasNoErrors();

    $settings = app(InboxSettings::class);
    expect($settings->defaultBranchId())->toBe($this->org->b->id)
        ->and($settings->templates())->toBe([['name' => 'payment_link', 'language' => 'en', 'params' => 2, 'label' => 'Payment']])
        ->and(AuditEvent::query()->where('action', 'integrations.whatsapp_inbox_saved')->count())->toBe(2);
});
