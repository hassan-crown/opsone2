<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer support tickets (P8 part D, SHR-08, M-21). A ticket belongs to one customer and optionally to one of their
 * orders; `branch_id` is the order's branch (else the customer's) and drives the staff scope. Messages are public
 * (the customer sees them) or internal notes (never); attachments hang on the message that carried them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->string('type', 16);
            $table->string('subject', 160);
            $table->string('status', 24)->index();
            $table->text('resolution')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->timestamp('last_customer_activity_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['customer_id', 'client_key']);
            $table->index(['branch_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('visibility', 8);
            $table->string('author_kind', 8);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'visibility', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
