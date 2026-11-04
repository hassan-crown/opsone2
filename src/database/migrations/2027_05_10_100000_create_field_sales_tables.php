<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field sales (ADR-0041): the order's sales channel and seller, the facility's field-sales settings (a draft section of
 * the configuration release), the rep's assignments (a lead or an appointment request handed to a rep, with its
 * response time) and the rep's sales visit on a visit/measurement appointment with its photos.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // showroom | online_store | field_sales
            $table->string('sales_channel', 16)->default('showroom')->after('kind');
            // The field rep who sold the order; the commission's sale owner when present.
            $table->foreignId('seller_id')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
            $table->index(['sales_channel', 'created_at']);
            $table->index(['seller_id', 'created_at']);
        });

        Schema::create('field_sales_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Appointment kinds reps handle in the field app: visit | measurement
            $table->json('kinds');
            $table->unsignedSmallInteger('response_hours')->nullable();
            // The owner's ceiling on the discount a supervisor may give a rep, in basis points.
            $table->unsignedSmallInteger('max_discount_bp')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('field_assignments', function (Blueprint $table) {
            $table->id();
            // interest | appointment_request
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('rep_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('due_at')->nullable();
            // open | done | reassigned
            $table->string('status', 12);
            // converted | closed | scheduled (how an open assignment ended)
            $table->string('outcome', 12)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['rep_id', 'status']);
            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index(['assigned_at']);
        });

        Schema::create('sales_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('rep_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('arrived_at');
            $table->text('note')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome', 1000)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['rep_id', 'arrived_at']);
        });

        Schema::create('sales_visit_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_visit_id')->constrained('sales_visits')->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_visit_photos');
        Schema::dropIfExists('sales_visits');
        Schema::dropIfExists('field_assignments');
        Schema::dropIfExists('field_sales_settings');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'created_at']);
            $table->dropIndex(['sales_channel', 'created_at']);
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn('sales_channel');
        });
    }
};
