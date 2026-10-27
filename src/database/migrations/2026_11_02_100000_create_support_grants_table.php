<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpsOne support delegation (SHR-14, M-38 proposed in Q-25). A support identity is a user flagged `is_support`
 * with no staff membership; it may act only through an active, time-boxed, scoped grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_support')->default(false)->index();
        });

        Schema::create('support_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('support_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('support_request_id')->nullable()->constrained('support_requests')->nullOnDelete();
            $table->string('ticket', 40)->nullable();
            $table->string('reason', 500);
            $table->json('requested_areas');
            $table->unsignedSmallInteger('requested_hours');
            $table->json('areas')->nullable();
            $table->unsignedSmallInteger('hours')->nullable();
            $table->string('status', 16)->default('requested')->index();
            $table->timestamp('request_expires_at');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['support_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_grants');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_support']);
            $table->dropColumn('is_support');
        });
    }
};
