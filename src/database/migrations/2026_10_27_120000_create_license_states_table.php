<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single row: the last trusted license and the monotonic clock (ADR-0004).
        Schema::create('license_states', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->mediumText('envelope')->nullable();
            $table->string('status', 32);
            $table->string('problem', 32)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_seen_at');
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_states');
    }
};
