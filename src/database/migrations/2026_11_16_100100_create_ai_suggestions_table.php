<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-0 (ADR-0050 §1): every AI output is a draft. `payload` (the proposal) and `final_payload` (what the staff member
 * actually sent or applied, when edited) are encrypted at rest — they may hold customer data. Status:
 * proposed → accepted | edited | rejected | expired. Execution never happens here: the staff member runs the
 * existing Action, which keeps permissions, audit and releases in the path.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('task', 40);
            $table->foreignId('ai_usage_id')->nullable()->constrained('ai_usage')->nullOnDelete();
            $table->string('status', 20)->default('proposed');
            $table->longText('payload');
            $table->longText('final_payload')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['task', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
