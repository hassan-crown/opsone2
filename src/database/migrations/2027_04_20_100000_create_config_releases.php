<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No-Code phase 2 (ADR-0040): configuration releases. The business-rules tables of phase 1 become the draft; a
 * release is an immutable, numbered snapshot of them that the application reads. An order records the release that
 * was current when it was created, so later releases never change how it is priced or what its documents say.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('config_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            // The published flow version at that moment (reference only: the flow keeps its own versions, M-23).
            $table->unsignedBigInteger('flow_version_id')->nullable();
            $table->string('notes', 1000)->nullable();
            // A rollback publishes a copy of an older release; the older one is never changed or deleted.
            $table->foreignId('restored_from_id')->nullable()->constrained('config_releases')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('config_release_id')->nullable()->constrained('config_releases')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('config_release_id');
        });
        Schema::dropIfExists('config_releases');
    }
};
