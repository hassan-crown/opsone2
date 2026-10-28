<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P6d: sale ownership and commissions (ADR-0016, SAL-10..16, SAL-19, INT-06, M-33). Amounts are integer halalas, rates
| basis points. Commission entries are append-only (a correction is a new signed entry); a sale owner or rate
| correction supersedes the sale row and moves its accrual with two linked entries, so history is never rewritten.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Facility commission policy (one row, defaults until saved). Applies to sales pinned from now on (SAL-19).
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('default_rate_bp')->default(0);
            // Whether lines typed into the quote by hand (no order line) count when marked commissionable.
            $table->boolean('manual_lines_eligible')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        // Optional rate per role template or per member (a member override wins).
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_template_id')->nullable()->unique()->constrained('role_templates')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('rate_bp');
            $table->timestamps();
        });

        // «صاحب المبيعة» of an order with the rate pinned at the agreement (SAL-11, SAL-12). One active row per order.
        Schema::create('order_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('rate_bp');
            // member | role | default | disabled | corrected
            $table->string('rate_source', 12);
            $table->boolean('manual_lines_eligible');
            // assignee | creator | correction
            $table->string('owner_source', 12);
            $table->foreignId('agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            // active | superseded
            $table->string('status', 12);
            // order_id while active: one active sale per order even under concurrency.
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            $table->foreignId('replaces_id')->nullable()->constrained('order_sales')->restrictOnDelete();
            $table->timestamp('pinned_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        // Accrual and correction entries (SAL-13): signed differences whose sum is the sale's accrual.
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_sale_id')->constrained('order_sales')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // accrual | transfer_out | transfer_in
            $table->string('kind', 14);
            $table->bigInteger('amount_minor');
            // The derivation behind an accrual (null on transfers): eligible base, collected, agreed value, rate.
            $table->unsignedBigInteger('eligible_minor')->nullable();
            $table->unsignedBigInteger('collected_minor')->nullable();
            $table->unsignedBigInteger('agreed_minor')->nullable();
            $table->unsignedSmallInteger('rate_bp');
            // The sale's accrual after this entry.
            $table->bigInteger('accrued_minor');
            $table->string('source', 80);
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['order_sale_id', 'id']);
        });

        // M-33 «صرف العمولة»: one member, one period, reserved lines.
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            // draft | reserved | paid | cancelled | corrected
            $table->string('status', 12);
            $table->bigInteger('amount_minor')->nullable();
            $table->string('method', 12)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('note', 500)->nullable();
            // M-33-T04: what really left, with its reason and proof.
            $table->bigInteger('corrected_minor')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // What a payout takes from each sale (or a member-level adjustment, sale null). Released when it is cancelled.
        Schema::create('commission_payout_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('commission_payouts')->restrictOnDelete();
            $table->foreignId('order_sale_id')->nullable()->constrained('order_sales')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamp('created_at');

            $table->index('order_sale_id');
        });

        // Owner or rate correction of a pinned sale (SAL-16, P-98/P-97): requested with a reason, decided by another.
        Schema::create('sale_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_sale_id')->constrained('order_sales')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // owner | rate
            $table->string('kind', 8);
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('from_rate_bp');
            $table->unsignedSmallInteger('to_rate_bp');
            $table->string('reason', 500);
            // pending | approved | rejected | cancelled
            $table->string('status', 10);
            // order_id while pending: one open correction per order.
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->foreignId('result_sale_id')->nullable()->constrained('order_sales')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_corrections');
        Schema::dropIfExists('commission_payout_lines');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('order_sales');
        Schema::dropIfExists('commission_rates');
        Schema::dropIfExists('commission_settings');
    }
};
