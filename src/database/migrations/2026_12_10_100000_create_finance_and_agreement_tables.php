<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P6a: money settings and the agreement inside the order (domain 04, ADR-0013). Amounts are integer halalas; rates and
| limits are basis points (1500 = 15%). Sent documents, customer decisions and agreement versions are append-only.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Facility finance settings (Q-35 a): one row; defaults until saved.
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->boolean('prices_include_vat');
            $table->unsignedSmallInteger('quote_validity_days');
            $table->boolean('contracts_enabled');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Discount limits (AGR-02, SAL-09): per role template, or a member override. The owner has none (unlimited).
        Schema::create('discount_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_template_id')->nullable()->unique()->constrained('role_templates')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->unique()->constrained('memberships')->cascadeOnDelete();
            $table->unsignedSmallInteger('custom_bp')->default(0);
            $table->unsignedSmallInteger('ready_bp')->default(0);
            // Highest discount this holder may approve for others (P-31).
            $table->unsignedSmallInteger('approval_bp')->default(0);
            $table->timestamps();
        });

        // Documents that ask for the customer's approval (M-08): quote, design, contract.
        Schema::create('agreement_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // quote | design | contract
            $table->string('kind', 16);
            $table->string('number', 32)->unique();
            // M-08
            $table->string('status', 24);
            $table->foreignId('replaces_id')->nullable()->constrained('agreement_documents')->restrictOnDelete();
            $table->json('content');
            // sha256 of the content, fixed when sent: an approval names the content it approves.
            $table->string('content_hash', 64)->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'kind', 'status']);
        });

        // Customer decisions on a document or a change (AGR-04/05/09): web approval from the customer's account or an
        // external approval recorded by staff with evidence (Q-40 a: no e-signature provider).
        Schema::create('customer_decisions', function (Blueprint $table) {
            $table->id();
            // document | change
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('content_hash', 64);
            // approved | changes_requested
            $table->string('decision', 24);
            // customer_account | external
            $table->string('method', 24);
            // phone | message | paper | in_person | email | other (external only)
            $table->string('channel', 24)->nullable();
            $table->timestamp('decided_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('customer_mobile', 32)->nullable();
            $table->text('note')->nullable();
            // `subject_type:subject_id` on an approval only: the content is approved once (AGR-04).
            $table->string('approval_key', 48)->nullable()->unique();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });

        // The order's agreement, versioned (AGR-01, AGR-07): one active version; earlier ones are kept unchanged.
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('version');
            // active | superseded
            $table->string('status', 16);
            // The order id while active: one active agreement per order.
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            // document | change
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('customer_decision_id')->constrained('customer_decisions')->restrictOnDelete();
            $table->json('content');
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('discount_minor');
            // Value of commission-eligible product lines (SAL-10, INT-06), incl. VAT as entered.
            $table->bigInteger('products_minor');
            $table->bigInteger('previous_total_minor')->nullable();
            $table->bigInteger('difference_minor');
            // Collected above the new value at approval time (AGR-06): to settle in finance, never refunded here.
            $table->bigInteger('settlement_needed_minor')->default(0);
            $table->timestamp('approved_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'version']);
        });

        // Agreement change after approval (M-09).
        Schema::create('agreement_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('number', 32)->unique();
            $table->foreignId('base_agreement_id')->constrained('agreements')->restrictOnDelete();
            // M-09
            $table->string('status', 24);
            // The order id while the change is open: one open change per order.
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->text('description');
            $table->json('content');
            $table->string('content_hash', 64)->nullable();
            // Action keys whose work waits for the decision; others continue.
            $table->json('affected')->nullable();
            $table->smallInteger('delay_days')->default(0);
            $table->bigInteger('difference_minor')->nullable();
            // Earlier presentations, kept when the change is presented again (M-09-T03).
            $table->json('presentations')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestamp('presented_at')->nullable();
            $table->foreignId('presented_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_agreement_id')->nullable()->constrained('agreements')->restrictOnDelete();
            $table->text('cancelled_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Discount override decisions (M-26, AGR-02): on the exact content of a quote or change.
        Schema::create('discount_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // document | change
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->string('content_hash', 64);
            // M-26
            $table->string('status', 16);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->bigInteger('base_minor');
            $table->bigInteger('discount_minor');
            $table->unsignedSmallInteger('limit_bp');
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status']);
        });

        Schema::table('order_actions', function (Blueprint $table) {
            // Set while a pending agreement change affects this work (AGR-07): it may not start or complete.
            $table->foreignId('held_by_change_id')->nullable()->after('held_by_action_id')
                ->constrained('agreement_changes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by_change_id');
        });
        Schema::dropIfExists('discount_decisions');
        Schema::dropIfExists('agreement_changes');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('customer_decisions');
        Schema::dropIfExists('agreement_documents');
        Schema::dropIfExists('discount_limits');
        Schema::dropIfExists('finance_settings');
    }
};
