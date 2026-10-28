<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // A requested report export (SHR-12, NFR-16): generated on the queue into a private file, kept briefly.
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report', 24);
            // from, to, branch_ids, locale: the request, re-evaluated against the user's live grants when it runs.
            $table->json('params');
            // queued | ready | failed
            $table->string('status', 12)->default('queued');
            $table->string('disk', 40)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('filename', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('error', 200)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
