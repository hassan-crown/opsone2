<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-6 (ADR-0050, plan §2 axis 4, Q-92): one row per inbound message the automatic reply looked at — what it decided
 * (sent, handed off to staff, skipped), why, the intent and confidence the classifier gave, and the reply it queued.
 * It is the daily cap's counter, the staff hand-off note and the trace of every automatic reply. No message text is
 * stored here: the reply itself is the conversation message it points to (encrypted there).
 *
 * `conversations.is_group`: set when Meta delivers a message from a WhatsApp group; never answered automatically
 * (a financial fact must not reach a group). Additive only.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_auto_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // One decision per inbound message: a re-run of the job changes nothing.
            $table->foreignId('inbound_message_id')->unique()->constrained('conversation_messages')->cascadeOnDelete();
            $table->string('outcome', 16); // sent | handoff | skipped
            $table->string('reason', 40)->nullable();
            $table->string('intent', 30)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('reply_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'outcome', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_group')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('is_group');
        });

        Schema::dropIfExists('ai_auto_replies');
    }
};
