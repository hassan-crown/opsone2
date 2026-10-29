<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 performance indexes (ADR-0027), additive only (expand): measured on the Q-55 dataset, each one serves a list
 * that scanned the whole table. Dropping them is safe for the code; nothing depends on their names.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Orders list filtered by status (all branches) and by branch + status, newest first.
            $table->index(['status', 'id'], 'orders_status_id_index');
            $table->index(['branch_id', 'status', 'id'], 'orders_branch_id_status_id_index');
        });

        Schema::table('money_entries', function (Blueprint $table) {
            // Open dues (allocated per due) and unallocated credit per order: index-only sums.
            $table->index(['order_due_id', 'order_id', 'amount_minor'], 'money_entries_due_order_amount_index');
        });
    }

    public function down(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            $table->dropIndex('money_entries_due_order_amount_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_branch_id_status_id_index');
            $table->dropIndex('orders_status_id_index');
        });
    }
};
