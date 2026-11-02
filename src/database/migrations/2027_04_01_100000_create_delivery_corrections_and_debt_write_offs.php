<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // M-14-T03 (ADR-0035): a correction of a confirmed delivery. The note and its lines stay as recorded; the
        // correction takes part of a line's quantity back and says where ready stock went (general stock or the
        // customer's reservation). The first-delivery date it saw is kept for a future due-rule engine.
        Schema::create('delivery_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // stock | reservation; null when no line moves stock (made-to-order lines)
            $table->string('destination', 16)->nullable();
            $table->text('reason');
            $table->date('first_delivery_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index('delivery_note_id');
        });

        // One row per note line and reservation piece taken back (a ready line may have been issued from several
        // reservations; a made-to-order line has none).
        Schema::create('delivery_correction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_correction_id')->constrained('delivery_corrections')->restrictOnDelete();
            $table->foreignId('delivery_note_line_id')->constrained('delivery_note_lines')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_milli');
            $table->timestamps();

            $table->index('delivery_note_line_id');
            $table->index('order_line_id');
        });

        // Debt write-off at closure (ADR-0035): an append-only, balanced journal. A write-off credits the receivable
        // of each open due and debits `bad_debt` by the same total; a reversal is its exact negation, once.
        Schema::create('debt_write_offs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // write_off | reversal
            $table->string('kind', 16);
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('debt_write_offs')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->text('reason');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->timestamp('created_at');

            $table->index(['order_id', 'kind']);
            $table->index('created_at');
        });

        Schema::create('debt_write_off_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_write_off_id')->constrained('debt_write_offs')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_due_id')->nullable()->constrained('order_dues')->restrictOnDelete();
            // receivable (per due) | bad_debt
            $table->string('account', 16);
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at');

            $table->index(['order_id', 'account']);
            $table->index('order_due_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_write_off_entries');
        Schema::dropIfExists('debt_write_offs');
        Schema::dropIfExists('delivery_correction_lines');
        Schema::dropIfExists('delivery_corrections');
    }
};
