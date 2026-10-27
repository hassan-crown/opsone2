<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use App\Models\WhatsNewEntry;
use Illuminate\Database\Seeder;

/**
 * Starter help articles (one per core screen, NFR-18) and the first release note. Idempotent by slug/version.
 * Later domains add their articles here or in their own seeder; OpsOne knowledge staff publish updates (P-81).
 */
class HelpSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->articles() as $i => $a) {
            HelpArticle::query()->updateOrCreate(['slug' => $a['slug']], [...$a, 'sort' => $i, 'published_at' => now()]);
        }

        WhatsNewEntry::query()->updateOrCreate(['version' => '0.3.0'], [
            'released_on' => '2026-11-01',
            'title_ar' => 'المساعدة والبحث ومركز العمل',
            'title_en' => 'Help, search and the work center',
            'body_ar' => 'مركز المساعدة بمقالات عربية وإنجليزية، وبحث عام ضمن صلاحياتك، وصفحة رئيسية جديدة للمهام والتنبيهات.',
            'body_en' => 'A help center with Arabic and English articles, global search limited to what you may see, and a new home page for tasks and alerts.',
            'published_at' => now(),
        ]);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function articles(): array
    {
        return [
            [
                'slug' => 'what-is-opsone', 'category' => 'getting_started', 'page_key' => null,
                'title_ar' => 'ما هو OpsOne؟', 'title_en' => 'What is OpsOne?',
                'summary_ar' => 'نظام واحد لطلباتك من الاهتمام إلى التسليم.', 'summary_en' => 'One system for your orders, from first interest to delivery.',
                'body_ar' => "OpsOne نظام تشغيل للمنشآت التي تنفذ طلبات حسب الطلب.\nتعمل كل منشأة في بيئتها المعزولة، وتظهر لك الأقسام حسب صلاحيات دورك وباقة منشأتك.",
                'body_en' => "OpsOne runs facilities that make orders to measure.\nEach facility works in its own isolated deployment, and you see sections according to your role and your plan.",
            ],
            [
                'slug' => 'first-setup-and-team', 'category' => 'getting_started', 'page_key' => 'org.setup',
                'title_ar' => 'التهيئة الأولى والفريق', 'title_en' => 'First setup and your team',
                'summary_ar' => 'معالج الإعداد، ودعوة الفريق، والأدوار والفروع.', 'summary_en' => 'The setup wizard, inviting staff, roles and branches.',
                'body_ar' => "1. أكمل خطوات معالج الإعداد من الصفحة الرئيسية.\n2. أضف الفروع من «الفروع».\n3. ادعُ الموظفين من «الفريق والأدوار» وحدد دور كل منهم ونطاقه.",
                'body_en' => "1. Finish the setup wizard from the home page.\n2. Add branches under Branches.\n3. Invite staff under Team and roles and choose each person's role and scope.",
            ],
            [
                'slug' => 'navigation-search-help', 'category' => 'getting_started', 'page_key' => 'search.index',
                'title_ar' => 'التنقل والبحث والمساعدة', 'title_en' => 'Navigation, search and help',
                'summary_ar' => 'القائمة الجانبية، والبحث العام، وزر «؟» في كل صفحة.', 'summary_en' => 'The side menu, global search and the «?» button on every page.',
                'body_ar' => "ابحث من أعلى الصفحة عن الأعضاء والفروع ومقالات المساعدة. لا تظهر في النتائج إلا السجلات التي تسمح لك صلاحياتك برؤيتها.\nزر «؟» يفتح شرح الصفحة التي أنت فيها.",
                'body_en' => "Search from the top bar for members, branches and help articles. Results only include records your permissions let you see.\nThe «?» button opens the explanation of the page you are on.",
            ],
            [
                'slug' => 'home-work-center', 'category' => 'daily_work', 'page_key' => 'dashboard',
                'title_ar' => 'الرئيسية ومركز العمل', 'title_en' => 'Home and the work center',
                'summary_ar' => 'ما يحتاج قرارك وتنبيهات اليوم.', 'summary_en' => 'What needs your decision, and today\'s alerts.',
                'body_ar' => "الرئيسية تجمع مهامك وتنبيهاتك في مكان واحد.\nتظهر الأقسام التي تناسب دورك فقط، وتمتلئ تدريجيًا مع تفعيل بقية أقسام النظام.",
                'body_en' => "Home gathers your tasks and alerts in one place.\nYou only see the sections that fit your role, and they fill up as other parts of the system are switched on.",
            ],
            [
                'slug' => 'team-and-roles', 'category' => 'settings', 'page_key' => 'org.team.index',
                'title_ar' => 'الفريق والأدوار', 'title_en' => 'Team and roles',
                'summary_ar' => 'من يرى ماذا، ولأي نطاق.', 'summary_en' => 'Who sees what, and within which scope.',
                'body_ar' => "لكل عضو دور واحد أو أكثر، ولكل دور نطاق: فروع محددة أو منطقة أو القسم أو كامل المنشأة.\nتعطيل العضو يوقف جلساته فورًا.",
                'body_en' => "Every member has one or more roles, and every role has a scope: chosen branches, a region, the department, or the whole facility.\nDisabling a member ends their sessions immediately.",
            ],
            [
                'slug' => 'branches', 'category' => 'settings', 'page_key' => 'org.branches.index',
                'title_ar' => 'الفروع', 'title_en' => 'Branches',
                'summary_ar' => 'إضافة الفروع وتعطيلها وحدود الباقة.', 'summary_en' => 'Adding and disabling branches, and plan limits.',
                'body_ar' => "أضف فرعًا من «الفروع». عدد الفروع محدود بباقتك.\nتعطيل فرع لا يحذف سجلاته السابقة.",
                'body_en' => "Add a branch from Branches. The number of branches is limited by your plan.\nDisabling a branch does not delete its past records.",
            ],
            [
                'slug' => 'settings-and-plan', 'category' => 'settings', 'page_key' => 'settings.home',
                'title_ar' => 'الإعدادات والباقة', 'title_en' => 'Settings and your plan',
                'summary_ar' => 'مكان كل إعداد، والحدود والاستخدام.', 'summary_en' => 'Where each setting lives, plus limits and usage.',
                'body_ar' => "كل إعداد له مكان واحد في «الإعدادات». ترى الأقسام التي تسمح بها صلاحياتك فقط.\nالباقة والاستخدام يعرضان المقاعد والفروع المستعملة.",
                'body_en' => "Every setting has a single home under Settings. You only see sections your permissions allow.\nPlan and usage show the seats and branches in use.",
            ],
            [
                'slug' => 'ask-opsone-for-help', 'category' => 'faq', 'page_key' => 'help.support',
                'title_ar' => 'كيف أطلب مساعدة فريق OpsOne؟', 'title_en' => 'How do I ask the OpsOne team for help?',
                'summary_ar' => 'طلب الدعم، ولا يرى الفريق بياناتك إلا بإذن مؤقت.', 'summary_en' => 'Send a support request; the team only sees your data with temporary permission.',
                'body_ar' => "من «؟» ثم «طلب دعم OpsOne» اكتب سؤالك أو مشكلتك.\nلا يطلع الفريق على بيانات منشأتك إلا بإذن مؤقت يمنحه المالك.",
                'body_en' => "From «?» choose Ask OpsOne for help and describe your question or problem.\nThe team can only look at your facility's data with a temporary permission the owner grants.",
            ],
            [
                'slug' => 'why-no-section', 'category' => 'faq', 'page_key' => null,
                'title_ar' => 'لماذا لا يظهر لي قسم ما؟', 'title_en' => null,
                'summary_ar' => 'تظهر الأقسام حسب صلاحيات دورك وباقة منشأتك.', 'summary_en' => null,
                'body_ar' => 'تظهر الأقسام حسب صلاحيات دورك وباقة منشأتك. اطلب من مالك المنشأة أو مديرها تعديل دورك إن احتجت وصولًا إضافيًا.',
                'body_en' => null,
            ],
        ];
    }
}
