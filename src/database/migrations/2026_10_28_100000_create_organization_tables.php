<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization structure of the single facility served by this deployment (ADR-0002: no tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('branch_department', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'department_id']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->string('job_title')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_membership', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'membership_id']);
        });

        // Single-row facility profile (M-02 setup status lives here).
        Schema::create('facility', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('default_locale', 5)->default('ar');
            $table->string('currency', 3)->default('SAR');
            $table->timestamp('currency_locked_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->foreignId('owner_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->string('setup_status', 16)->default('incomplete');
            $table->json('setup_steps')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Bumped whenever the user's access changes; sessions holding an older stamp are revoked.
            $table->unsignedInteger('access_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_version');
        });
        Schema::dropIfExists('facility');
        Schema::dropIfExists('branch_membership');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('branch_department');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('regions');
    }
};
