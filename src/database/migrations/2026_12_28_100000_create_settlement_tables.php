<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P6c: refunds (M-12), corrections of confirmed payments (M-11-T07), financial disputes (M-39) and external accounting
| documents (M-40) — ADR-0015. Amounts are integer halalas. None of these rows is money by itself: an executed refund
| and a correction act on the append-only journal of ADR-0014.
*/
return new class extends Migration
{
    public function up(): void
    {
        // M-12: a refund of part of one payment's money on one order.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            // «سند صرف» RF-YYYY-000123, taken when the refund is executed (gapless, Q-41).
            $table->string('number', 32)->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            // transfer | cash | card | cheque
            $table->string('method', 12);
            $table->text('reason');
            // M-12
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempt')->default(0);
            // The bank / provider reference of the current attempt.
            $table->string('reference', 80)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('journal_id')->nullable()->unique()->constrained('money_journals')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['payment_id', 'order_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // M-11-T07: the documented correction of a confirmed payment («سند تصحيح» CR-YYYY-000123).
        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // void | amend
            $table->string('kind', 8);
            $table->text('reason');
            $table->foreignId('replacement_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->json('reversed_journals');
            $table->json('before');
            $table->json('after')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // M-39: a financial dispute on an order and one of its money movements.
        Schema::create('financial_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            // The due whose part is held from reminders while the dispute is open.
            $table->foreignId('order_due_id')->nullable()->constrained('order_dues')->restrictOnDelete();
            $table->string('subject', 16);
            $table->text('reason');
            $table->unsignedBigInteger('amount_minor');
            // Required when the amount is above the movement's amount.
            $table->string('amount_note', 500)->nullable();
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            // M-39: open | awaiting_external | resolved
            $table->string('status', 20);
            $table->string('external_party', 120)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['order_due_id', 'status']);
        });

        // M-40: an invoice or note issued by the external accounting system, linked to an order event.
        Schema::create('external_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('event', 12);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            // The accounting system that issued it, and its number there: unique per source (FIN-10).
            $table->string('source', 60)->nullable();
            $table->string('source_key', 60)->nullable();
            $table->string('number', 60)->nullable();
            $table->string('number_key', 60)->nullable();
            $table->date('issued_on')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('note', 500)->nullable();
            // M-40: requested | attached | accepted | needs_correction
            $table->string('status', 20);
            $table->string('correction_reason', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['source_key', 'number_key']);
            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_documents');
        Schema::dropIfExists('financial_disputes');
        Schema::dropIfExists('payment_corrections');
        Schema::dropIfExists('refunds');
    }
};
