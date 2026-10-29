<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // The expand/contract kind of every migration that ran here (ADR-0028), so an older release can tell whether
        // the schema it finds is still compatible with it.
        Schema::create('ops_migration_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('migration')->unique();
            // expand | contract | unmarked
            $table->string('kind', 12);
            $table->timestamp('recorded_at');
        });

        // Full facility data exports (Q-53 a, NFR-16): built on the queue, downloaded through a short signed link.
        Schema::create('facility_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            // queued | running | ready | failed | expired
            $table->string('status', 12)->index();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('summary')->nullable();
            $table->string('failure', 500)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_exports');
        Schema::dropIfExists('ops_migration_kinds');
    }
};
