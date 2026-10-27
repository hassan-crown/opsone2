<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's first-login invitation, issued by provisioning (not by a member). Same lifetime as M-01.
        Schema::create('owner_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_sent_at')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        // Ownership-proof code (Q-17, default channel: email) for staff invitations.
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable();
            $table->timestamp('code_sent_at')->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->unsignedTinyInteger('code_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'code_sent_at', 'code_expires_at', 'code_attempts']);
        });
        Schema::dropIfExists('owner_invitations');
    }
};
