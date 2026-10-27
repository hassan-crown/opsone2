<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped permission grants (Q-19): editable role templates plus per-member overrides.
 * Permission codes are the matrix identifiers (P-01..P-102), catalogued in App\Access\PermissionCatalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->nullable()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->timestamps();
        });

        Schema::create('role_template_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_template_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8);
            $table->string('scope', 16);
            $table->timestamps();
            $table->unique(['role_template_id', 'permission']);
        });

        // A member holds one or more role templates; the scope breadth is chosen per member.
        Schema::create('membership_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_template_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16)->nullable();
            $table->json('targets')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'role_template_id']);
        });

        Schema::create('member_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8);
            $table->string('effect', 8)->default('allow');
            $table->string('scope', 16)->nullable();
            $table->json('targets')->nullable();
            $table->foreignId('granted_by_membership_id')->nullable()->constrained('memberships')->nullOnDelete();
            $table->timestamps();
            $table->unique(['membership_id', 'permission', 'effect', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_grants');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_template_grants');
        Schema::dropIfExists('role_templates');
    }
};
