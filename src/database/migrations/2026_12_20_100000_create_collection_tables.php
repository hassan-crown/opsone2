<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P6b: order money — dues, payments (M-11), customer transfer proofs (M-10), receipts and the append-only money
| journal (ADR-0014). Amounts are integer halalas; percentages are basis points. Receipts, journals and entries are
| never updated or deleted; a correction is a reversing journal.
*/
return new class extends Migration
{
    public function up(): void
    {
        // The finance head of an order: optimistic lock of every money write and the agreement the schedule follows.
        Schema::create('order_finances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        // Installments (FIN-02). The amount follows the active agreement; allocations point here.
        Schema::create('order_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // Matches a P5 payment_check `reference` (first, final, …).
            $table->string('reference', 30);
            $table->json('name');
            // amount | percent | rest
            $table->string('basis', 12);
            // halalas (amount) or basis points (percent); null for rest
            $table->unsignedBigInteger('basis_value')->nullable();
            $table->unsignedBigInteger('amount_minor');
            // agreement | payment_stage | date
            $table->string('due_event', 16);
            $table->date('due_date')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'removed_at', 'position']);
        });

        // M-11: one row per real money movement from the customer.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // The order it was recorded from; shares over other orders are journal entries.
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            // transfer | cash | card | cheque | online
            $table->string('method', 12);
            $table->date('paid_on');
            $table->string('reference', 80)->nullable();
            // method + normalized reference: the same transfer or provider transaction is one payment (INT-04).
            $table->string('reference_key', 100)->nullable()->unique();
            // staff | customer_proof | gateway
            $table->string('origin', 16);
            // M-11
            $table->string('status', 24);
            $table->string('gateway', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['customer_id', 'paid_on']);
            $table->index(['order_id', 'status']);
        });

        // «سند قبض»: exactly one per confirmed payment, gapless per year (Q-41).
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        // M-10: a transfer proof uploaded by the customer. Never money before confirmation.
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('claimed_minor');
            $table->string('method', 12);
            $table->date('paid_on');
            $table->string('reference', 80)->nullable();
            $table->string('reference_key', 100)->nullable();
            // M-10: under_review | needs_correction | confirmed | cancelled
            $table->string('status', 20);
            $table->foreignId('replaces_id')->nullable()->constrained('payment_proofs')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->string('submitted_mobile', 32)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('reference_key');
        });

        // The append-only journal of every effect of a payment (ADR-0014).
        Schema::create('money_journals', function (Blueprint $table) {
            $table->id();
            // collection | allocation | deallocation | transfer | reversal
            $table->string('kind', 16);
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('reverses_journal_id')->nullable()->unique()->constrained('money_journals')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->timestamp('created_at');

            $table->index(['payment_id', 'id']);
        });

        Schema::create('money_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('money_journals')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_due_id')->nullable()->constrained('order_dues')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at');

            $table->index(['order_id', 'order_due_id']);
            $table->index(['payment_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_entries');
        Schema::dropIfExists('money_journals');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_dues');
        Schema::dropIfExists('order_finances');
    }
};
