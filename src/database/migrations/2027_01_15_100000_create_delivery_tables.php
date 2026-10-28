<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7 delivery (domain 06, FUL-09/FUL-10, M-14) — ADR-0018. A delivery note states the actual quantities handed over per
| order line, the receiver and the proof (private attachments). The number DN-YYYY-000123 is taken when the note is
| confirmed, so drafts and cancelled drafts never leave a hole. Quantities are thousandths (Q-43).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // M-14: draft | confirmed | cancelled
            $table->string('status', 16)->index();
            $table->date('delivered_on')->nullable();
            $table->string('receiver_name', 120)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_milli');
            $table->timestamps();

            $table->unique(['delivery_note_id', 'order_line_id']);
            $table->index('order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
    }
};
