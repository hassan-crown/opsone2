<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-B: in-app notifications, the outbound message outbox, per-user preferences and facility integration settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            // Translation keys and non-sensitive parameters only; text is rendered in the viewer's locale.
            $table->json('data');
            // The record the link opens. It never carries access: opening re-checks the Gate on the record.
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'id']);
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            // Same key → same message: a repeated enqueue is ignored, so nothing is sent twice.
            $table->string('idempotency_key', 120)->unique();
            $table->string('channel', 16);
            $table->string('recipient');
            $table->string('kind', 32);
            // Encrypted; cleared as soon as the message is delivered (it can carry codes and single-use links).
            $table->text('payload')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('email')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->boolean('enabled')->default(false);
            // Encrypted at rest (secrets included); never returned to the browser.
            $table->text('config')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->boolean('test_ok')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('user_notifications');
    }
};
