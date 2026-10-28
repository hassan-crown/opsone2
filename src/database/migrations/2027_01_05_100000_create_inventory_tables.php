<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Inventory (P7a, domain 06, ADR-0017): locations, tracked items with one fixed unit (Q-43 a), balances derived from an
| append-only movement journal, reservations (M-24), transfers (M-25) and counts. Quantities are integers in
| thousandths of the item's unit (`*_milli`), never floats. Rows of the journal are never updated or deleted.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            // Q-43 (a): the fixed unit codes this facility enabled (App\Inventory\StockUnit).
            $table->json('enabled_units');
            $table->boolean('low_stock_alerts')->default(true);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->json('name');
            // Optional branch: a location without one is facility-wide (covered by tenant-scope grants only).
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('status', 16)->default('active');
            $table->foreignId('manager_id')->nullable()->constrained('users');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            // ready_variant | material
            $table->string('item_type', 16);
            $table->unsignedBigInteger('item_id');
            $table->string('unit', 24);
            // Optional reorder level (alert only, never an automatic purchase).
            $table->unsignedBigInteger('reorder_level_milli')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['item_type', 'item_id']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('stock_item_id')->constrained('stock_items');
            // Projection of the journal kept in the same transaction: on_hand = Σ on_hand_delta, reserved = Σ reserved_delta.
            $table->unsignedBigInteger('on_hand_milli')->default(0);
            $table->unsignedBigInteger('reserved_milli')->default(0);
            $table->timestamps();
            $table->unique(['location_id', 'stock_item_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines');
            // ready_order | custom_order
            $table->string('purpose', 16);
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('issued_milli')->default(0);
            $table->unsignedBigInteger('released_milli')->default(0);
            // M-24: active, partially_issued, issued, released, settled_mixed
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['stock_item_id', 'status']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('from_location_id')->constrained('stock_locations');
            $table->foreignId('to_location_id')->constrained('stock_locations');
            // M-25: draft, in_transit, received, cancelled
            $table->string('status', 16)->default('draft');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers');
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->unsignedBigInteger('quantity_milli');
            $table->timestamps();
            $table->unique(['transfer_id', 'stock_item_id']);
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('location_id')->constrained('stock_locations');
            // draft, submitted, approved, rejected
            $table->string('status', 16)->default('draft');
            $table->string('reason', 500);
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('stock_counts');
            $table->foreignId('stock_item_id')->constrained('stock_items');
            // On-hand when the line was counted; the approval posts counted − on-hand at approval time.
            $table->unsignedBigInteger('expected_milli');
            $table->unsignedBigInteger('counted_milli');
            $table->timestamps();
            $table->unique(['count_id', 'stock_item_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // receipt, issue, reserve, release, issue_reserved, transfer_out, transfer_in, adjustment_in, adjustment_out
            $table->string('kind', 20);
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('stock_item_id')->constrained('stock_items');
            $table->string('unit', 24);
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('on_hand_delta');
            $table->bigInteger('reserved_delta');
            // Balance after the movement (both columns), for a readable history without re-summing.
            $table->unsignedBigInteger('on_hand_after');
            $table->unsignedBigInteger('reserved_after');
            $table->string('document_number', 24)->nullable()->index();
            $table->foreignId('reservation_id')->nullable()->constrained('stock_reservations');
            $table->foreignId('transfer_id')->nullable()->constrained('stock_transfers');
            $table->foreignId('count_id')->nullable()->constrained('stock_counts');
            $table->foreignId('order_id')->nullable()->constrained('orders');
            // Free reference of the producing domain (e.g. purchase_receipt / 12).
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['location_id', 'stock_item_id', 'id']);
            $table->index(['source_type', 'source_id']);
        });

        $now = now();
        DB::table('inventory_settings')->insert([
            'enabled_units' => json_encode(['piece', 'meter', 'square_meter', 'sheet']),
            'low_stock_alerts' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        // FUL «موقع افتراضي واحد»: one facility-wide default location.
        DB::table('stock_locations')->insert([
            'code' => 'MAIN', 'name' => json_encode(['ar' => 'المستودع الرئيسي', 'en' => 'Main store'], JSON_UNESCAPED_UNICODE),
            'branch_id' => null, 'status' => 'active', 'is_default' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('inventory_settings');
    }
};
