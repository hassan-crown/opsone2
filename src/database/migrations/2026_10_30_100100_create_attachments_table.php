<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private attachments (SHR-10, ADR-0006): a polymorphic owner record, stored on a private disk, counted against
 * the license `storage_mb` limit. `uuid` is the only identifier that appears in (signed) URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->morphs('attachable');
            $table->string('purpose', 32)->nullable();
            $table->string('disk', 32);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
