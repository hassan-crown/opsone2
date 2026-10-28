<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog (domain 02, COM-04..06, Q-28): bilingual text is stored as JSON `{ar,en}`; money is an integer in minor
 * units (halalas). Rows are never deleted — a disabled row stays for past usage. `catalog_versions` is an
 * append-only history: later order lines snapshot one version (id) and it never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('category', 80)->nullable()->index();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('pricing_basis', 24);
            $table->unsignedBigInteger('reference_price_minor')->nullable();
            $table->json('option_groups');
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_group', 80)->nullable()->index();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('unit', 24);
            $table->string('availability', 16)->default('available');
            $table->string('supplier_name', 120)->nullable();
            $table->boolean('track_quantity')->default(false);
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_ready_products', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_ready_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ready_product_id')->constrained('catalog_ready_products');
            $table->string('code', 40)->unique();
            $table->json('name')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->morphs('versionable');
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_versions');
        Schema::dropIfExists('catalog_ready_variants');
        Schema::dropIfExists('catalog_ready_products');
        Schema::dropIfExists('catalog_materials');
        Schema::dropIfExists('catalog_products');
    }
};
