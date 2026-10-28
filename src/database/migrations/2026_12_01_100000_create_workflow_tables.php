<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facility work settings (Q-31 a): one row; working days/hours, appointment slot, reminder send hours and the
        // «conflicting appointments» policy (SHR-03). Durations elsewhere are calendar days unless stated.
        Schema::create('work_settings', function (Blueprint $table) {
            $table->id();
            // ISO weekdays 1 (Mon) .. 7 (Sun).
            $table->json('working_days');
            $table->string('work_starts_at', 5);
            $table->string('work_ends_at', 5);
            $table->unsignedSmallInteger('appointment_slot_minutes');
            $table->string('reminder_from', 5);
            $table->string('reminder_until', 5);
            // allow_with_reason | block
            $table->string('conflicting_appointments', 24);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The facility's single custom flow (M-23). `draft` holds an unpublished edit; `published_version_id` is what
        // new orders pin.
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            // Exactly one flow per facility (WF-01): a second insert fails on this key.
            $table->unsignedTinyInteger('singleton')->default(1)->unique();
            $table->string('status', 24);
            $table->foreignId('published_version_id')->nullable();
            $table->string('template_key', 40);
            $table->json('draft')->nullable();
            $table->foreignId('draft_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_updated_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        // Immutable published definitions; every order pins one (WF-10).
        Schema::create('flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('template_key', 40);
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['flow_id', 'number']);
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('flow_versions')->restrictOnDelete();
        });

        // One row per custom order: the pinned version and the single active main stage (WF-06).
        Schema::create('order_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('flow_version_id')->constrained('flow_versions')->restrictOnDelete();
            $table->foreignId('active_stage_id')->nullable();
            $table->text('pause_reason')->nullable();
            $table->foreignId('pause_follow_up_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('pause_review_on')->nullable();
            $table->string('paused_from', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('order_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('key', 40);
            $table->json('name');
            $table->json('public_name')->nullable();
            $table->boolean('show_to_customer')->default(true);
            // order_assignee | member | department
            $table->string('follow_up_type', 16);
            $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('follow_up_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->unsignedSmallInteger('target_days')->nullable();
            // M-06
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['order_id', 'position']);
            $table->unique(['order_id', 'key']);
            $table->index(['status', 'follow_up_department_id']);
        });

        Schema::table('order_workflows', function (Blueprint $table) {
            $table->foreign('active_stage_id')->references('id')->on('order_stages')->nullOnDelete();
        });

        Schema::create('order_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_stage_id')->constrained('order_stages')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('key', 40);
            $table->string('type', 32);
            $table->json('name');
            // required | optional
            $table->string('requirement', 16);
            // always | needs_measurement | needs_design
            $table->string('applies_when', 24);
            $table->json('inputs')->nullable();
            $table->json('depends_on')->nullable();
            // order_assignee | member | department
            $table->string('assign_type', 16);
            $table->unsignedBigInteger('assign_id')->nullable();
            // M-27
            $table->string('status', 24);
            // Set while an action it depends on is being reworked: its completion waits (WF-11).
            $table->foreignId('held_by_action_id')->nullable();
            $table->json('source_reference')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('allowed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('allowed_reason')->nullable();
            $table->unsignedSmallInteger('rework_count')->default(0);
            $table->timestamps();

            $table->unique(['order_id', 'key']);
            $table->index(['order_stage_id', 'position']);
            $table->index(['type', 'status']);
        });

        // M-07. `branch` is the order's (read through the order, so a branch transfer moves its tasks too).
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_stage_id')->constrained('order_stages')->restrictOnDelete();
            $table->foreignId('order_action_id')->nullable()->constrained('order_actions')->restrictOnDelete();
            // action | correction
            $table->string('kind', 16);
            $table->foreignId('corrects_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('title');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24);
            $table->timestamp('due_at')->nullable();
            $table->text('waiting_need')->nullable();
            $table->date('waiting_follow_up_on')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['assignee_id', 'status']);
            $table->index(['department_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index(['follow_up_user_id', 'status']);
        });

        // COM-07 measurements: estimated or final, by line or a temporary position before products are fixed.
        Schema::create('order_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('location', 120);
            $table->string('name', 120);
            $table->decimal('value', 12, 3);
            $table->string('unit', 8);
            // estimated | final
            $table->string('kind', 16);
            $table->date('measured_on');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'kind']);
        });

        // M-16. Times are stored in UTC and shown in the facility timezone.
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            // measurement | installation | delivery | visit
            $table->string('kind', 16);
            $table->string('status', 24);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('address');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // phone | in_person | message | other
            $table->string('confirmation_method', 16)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            // Kept with the confirmation when a conflict was accepted (SHR-03); `conflicted` is the derived marker.
            $table->text('conflict_reason')->nullable();
            $table->boolean('conflicted')->default(false);
            // A pending change keeps the original until it is approved (M-16-T03/T04).
            $table->json('pending_change')->nullable();
            $table->text('outcome')->nullable();
            $table->boolean('no_show')->default(false);
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'starts_at']);
        });

        // Actual members booked (a team is its members; SHR-03 checks every member, not the team name).
        Schema::create('appointment_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unique(['appointment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_members');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('order_measurements');
        Schema::dropIfExists('tasks');
        Schema::table('order_workflows', fn (Blueprint $table) => $table->dropForeign(['active_stage_id']));
        Schema::dropIfExists('order_actions');
        Schema::dropIfExists('order_stages');
        Schema::dropIfExists('order_workflows');
        Schema::table('flows', fn (Blueprint $table) => $table->dropForeign(['published_version_id']));
        Schema::dropIfExists('flow_versions');
        Schema::dropIfExists('flows');
        Schema::dropIfExists('work_settings');
    }
};
