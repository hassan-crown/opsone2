<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // ADR-0036: what happened on site for one installation appointment (arrival, finish, acceptance signature).
        // Created at «وصلت»; an appointment without a row has not started.
        Schema::create('installation_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // in_progress | finished | finished_with_remarks
            $table->string('status', 24);
            $table->timestamp('arrived_at');
            $table->foreignId('arrived_by')->nullable()->constrained('users')->nullOnDelete();
            // One reading at arrival, only with the device's consent; never tracked afterwards.
            $table->decimal('arrival_latitude', 10, 7)->nullable();
            $table->decimal('arrival_longitude', 10, 7)->nullable();
            $table->unsignedInteger('arrival_accuracy_m')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('finish_note')->nullable();
            // Customer acceptance: drawn on the technician's phone (`device`) or in the customer's workspace (`portal`).
            $table->string('signature_method', 16)->nullable();
            $table->string('signer_name', 120)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signature_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->char('signature_sha256', 64)->nullable();
            $table->string('signature_ip', 45)->nullable();
            $table->string('signature_user_agent', 255)->nullable();
            $table->timestamp('signature_requested_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('finished_at');
        });

        // Before/after photos: private attachments owned by the visit. `client_uuid` makes a retried upload a no-op.
        Schema::create('installation_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_visit_id')->constrained('installation_visits')->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->restrictOnDelete();
            // before | after
            $table->string('phase', 8);
            $table->uuid('client_uuid')->unique();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['installation_visit_id', 'phase']);
        });

        // A remark from site, on the delivery remarks' model (ADR-0032/0035): it waits with a follow-up person and keeps the
        // order from closing until it is resolved on its own.
        Schema::create('installation_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_visit_id')->constrained('installation_visits')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // missing_part | damage | extra_work | other
            $table->string('kind', 16);
            $table->text('description');
            // open | resolved
            $table->string('status', 16);
            $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('installation_visit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_remarks');
        Schema::dropIfExists('installation_photos');
        Schema::dropIfExists('installation_visits');
    }
};
