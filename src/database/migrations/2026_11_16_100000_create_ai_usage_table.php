<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-0 (ADR-0050 §6, plan §3.4): one row per AI gateway call — task, model, tokens, cost in micro-dollars, credits
 * charged, user, subject, outcome and duration. **Never the prompt or the answer.** A `pending` row reserves the
 * task's credits while the call runs, so concurrent calls cannot spend past the monthly limit.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('task', 40);
            $table->string('model', 80);
            $table->string('driver', 20);
            $table->string('outcome', 20)->default('pending');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'outcome']);
            $table->index(['task', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
