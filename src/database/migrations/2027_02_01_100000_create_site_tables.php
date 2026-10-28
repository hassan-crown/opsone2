<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P8 part A (domain 07: WEB-01..04, THM-01..07, SUB-19) — ADR-0021. The facility's public site: pages with a
| per-language draft → published workflow (M-17), the single-row Alpha theme with one private draft (M-35) and the
| custom-domain record with its ownership proof. Published versions are immutable rows; only the pointer in
| `site_page_locales` moves. System pages (home / store / account) are seeded here and protected by policy.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            // '' is the home page. Content pages use a lowercase slug that never collides with a reserved path.
            $table->string('slug', 80)->unique();
            // content = add/archive/restore · editable = edit only · system = untouchable (WEB-04).
            $table->string('policy', 16);
            $table->string('system_key', 32)->nullable()->unique();
            $table->boolean('show_in_nav')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('site_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('site_pages')->restrictOnDelete();
            $table->string('locale', 2);
            $table->unsignedInteger('number');
            $table->string('title', 160)->default('');
            $table->string('meta_description', 320)->nullable();
            $table->json('sections');
            // null while it is the editable draft; once set the row is immutable.
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'locale', 'number']);
        });

        Schema::create('site_page_locales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('site_pages')->restrictOnDelete();
            $table->string('locale', 2);
            $table->foreignId('published_version_id')->nullable()->constrained('site_page_versions')->nullOnDelete();
            $table->foreignId('draft_version_id')->nullable()->constrained('site_page_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'locale']);
        });

        Schema::create('site_theme', function (Blueprint $table) {
            $table->id();
            // null published = the default Alpha theme (M-35 «منشور افتراضي»).
            $table->json('published')->nullable();
            $table->json('draft')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('draft_saved_at')->nullable();
            $table->foreignId('draft_saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->string('hostname', 253)->unique();
            // pending → verified | failed (re-checkable); removed rows are deleted.
            $table->string('status', 16)->index();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 32)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('site_lead_submissions', function (Blueprint $table) {
            $table->id();
            // The key the public form carries: a repeat of the same submission returns the same interest.
            $table->string('submission_key', 64)->unique();
            $table->foreignId('interest_id')->constrained('interests')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        $this->seedSystemPages();
    }

    private function seedSystemPages(): void
    {
        $now = now();
        $copy = [
            'ar' => ['title' => 'الرئيسية', 'sub' => 'تواصل معنا لمعرفة المزيد عن خدماتنا ومنتجاتنا.', 'cta' => 'اطلب تواصلًا', 'contact' => 'تواصل معنا', 'text' => 'اترك بياناتك وسنعود إليك.'],
            'en' => ['title' => 'Home', 'sub' => 'Get in touch to learn more about our services and products.', 'cta' => 'Request a call', 'contact' => 'Contact us', 'text' => 'Leave your details and we will get back to you.'],
        ];

        $home = DB::table('site_pages')->insertGetId([
            'slug' => '', 'policy' => 'editable', 'system_key' => 'home', 'show_in_nav' => false, 'sort' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($copy as $locale => $c) {
            $sections = [
                ['id' => 'seed-hero-'.$locale, 'type' => 'hero', 'visible' => true, 'data' => ['heading' => '', 'subheading' => $c['sub'], 'cta_label' => $c['cta'], 'cta_link' => '#contact', 'image' => null]],
                ['id' => 'seed-contact-'.$locale, 'type' => 'contact', 'visible' => true, 'data' => ['heading' => $c['contact'], 'text' => $c['text'], 'show_form' => true]],
            ];
            $versionId = DB::table('site_page_versions')->insertGetId([
                'page_id' => $home, 'locale' => $locale, 'number' => 1, 'title' => $c['title'], 'meta_description' => null,
                'sections' => json_encode($sections, JSON_UNESCAPED_UNICODE), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('site_page_locales')->insert([
                'page_id' => $home, 'locale' => $locale, 'published_version_id' => $versionId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Reserved system pages: owned by later parts of P8 (store, customer workspace); locked here.
        foreach ([['store', 'store', 1], ['account', 'account', 2]] as [$slug, $key, $sort]) {
            DB::table('site_pages')->insert([
                'slug' => $slug, 'policy' => 'system', 'system_key' => $key, 'show_in_nav' => false, 'sort' => $sort,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('site_theme')->insert(['created_at' => $now, 'updated_at' => $now]);
    }
};
