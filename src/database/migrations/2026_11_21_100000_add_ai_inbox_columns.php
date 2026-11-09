<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-4 (ADR-0050, plan §2 axis 2): the classification of each inbound message (closed intent list, set once by the
 * queued IntentClassifier) and the suspicion flag (an attempt to steer the model, or abuse). A flagged conversation
 * shows a warning to staff and is never answered automatically (AI-6). An outbound reply keeps the AI suggestion it
 * came from, for tracing (`source: ai_suggestion`). Additive only.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('ai_intent', 30)->nullable();
            $table->boolean('ai_suspicious')->default(false);
            $table->foreignId('ai_suggestion_id')->nullable()->constrained('ai_suggestions')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('ai_flagged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('ai_flagged_at');
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_suggestion_id');
            $table->dropColumn(['ai_intent', 'ai_suspicious']);
        });
    }
};
