<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-Code phase 1a (ADR-0037): owner-managed payment plans copied into the order's dues at the agreement, the
 * collection reminder policy, the contract/quote clause library with immutable versions, editable message templates,
 * task SLA settings (holidays, days per action type) and document numbering formats.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // A facility payment plan: ordered lines (basis, value, due trigger, offset, grace). Orders copy the lines at the
        // agreement, so editing a plan never changes an existing order.
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->json('name');
            $table->json('lines');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('order_finances', function (Blueprint $table) {
            // The plan the order chose (before the agreement) or that its schedule was copied from.
            $table->foreignId('payment_plan_id')->nullable()->after('agreement_id')->constrained('payment_plans')->nullOnDelete();
            $table->string('payment_plan_key', 30)->nullable()->after('payment_plan_id');
        });

        Schema::table('order_dues', function (Blueprint $table) {
            $table->string('stage_key', 40)->nullable()->after('due_event');
            $table->unsignedSmallInteger('offset_days')->default(0)->after('stage_key');
            $table->unsignedSmallInteger('grace_days')->default(0)->after('offset_days');
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            // The confirmed note that completed the order's delivery (trigger `delivery.completed`).
            $table->boolean('completes_order')->default(false)->after('status');
        });

        // Collection reminders and the appointment reminder lead time. One row; defaults apply before it is saved.
        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->json('before_days');
            $table->boolean('on_due')->default(true);
            $table->unsignedSmallInteger('after_every_days')->default(7);
            $table->unsignedSmallInteger('max_after')->default(4);
            $table->unsignedSmallInteger('escalate_after_days')->nullable();
            $table->string('escalate_to', 32)->nullable();
            $table->unsignedSmallInteger('appointment_hours')->default(24);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Contract and quote clauses. The clause row holds the current text; every save adds an immutable version,
        // and a prepared document stores the version it copied.
        Schema::create('document_clauses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->json('title');
            $table->json('body');
            $table->json('applies_to');
            $table->boolean('included_by_default')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_clause_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_clause_id')->constrained('document_clauses')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('title');
            $table->json('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->unique(['document_clause_id', 'version']);
        });

        // Facility wording for an event's message on one channel and language; absent = the built-in text.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);
            $table->string('channel', 12);
            $table->string('locale', 2);
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event', 'channel', 'locale']);
        });

        Schema::table('work_settings', function (Blueprint $table) {
            $table->json('holidays')->nullable();
            // {"default": 3, "by_type": {"record_measurements": 2, ...}} in working days
            $table->json('task_sla')->nullable();
            $table->unsignedSmallInteger('task_escalate_after_days')->default(1);
        });

        // Document number formats per sequence (closed list). E-invoice sequences are never configurable.
        Schema::create('number_formats', function (Blueprint $table) {
            $table->id();
            $table->string('sequence', 40)->unique();
            $table->string('prefix', 12);
            $table->string('year_mode', 12)->default('gregorian');
            $table->boolean('per_branch')->default(false);
            $table->unsignedTinyInteger('pad')->default(5);
            // A starting number (e.g. after migrating from an older system) for the period it was set in only.
            $table->unsignedBigInteger('start_at')->nullable();
            $table->unsignedInteger('start_period')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_formats');
        Schema::table('work_settings', function (Blueprint $table) {
            $table->dropColumn(['holidays', 'task_sla', 'task_escalate_after_days']);
        });
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('document_clause_versions');
        Schema::dropIfExists('document_clauses');
        Schema::dropIfExists('reminder_settings');
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropColumn('completes_order');
        });
        Schema::table('order_dues', function (Blueprint $table) {
            $table->dropColumn(['stage_key', 'offset_days', 'grace_days']);
        });
        Schema::table('order_finances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_plan_id');
            $table->dropColumn('payment_plan_key');
        });
        Schema::dropIfExists('payment_plans');
    }
};
