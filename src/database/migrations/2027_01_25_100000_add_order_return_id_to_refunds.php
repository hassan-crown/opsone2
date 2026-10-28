<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 merge: a refund asked by a return (M-15 → M-12) keeps its return for good, so a refund the return replaced after a
 * failure can never be retried (no double refund) and the cancellation money never counts it. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->foreignId('order_return_id')->nullable()->after('order_id')->constrained('order_returns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_return_id');
        });
    }
};
