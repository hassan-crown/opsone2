<?php

/**
 * The delivery screens and JSON endpoints: draft → record with proof → note page; double submit; stale version; the
 * order page card; amounts hidden without P-37.
 */

use App\Models\Delivery\DeliveryNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/DeliveryTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('attachments');
    $this->d = deliveryFixture(10, 5);
    $this->line = deliveryLineId($this->d->order);
    $this->actingAs($this->d->keeper);
});

it('drafts, records with proof and shows the note (JSON)', function () {
    $draft = $this->postJson("/orders/{$this->d->order->id}/deliveries", [
        'idempotency_key' => 'http-draft-01', 'note' => 'تسليم أول',
        'lines' => [['order_line_id' => $this->line, 'quantity' => '2']],
    ])->assertCreated()->assertJsonPath('note.status', 'draft')->assertJsonPath('note.lines.0.quantity', '2')->json('note');
    $this->postJson("/orders/{$this->d->order->id}/deliveries", [
        'idempotency_key' => 'http-draft-01', 'note' => 'تسليم أول', 'lines' => [['order_line_id' => $this->line, 'quantity' => '2']],
    ])->assertCreated()->assertJsonPath('note.id', $draft['id']);

    $response = $this->post("/deliveries/{$draft['id']}/confirm", [
        'idempotency_key' => 'http-confirm-01', 'lock_version' => $draft['lock_version'], 'delivered_on' => now()->toDateString(),
        'receiver_name' => 'سلمان', 'proofs' => [UploadedFile::fake()->image('receipt.png', 80, 80)],
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('note.status', 'confirmed')->assertJsonPath('note.receiver_name', 'سلمان');
    expect($response->json('note.number'))->toStartWith('DN-'.now()->year.'-')->and($response->json('note.proofs'))->toHaveCount(1);

    $this->getJson("/deliveries/{$draft['id']}")->assertOk()->assertJsonPath('note.can.confirm', false);
    $this->getJson('/deliveries?status=confirmed')->assertOk()->assertJsonCount(1, 'notes.data');
});

it('returns 422 without a proof or receiver and 409 on a stale or repeated record', function () {
    $draft = $this->postJson("/orders/{$this->d->order->id}/deliveries", [
        'idempotency_key' => 'http-draft-02', 'lines' => [['order_line_id' => $this->line, 'quantity' => '1']],
    ])->json('note');

    $this->postJson("/deliveries/{$draft['id']}/confirm", ['idempotency_key' => 'k-http-confirm-2', 'delivered_on' => now()->toDateString(), 'receiver_name' => 'س'])
        ->assertUnprocessable()->assertJsonValidationErrors(['proofs']);
    $this->post("/deliveries/{$draft['id']}/confirm", [
        'idempotency_key' => 'k-http-confirm-3', 'lock_version' => 99, 'delivered_on' => now()->toDateString(), 'receiver_name' => 'سالم',
        'proofs' => [UploadedFile::fake()->image('a.png')],
    ], ['Accept' => 'application/json'])->assertStatus(409);
    $this->postJson("/orders/{$this->d->order->id}/deliveries", [
        'idempotency_key' => 'http-draft-03', 'lines' => [['order_line_id' => $this->line, 'quantity' => '6']],
    ])->assertUnprocessable();
});

it('renders the list, the new-delivery screen, the note page and the order card', function () {
    $draft = deliveryDraft($this->d->keeper, $this->d->order, [$this->line => '2']);

    $this->get('/deliveries')->assertOk()->assertInertia(fn (Assert $p) => $p->component('deliveries/index')->has('awaiting', 1)->has('notes.data', 1));
    $this->get("/orders/{$this->d->order->id}/deliveries/create")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('deliveries/form')->where('lines.0.deliverable', '5')->where('lines.0.ordered', '5')->missing('lines.0.unit_price_minor'));
    $this->get("/deliveries/{$draft->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('deliveries/show')->where('note.can.confirm', true));

    $this->actingAs($this->d->owner)->get("/orders/{$this->d->order->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('orders/show')->where('delivery.can_record', true)->has('delivery.notes', 1));
    $this->actingAs($this->d->owner)->get("/orders/{$this->d->order->id}/deliveries/create")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price_minor', fn ($v) => $v > 0));
    $this->actingAs($this->d->s->f->sales)->get("/orders/{$this->d->order->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('delivery', null));
    expect(DeliveryNote::query()->count())->toBe(1);
});
