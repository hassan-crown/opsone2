<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant marketing readiness, batch 3 (ADR-0045):
 * - `marketing_settings` (one row): tag IDs only (GTM, GA4, Meta, TikTok, Snap, Google Ads) — never free code —, the
 *   WhatsApp button and the facility's social / Google Business links. Edited with P-107, audited.
 * - `store_checkouts.purchase_tracked_at`: set once, atomically, the first time the paid checkout is shown, so the
 *   `purchase` event is sent once whatever the reloads.
 * - Privacy policy and terms pages (Q-80): guidance templates seeded as **drafts** — nothing is public until the
 *   facility reviews and publishes them. Editable, never archived (like the home page).
 */
return new #[Expand] class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gtm_id', 20)->nullable();
            $table->string('ga4_id', 20)->nullable();
            $table->string('meta_pixel_id', 20)->nullable();
            $table->string('tiktok_pixel_id', 30)->nullable();
            $table->string('snap_pixel_id', 40)->nullable();
            $table->string('google_ads_id', 20)->nullable();
            $table->string('google_ads_purchase_label', 40)->nullable();
            $table->string('google_ads_lead_label', 40)->nullable();
            // Digits of an international number (9665XXXXXXXX), and a greeting per language.
            $table->string('whatsapp_number', 20)->nullable();
            $table->json('whatsapp_message')->nullable();
            // {instagram, x, tiktok, snapchat, youtube, linkedin, facebook, google_business, google_review}: https URLs.
            $table->json('links')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('store_checkouts', function (Blueprint $table) {
            $table->timestamp('purchase_tracked_at')->nullable()->after('paid_at');
        });

        $this->seedPolicyPages();
    }

    public function down(): void
    {
        $ids = DB::table('site_pages')->whereIn('system_key', ['privacy', 'terms'])->pluck('id');
        DB::table('site_page_locales')->whereIn('page_id', $ids)->delete();
        DB::table('site_page_versions')->whereIn('page_id', $ids)->delete();
        DB::table('site_pages')->whereIn('id', $ids)->delete();
        Schema::table('store_checkouts', function (Blueprint $table) {
            $table->dropColumn('purchase_tracked_at');
        });
        Schema::dropIfExists('marketing_settings');
    }

    private function seedPolicyPages(): void
    {
        $now = now();
        $sort = (int) DB::table('site_pages')->max('sort');
        foreach (self::TEMPLATES as $key => $locales) {
            if (DB::table('site_pages')->where('slug', $key)->orWhere('system_key', $key)->exists()) {
                continue; // a facility page already uses the path
            }
            $page = DB::table('site_pages')->insertGetId([
                'slug' => $key, 'policy' => 'editable', 'system_key' => $key, 'show_in_nav' => false, 'sort' => ++$sort,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($locales as $locale => [$title, $sections]) {
                $rows = [];
                foreach ($sections as $i => [$heading, $body]) {
                    $rows[] = ['id' => "seed-$key-$locale-$i", 'type' => 'text', 'visible' => true, 'data' => ['heading' => $heading, 'body' => $body]];
                }
                $version = DB::table('site_page_versions')->insertGetId([
                    'page_id' => $page, 'locale' => $locale, 'number' => 1, 'title' => $title, 'meta_description' => null,
                    'sections' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('site_page_locales')->insert([
                    'page_id' => $page, 'locale' => $locale, 'draft_version_id' => $version, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** Guidance templates only: the facility adapts them (ideally with legal advice) before publishing. */
    private const TEMPLATES = [
        'privacy' => [
            'ar' => ['سياسة الخصوصية', [
                ['من نحن', 'تشرح هذه السياسة كيف نجمع بياناتك الشخصية ونستخدمها ونحميها عند زيارتك موقعنا أو متجرنا أو تواصلك معنا، وفق نظام حماية البيانات الشخصية في المملكة العربية السعودية.'],
                ['البيانات التي نجمعها', 'ما تُدخله بنفسك في نماذج التواصل وطلب الموعد والشراء: الاسم ورقم الجوال والبريد والعنوان وتفاصيل طلبك. نستخدمها للرد عليك وتنفيذ طلبك وإصدار الفواتير، ولا نبيعها لأي جهة.'],
                ['ملفات الارتباط (Cookies)', 'نستخدم ملفات ضرورية لتشغيل الموقع والسلة وتسجيل الدخول. ونحفظ في ملف أولى مصدر زيارتك (مثل الحملة الإعلانية أو الموقع الذي أحالك) لمدة 90 يوماً، لنعرف أي قنواتنا تخدم عملاءنا؛ يبقى هذا لدينا ولا يُشارك. أما ملفات التحليلات والإعلانات (مثل Google وMeta وTikTok وSnapchat) فلا تُفعَّل إلا بعد موافقتك، ويمكنك تغيير اختيارك في أي وقت من رابط «إعدادات ملفات الارتباط» أسفل الصفحة.'],
                ['ما يُرسل إلى أدوات الإعلان', 'عند موافقتك تُرسل إلى هذه الأدوات معلومات عن تصفحك ومشترياتك (مثل المنتج والقيمة ورقم الطلب) دون اسمك أو رقمك أو بريدك.'],
                ['حقوقك', 'لك أن تطلب الاطلاع على بياناتك أو تصحيحها أو حذفها أو الاعتراض على معالجتها، بالتواصل معنا عبر بيانات التواصل في أسفل الموقع.'],
                ['التعديلات', 'قد نحدّث هذه السياسة، وننشر أي تعديل في هذه الصفحة.'],
            ]],
            'en' => ['Privacy policy', [
                ['Who we are', 'This policy explains how we collect, use and protect your personal data when you visit our site or store or contact us, under the Saudi Personal Data Protection Law (PDPL).'],
                ['Data we collect', 'What you enter yourself in the contact, appointment and checkout forms: name, mobile, email, address and the details of your request. We use it to answer you, fulfil your order and issue invoices, and we never sell it.'],
                ['Cookies', 'We use necessary cookies to run the site, the cart and sign-in. A first-party cookie keeps where your visit came from (such as an ad campaign or a referring site) for 90 days so we know which channels serve our customers; it stays with us and is never shared. Analytics and advertising cookies (such as Google, Meta, TikTok and Snapchat) run only after you agree, and you can change your choice at any time from the "Cookie settings" link at the bottom of the page.'],
                ['What advertising tools receive', 'With your consent these tools receive information about your browsing and purchases (such as the product, the value and the order number), never your name, number or email.'],
                ['Your rights', 'You may ask to access, correct or delete your data, or object to its processing, by contacting us with the details at the bottom of the site.'],
                ['Changes', 'We may update this policy and will publish any change on this page.'],
            ]],
        ],
        'terms' => [
            'ar' => ['الشروط والأحكام', [
                ['الطلبات والأسعار', 'الأسعار المعروضة بالريال السعودي وتشمل ضريبة القيمة المضافة ما لم يُذكر غير ذلك. يُؤكَّد الطلب بعد إتمام الدفع، ونرسل لك رقمه وفاتورته.'],
                ['التوصيل والتركيب', 'نتفق معك على موعد التوصيل أو التركيب، ونبلغك بأي تأخير.'],
                ['الإلغاء والاسترجاع', 'يمكنك طلب الإلغاء أو الاسترجاع وفق نظام التجارة الإلكترونية والسياسة التي نوضحها لك قبل الشراء. لا تُسترجع المنتجات المصنوعة حسب الطلب إلا لعيب في الصنع.'],
                ['التواصل', 'لأي استفسار أو شكوى تواصل معنا عبر بيانات التواصل في أسفل الموقع.'],
            ]],
            'en' => ['Terms and conditions', [
                ['Orders and prices', 'Prices are in Saudi riyals and include VAT unless stated otherwise. An order is confirmed once paid; we send you its number and invoice.'],
                ['Delivery and installation', 'We agree the delivery or installation date with you and tell you of any delay.'],
                ['Cancellation and returns', 'You may ask to cancel or return under the E-Commerce Law and the policy we show you before you buy. Made-to-order products are returned only for a manufacturing defect.'],
                ['Contact', 'For any question or complaint, contact us with the details at the bottom of the site.'],
            ]],
        ],
    ];
};
