<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Product-owner decision (2026-10-28, ADR-0014): whether a cash collection needs a proof file is a facility setting
| the owner controls. Default: required (the M-11-T01 behaviour so far).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->boolean('cash_proof_required')->default(true)->after('contracts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->dropColumn('cash_proof_required');
        });
    }
};
