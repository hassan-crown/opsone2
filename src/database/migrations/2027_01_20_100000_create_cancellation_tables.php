<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7 cancellation (domain 02/06, SRS-COM-14, WF-13, FUL-12, FIN-14, INT-08; M-34, M-04-T04..T06, M-05-T09) — ADR-0020.
| A cancellation request is decided once; accepting it applies the reviewed impact atomically and records what was
| applied. The settlement follow-up is one per order (INT-08) and lists the remaining actions from their sources until
| every one is done; returns (P-53) add their items to the same follow-up.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            // CN-YYYY-000123, gapless per year, taken when the request is recorded.
            $table->string('number', 24)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // full | partial
            $table->string('scope', 12);
            // M-34: pending | accepted | rejected | withdrawn
            $table->string('status', 16)->index();
            // customer_account | external
            $table->string('source', 24);
            // phone | message | paper | in_person | email | other (external only)
            $table->string('channel', 24)->nullable();
            $table->date('requested_on');
            $table->text('reason');
            // Requested lines: [{ref, quantity_milli}] (partial only).
            $table->json('lines')->nullable();
            // Full cancellation: value of done but undelivered work the facility keeps (as entered, halalas).
            $table->bigInteger('retained_minor')->nullable();
            // The order state before the request suspended it (M-04-T06 returns to it).
            $table->string('order_status_before', 32);
            // What was applied (the reviewed impact, unmasked) and its fingerprint.
            $table->json('impact')->nullable();
            $table->string('impact_hash', 64)->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // order_id while pending: one active request per order (M-34-T01).
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('order_cancellation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_cancellation_id')->constrained('order_cancellations')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            // Index of the line in the agreement content it was cut from (custom order with an agreement).
            $table->unsignedSmallInteger('agreement_line')->nullable();
            $table->string('description', 500);
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('value_minor')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'order_line_id']);
        });

        Schema::create('settlement_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            // open | closed
            $table->string('status', 12)->index();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_followup_id')->constrained('settlement_followups')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // refund | stock_release | supplier | commission | return
            $table->string('kind', 24);
            // Who opened it: cancellation:{id}, return:{id}, …
            $table->string('source', 48);
            // The record it follows (purchase order, reservation, …), when there is one.
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            // `source:kind:ref` — an item is opened once per source.
            $table->string('item_key', 120)->unique();
            $table->json('details')->nullable();
            $table->bigInteger('target_minor')->nullable();
            // open | done | dropped
            $table->string('status', 12)->index();
            // auto (derived from its source) | manual (resolved by a person with the original action's permission)
            $table->string('resolution', 12)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlement_followups');
        Schema::dropIfExists('order_cancellation_lines');
        Schema::dropIfExists('order_cancellations');
    }
};
