<?php

use App\Operations\Upgrade\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0037: the payment-plan triggers (`contract.approved`, `delivery.completed`, …) are longer than 16 characters.
 * Widening keeps every existing value valid, but it is a `change()`, which ADR-0028 only allows in a contract
 * migration — so it runs in the maintenance window like one.
 */
return new #[Contract] class extends Migration
{
    public function up(): void
    {
        Schema::table('order_dues', function (Blueprint $table) {
            // agreement | contract.approved | design.approved | stage.started | delivery | delivery.completed | payment_stage | date
            $table->string('due_event', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_dues', function (Blueprint $table) {
            $table->string('due_event', 16)->change();
        });
    }
};
