<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // M-04-T07 / M-05-T10: one row per comprehensive closure of an order, with the reason and the state it left.
        Schema::create('order_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->text('reason');
            $table->unsignedInteger('agreement_version')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->index('order_id');
        });

        // M-14-T04: a blocking remark on a confirmed delivery. It does not repeat the delivery or reopen the order; it
        // keeps the order from closing until it is resolved (M-04-T07 / M-05-T10).
        Schema::create('delivery_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->text('description');
            // open | resolved
            $table->string('status', 16)->default('open');
            $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_remarks');
        Schema::dropIfExists('order_closures');
    }
};
