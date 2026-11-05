<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant marketing readiness, batch 2 (ADR-0045):
 * - ready products get a readable slug and an optional search title / description per language ({ar,en} JSON, like
 *   the name). The id stays in the URL, so a slug change never breaks a link (the old one answers 301).
 * - `site_redirects`: 301 from a site page's old path when its slug changes (written by SitePageWriter), so shared
 *   links and search results keep working.
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_ready_products', function (Blueprint $table) {
            $table->json('slug')->nullable()->after('description');
            $table->json('seo_title')->nullable()->after('slug');
            $table->json('seo_description')->nullable()->after('seo_title');
        });

        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            // `/ar/old-slug` — a path only, never a host or a query.
            $table->string('from_path', 255)->unique();
            $table->string('to_path', 255);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();

            $table->index('to_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_redirects');
        Schema::table('catalog_ready_products', function (Blueprint $table) {
            $table->dropColumn(['slug', 'seo_title', 'seo_description']);
        });
    }
};
