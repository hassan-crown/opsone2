<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp inbox (AI-3, amends ADR-0030): one conversation per WhatsApp number of the facility's own Meta account
 * (Q-72), and its inbound and outbound messages. Message text is encrypted at rest like `customer_messages.body`.
 * `provider_message_id` is unique, so a replayed webhook never stores the same inbound message twice; an outbound
 * reply carries the client's idempotency key, so a double submit never sends twice.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // The contact's WhatsApp number in E.164 (+9665…): one conversation per number.
            $table->string('wa_id', 20)->unique();
            // The profile name WhatsApp reports for an unknown contact; the customer's name wins once linked.
            $table->string('contact_name', 150)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // Follows the linked customer's branch; for an unknown contact the facility's default inbox branch (or none).
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // Start of Meta's 24-hour customer-service window: the last message the contact sent.
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'last_message_at']);
            $table->index(['assignee_id', 'last_message_at']);
            $table->index('last_message_at');
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            // text | image | audio | video | document | sticker | template | unsupported
            $table->string('type', 16);
            $table->string('provider_message_id', 191)->nullable()->unique();
            // Outbound only: the composer's one-time key (a double submit is a no-op).
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->text('body')->nullable(); // encrypted
            $table->string('template', 250)->nullable();
            $table->string('template_language', 8)->nullable();
            // in: received; out: queued → sent → delivered → read, or failed
            $table->string('status', 16);
            // Provider / Meta error code only, never provider text (it can echo a number or token).
            $table->string('error', 64)->nullable();
            // Inbound media: Meta's media id, the declared type and what became of the download.
            $table->string('media_provider_id', 191)->nullable();
            $table->string('media_mime', 100)->nullable();
            $table->string('media_status', 16)->nullable(); // pending | stored | rejected | failed
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
