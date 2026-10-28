<?php

use App\Actions\StateMachines\M05ReadyOrder\T01ConfirmReadyOrder;
use App\Actions\StateMachines\M14Delivery\T00CreateDraft;
use App\Actions\StateMachines\M14Delivery\T01ConfirmDelivery;
use App\Inventory\Quantity;
use App\Models\Delivery\DeliveryNote;
use App\Models\Order;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

require_once __DIR__.'/../Inventory/InventoryTestHelpers.php';

/**
 * Delivery fixture on the stock fixture: `stock` units of the ready variant at store A1 and a confirmed (reserved)
 * ready order of `quantity`; also an inventory officer (P-52) and an executor of branch A.
 *
 * @return object{s: object, order: Order, owner: User, keeper: User}
 */
function deliveryFixture(int $stock = 10, int $quantity = 5): object
{
    $s = stockFixture();
    stockReceive($s->la, 'ready_variant', $s->f->variant->id, (string) $stock);
    $order = readyOrder($s, $quantity);
    LockingTransaction::run(fn () => app(T01ConfirmReadyOrder::class)->handle($s->f->org->owner, $order, $s->la->id));

    return (object) ['s' => $s, 'order' => $order->refresh(), 'owner' => $s->f->org->owner, 'keeper' => $s->f->org->member('inventory', [$s->f->org->a])];
}

/**
 * @param  array<int, string>  $quantities  order line id → quantity text
 */
function deliveryDraft(User $actor, Order $order, array $quantities, ?string $key = null): DeliveryNote
{
    $lines = [];
    foreach ($quantities as $lineId => $quantity) {
        $lines[] = ['order_line_id' => $lineId, 'quantity_milli' => Quantity::parse($quantity)];
    }

    return app(T00CreateDraft::class)->handle($actor, $order, $lines, null, $key ?? (string) Str::uuid());
}

function deliveryProof(string $name = 'receipt.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%receipt ".Str::random(8)."\n%%EOF");
}

/**
 * @param  array<string, mixed>  $data
 */
function deliveryConfirm(User $actor, DeliveryNote $note, ?string $key = null, array $data = [], ?array $proofs = null): DeliveryNote
{
    return app(T01ConfirmDelivery::class)->handle(
        $actor, $note->refresh(),
        ['delivered_on' => now()->toDateString(), 'receiver_name' => 'خالد العتيبي', 'note' => 'سُلّم عند الباب', ...$data],
        $proofs ?? [deliveryProof()], null, $key ?? (string) Str::uuid(),
    );
}

function deliveryLineId(Order $order): int
{
    return (int) $order->activeLines()->firstOrFail()->id;
}
