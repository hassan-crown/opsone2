<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp channel (ADR-0030): the customer's opt-out and the per-customer message log (status only for most staff).
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Set when the customer asked to stop WhatsApp messages (by staff on their behalf, or by replying STOP).
            $table->timestamp('whatsapp_opt_out_at')->nullable();
            $table->string('whatsapp_opt_out_source', 16)->nullable();
        });

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            // Same key → same message, whichever channel carries it.
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            $table->string('channel', 16);
            $table->boolean('fell_back')->default(false);
            $table->string('template', 64)->nullable();
            $table->string('locale', 2);
            $table->string('status', 16)->default('queued');
            $table->string('provider_message_id', 128)->nullable()->unique();
            // Provider error code or short reason only; never provider text.
            $table->string('error', 64)->nullable();
            // The rendered text, encrypted: shown only to staff who may see the customer's sensitive data.
            $table->text('body')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_opt_out_at', 'whatsapp_opt_out_source']);
        });
    }
};
