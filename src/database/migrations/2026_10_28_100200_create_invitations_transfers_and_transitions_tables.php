<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M-01 staff invitation. Only the SHA-256 of the link token is stored.
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('job_title')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('branch_ids')->nullable();
            $table->json('roles');
            $table->foreignId('invited_by_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->constrained('invitations')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index('email');
        });

        // M-37 ownership transfer (Q-18).
        Schema::create('ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->foreignId('to_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->string('status', 16)->default('requested');
            $table->string('reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        // Generic append-only transition log for every state machine.
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('machine', 8);
            $table->string('transition', 16);
            $table->morphs('subject');
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 32);
            $table->string('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['machine', 'transition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_transitions');
        Schema::dropIfExists('ownership_transfers');
        Schema::dropIfExists('invitations');
    }
};
