<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-2 (ADR-0050, plan axis 7): questions the help assistant could not answer, for the content team. The text is
 * already redacted; nothing identifies who asked.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('help_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question', 300);
            $table->string('question_hash', 40)->unique();
            $table->string('reason', 20);
            $table->unsignedInteger('times')->default(0);
            $table->timestamp('last_asked_at');
            $table->timestamps();

            $table->index('last_asked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_unanswered_questions');
    }
};
