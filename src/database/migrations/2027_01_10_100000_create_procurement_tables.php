<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7 part B (domain 06, FUL-05..07): suppliers, purchase orders (M-13) and supplier receipts — ADR-0017. Quantities are
| integers in thousandths of the item's fixed unit (Q-43, no conversion); amounts are integer halalas. A location is
| referenced by id only: the stock domain owns it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 160)->nullable();
            // Saudi VAT registration number: 15 digits, first and last 3.
            $table->string('vat_number', 15)->nullable()->unique();
            $table->string('supplies', 500)->nullable();
            $table->text('notes')->nullable();
            // active | disabled — disabling keeps every purchase made from the supplier.
            $table->string('status', 12)->default('active')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            // PO-YYYY-000123, gapless per year (GaplessSequence).
            $table->string('number', 24)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            // The stock location receipts land in (stock domain, by id only).
            $table->unsignedBigInteger('location_id')->nullable()->index();
            // M-13: draft | ordered | partially_received | received | cancelled_remainder
            $table->string('status', 24)->index();
            $table->date('expected_on')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // Facility VAT policy snapshot (Q-35 a) taken when the order is created / confirmed.
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->boolean('prices_include_vat');
            // Sums of the rounded lines that carry a cost.
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            // material | ready_variant
            $table->string('item_type', 16);
            $table->unsignedBigInteger('item_id');
            $table->json('item_name');
            $table->string('unit', 24);
            $table->unsignedBigInteger('quantity_milli');
            // Reference price per unit; null when the writer lacks P-82.
            $table->unsignedBigInteger('unit_cost_minor')->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            // The order this supply serves (optional).
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // Running totals of the receipts: what arrived, what was accepted (satisfies the order), what was rejected.
            $table->unsignedBigInteger('received_milli')->default(0);
            $table->unsignedBigInteger('accepted_milli')->default(0);
            $table->unsignedBigInteger('rejected_milli')->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_no']);
            $table->index(['item_type', 'item_id']);
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->date('received_on');
            // The supplier's delivery note number.
            $table->string('reference', 80)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_order_id');
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            // received = accepted + rejected: what physically arrived.
            $table->unsignedBigInteger('received_milli');
            $table->unsignedBigInteger('accepted_milli');
            $table->unsignedBigInteger('rejected_milli');
            $table->string('reject_reason', 500)->nullable();
            $table->timestamps();

            $table->index('purchase_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
