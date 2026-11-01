<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner dashboard indexes (ADR-0031), additive only (expand): each serves one bounded dashboard query that would
 * otherwise scan its table. Nothing in the code depends on the index names.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            // "First approval" per order (MIN(approved_at) grouped by order) and the monthly sales trend.
            $table->index(['order_id', 'approved_at'], 'agreements_order_approved_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Executors leaderboard: tasks completed in a range per person.
            $table->index(['completed_by', 'completed_at'], 'tasks_completed_by_at_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Installations scheduled in the next days.
            $table->index(['kind', 'starts_at'], 'appointments_kind_starts_index');
        });

        Schema::table('interests', function (Blueprint $table) {
            // Lead conversion: interests created in a range, per branch.
            $table->index(['branch_id', 'created_at'], 'interests_branch_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('interests', fn (Blueprint $t) => $t->dropIndex('interests_branch_created_index'));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropIndex('appointments_kind_starts_index'));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropIndex('tasks_completed_by_at_index'));
        Schema::table('agreements', fn (Blueprint $t) => $t->dropIndex('agreements_order_approved_index'));
    }
};
