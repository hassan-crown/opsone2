<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer appointment requests (ADR-0039): the customer only asks (public site or workspace); staff call and book the
 * appointment themselves. One row per request, unique per submission key (idempotent replays), plus the owner's
 * settings: which kinds may be requested, which form fields are shown or required and the response time.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->nullable()->unique();
            $table->uuid('submission_key')->unique();
            // site | portal
            $table->string('source', 10);
            // new | assigned | scheduled | closed
            $table->string('status', 12);
            // measurement | installation | delivery | visit
            $table->string('kind', 16);
            $table->date('preferred_from')->nullable();
            $table->date('preferred_to')->nullable();
            // morning | afternoon | evening | any
            $table->string('time_of_day', 10)->nullable();
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->string('city', 80)->nullable();
            $table->string('address', 500)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('interest_id')->nullable()->constrained('interests')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            // Response SLA of the assignee (settings `response_hours`), fixed at assignment.
            $table->timestamp('due_at')->nullable();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained('appointments')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('close_reason', 24)->nullable();
            $table->text('close_note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['status', 'branch_id']);
            $table->index(['assignee_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('appointment_request_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('site_enabled')->default(true);
            $table->boolean('portal_enabled')->default(true);
            $table->json('kinds');
            // {field: off | optional | required}
            $table->json('fields');
            $table->unsignedSmallInteger('response_hours')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_request_settings');
        Schema::dropIfExists('appointment_requests');
    }
};
