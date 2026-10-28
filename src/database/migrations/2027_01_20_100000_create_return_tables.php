<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| P7 returns (domain 06, FUL-11, INT-02/03/05, M-15) — ADR-0019. A return asks to bring back quantities actually
| delivered on a ready order. Acceptance (P-102) reserves the return eligibility of the accepted quantity; each physical
| receipt (P-53) is a part that is inspected once (good back to a location / damaged in custody / rejected). The money
| side is a separate settlement: an expected amount fixed at acceptance and at most one live M-12 refund request.
| Quantities are thousandths (Q-43), amounts halalas. The number RT-YYYY-000123 is taken at the request.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_settings', function (Blueprint $table) {
            $table->id();
            // Indicator only (SRS «مدة طلب إرجاع الجاهز»): never blocks a request or a decision.
            $table->unsignedSmallInteger('window_days')->default(14);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            // M-15: requested | accepted | rejected | received | inspected | cancelled
            $table->string('status', 16)->index();
            $table->text('reason');
            $table->string('source', 16)->default('staff');
            $table->unsignedSmallInteger('window_days');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->unsignedBigInteger('settlement_minor')->nullable();
            $table->foreignId('refund_id')->nullable()->unique()->constrained('refunds')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('order_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->unsignedBigInteger('requested_milli');
            // Reserved eligibility: what was accepted and not released by a withdrawal (received ⊆ accepted).
            $table->unsignedBigInteger('accepted_milli')->default(0);
            $table->unsignedBigInteger('received_milli')->default(0);
            $table->unsignedBigInteger('unit_value_minor')->nullable();
            $table->timestamps();

            $table->unique(['order_return_id', 'order_line_id']);
            $table->index('order_line_id');
        });

        Schema::create('order_return_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignId('order_return_line_id')->constrained('order_return_lines')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_milli');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            // Inspection outcome, set once (M-15-T02); corrections (T04) move quantity between good and damaged.
            $table->unsignedBigInteger('good_milli')->nullable();
            $table->unsignedBigInteger('damaged_milli')->nullable();
            $table->unsignedBigInteger('rejected_milli')->nullable();
            $table->text('inspection_note')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();

            $table->index(['order_return_id', 'inspected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_parts');
        Schema::dropIfExists('order_return_lines');
        Schema::dropIfExists('order_returns');
        Schema::dropIfExists('return_settings');
    }
};
