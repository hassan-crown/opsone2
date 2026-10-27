<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->index();
            $table->string('name', 160);
            // Normalized E.164 (+9665XXXXXXXX for Saudi numbers); unique per facility (COM-01).
            $table->string('mobile', 20)->unique();
            // Lowercased; required only when customer-workspace login is enabled (Q-27).
            $table->string('email', 190)->nullable()->unique();
            $table->boolean('portal_login_enabled')->default(false);
            $table->string('country', 2)->default('SA');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('preferred_channel', 16)->nullable();
            // Sensitive fields: shown only to holders of P-10 over the record.
            $table->text('internal_notes')->nullable();
            $table->string('commercial_registration', 10)->nullable();
            $table->string('vat_number', 15)->nullable();
            $table->string('office_phone', 20)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'id']);
            $table->index('assignee_id');
            $table->index('created_by');
            $table->index('created_at');
        });

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('mobile', 20);
            $table->string('job_title', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_primary')->default(false);
            // Holds the customer id while this contact is the active primary, so the database itself allows
            // at most one primary per customer (COM-02).
            $table->unsignedBigInteger('primary_of')->nullable()->unique();
            // Removal stops the contact being picked for new orders but keeps history (no hard delete).
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'removed_at']);
        });

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->nullable()->unique();
            $table->string('status', 16);
            $table->string('name', 160);
            $table->string('mobile', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('need');
            $table->string('channel', 16);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 16)->nullable();
            $table->text('close_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            // One-time conversion (COM-03): set once, never changed. A customer may come from several interests.
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['branch_id', 'status']);
            $table->index('assignee_id');
            $table->index('created_by');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('customers', fn (Blueprint $table) => $table->fullText(['name', 'mobile', 'email'], 'customers_ft'));
            Schema::table('interests', fn (Blueprint $table) => $table->fullText(['name', 'need'], 'interests_ft'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('interests');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
    }
};
