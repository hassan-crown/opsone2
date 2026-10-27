<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facility contact details (SRS-SET: «بيانات الجهة والتواصل»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('name_en');
            $table->string('contact_phone', 32)->nullable()->after('contact_email');
            $table->string('address', 500)->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('facility', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone', 'address']);
        });
    }
};
