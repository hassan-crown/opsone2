<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-1 (ADR-0050, plan §2 axis 1): one row per «suggest the setup» request of the onboarding specialist (P-104). The
 * description and the answers are encrypted at rest; the draft itself is the linked `ai_suggestions` row. Status:
 * pending → running → ready | failed; ready → applied | rejected | superseded. Applying writes a flow draft, a draft payment
 * plan and disabled rules through the existing Actions; nothing is ever published from here.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_flow_setups', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending');
            $table->string('base_template', 40)->nullable();
            $table->longText('description');
            $table->longText('answers')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('ai_suggestion_id')->nullable()->constrained('ai_suggestions')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_flow_setups');
    }
};
