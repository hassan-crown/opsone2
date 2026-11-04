<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-Code phase 3 (ADR-0042): the automation rules engine «عند حدوث … وإذا … فافعل …».
 * - `automation_rules` is the draft (published as the `automation` section of a configuration release, ADR-0040);
 *   the kill switch (`killed_at`) is live and outside releases, so a misbehaving rule stops at once everywhere.
 * - `automation_events` are the domain events normalized from `state_transitions`, `audit_events` and the timer scan,
 *   written in the transaction of the change (unique `dedupe_key`), then processed on the queue.
 * - `automation_runs` is the per-rule execution log (one row per event and rule), `automation_actions` the per-action
 *   idempotency keys (one row per rule, event and action index).
 * - `order_notes` holds the notes an action adds to an order.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->json('name');
            $table->string('trigger', 48);
            $table->json('trigger_params')->nullable();
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('killed_at')->nullable();
            $table->foreignId('killed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kill_reason', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('automation_events', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 48);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            // transition | audit | timer, and the source row (a timer has none).
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->json('context')->nullable();
            // Loop protection: the rules whose actions led to this event, and how deep the chain is.
            $table->json('caused_by')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->index(['trigger', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('processed_at');
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_event_id')->constrained('automation_events')->cascadeOnDelete();
            $table->string('rule_key', 40);
            $table->foreignId('config_release_id')->nullable()->constrained('config_releases')->nullOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            // matched | skipped | failed
            $table->string('outcome', 16);
            $table->string('reason', 48)->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->unique(['automation_event_id', 'rule_key']);
            $table->index(['rule_key', 'created_at']);
            $table->index(['rule_key', 'subject_type', 'subject_id', 'outcome']);
        });

        Schema::create('automation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('type', 24);
            // done | skipped | failed
            $table->string('status', 16);
            $table->json('result')->nullable();
            $table->timestamps();
        });

        // The triggers a release's rules listen to, so recording an event is a cheap lookup (ADR-0042).
        Schema::table('config_releases', function (Blueprint $table) {
            $table->json('automation_triggers')->nullable();
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->text('body');
            $table->string('source', 16);
            $table->string('rule_key', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
        Schema::table('config_releases', function (Blueprint $table) {
            $table->dropColumn('automation_triggers');
        });
        Schema::dropIfExists('automation_actions');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_events');
        Schema::dropIfExists('automation_rules');
    }
};
