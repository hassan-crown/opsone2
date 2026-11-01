<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Site\SitePageWriter;
use App\Actions\Site\ThemeWriter;
use App\Files\AttachmentService;
use App\Models\Site\SitePage;
use App\Site\Theme\BrandPalette;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * The public site of OpsOne in Arabic and English: home, about, kitchens, accessories and contact pages with real
 * marketing copy, SEO titles and descriptions, photos from the committed showcase set and the Alpha theme with a
 * brand colour that passes the contrast guard. Published on 2026-04-28, before the first business day.
 */
final class Site
{
    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        Timeline::freeze(CarbonImmutable::parse('2026-04-28 10:00', 'Asia/Riyadh'));
        $this->theme();
        $writer = app(SitePageWriter::class);
        $dana = $this->s->users['content'];

        $home = SitePage::query()->where('system_key', 'home')->firstOrFail();
        $about = $writer->create($dana, 'about', ['ar' => 'من نحن', 'en' => 'About us'], true);
        $kitchens = $writer->create($dana, 'kitchens', ['ar' => 'مشاريعنا', 'en' => 'Our kitchens'], true);
        $accessories = $writer->create($dana, 'accessories', ['ar' => 'الإكسسوارات', 'en' => 'Accessories'], true);
        $contact = $writer->create($dana, 'contact', ['ar' => 'تواصل معنا', 'en' => 'Contact'], true);

        $writer->saveDraft($dana, $home, $this->home($home), null, false);
        $writer->saveDraft($dana, $about, $this->about($about));
        $writer->saveDraft($dana, $kitchens, $this->kitchens($kitchens));
        $writer->saveDraft($dana, $accessories, $this->accessories($accessories));
        $writer->saveDraft($dana, $contact, $this->contact());
        foreach ([$home, $about, $kitchens, $accessories, $contact] as $page) {
            $writer->publish($dana, $page->refresh(), ['ar', 'en']);
        }
    }

    private function theme(): void
    {
        $owner = $this->s->users['owner'];
        $writer = app(ThemeWriter::class);
        $brand = null;
        foreach (['#A8501C', '#9C4A1A', '#8A4317', '#1F5C6B'] as $candidate) {
            $derived = BrandPalette::derive($candidate);
            if ($derived['status'] !== 'rejected') {
                $brand = $candidate;
                break;
            }
        }
        $writer->saveDraft($owner, ['brand_color' => $brand, 'font' => 'plex-arabic', 'appearance' => 'light']);
        $dir = Assets::dir().'/../brand';
        foreach (['logo' => 'logo.png', 'logo_dark' => 'logo-dark.png', 'favicon' => 'favicon.png'] as $kind => $file) {
            if (is_file($dir.'/'.$file)) {
                $writer->uploadAsset($owner, $kind, new UploadedFile($dir.'/'.$file, 'opsone-'.$file, 'image/png', null, true));
            }
        }
        $writer->apply($owner);
    }

    /**
     * @param  list<int>  $ids  Pexels photo ids
     * @return list<string> attachment uuids (empty when images are off)
     */
    private function photos(SitePage $page, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $attachment = $this->s->assets->attach($this->s->users['content'], $page, $id, 'site_image');
            $out[] = $attachment?->uuid;
        }

        return $out;
    }

    /**
     * @param  list<string|null>  $uuids
     * @param  list<array{0: string, 1: string}>  $captions  [ar, en]
     * @return array{ar: list<array<string, mixed>>, en: list<array<string, mixed>>}
     */
    private function gallery(array $uuids, array $captions): array
    {
        $ar = [];
        $en = [];
        foreach ($uuids as $i => $uuid) {
            if ($uuid === null) {
                continue;
            }
            $ar[] = ['image' => $uuid, 'caption' => $captions[$i][0]];
            $en[] = ['image' => $uuid, 'caption' => $captions[$i][1]];
        }

        return ['ar' => $ar, 'en' => $en];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function home(SitePage $page): array
    {
        $images = $this->photos($page, [1080721, 8134756, 12700379, 6538903, 18285887, 14495889, 29923543, 6283970, 7545855, 8583896]);
        $gallery = $this->gallery(array_slice($images, 1, 8), [
            ['مطبخ L أبيض بسطح كوارتز وإضاءة مخفية', 'White L-shaped kitchen with quartz top and hidden lighting'],
            ['مطبخ عصري بخطوط نظيفة وجزيرة وسطية', 'Modern clean-line kitchen with a central island'],
            ['واجهات أكريليك مطفية بدرجات الرمادي', 'Matte acrylic fronts in soft greys'],
            ['مطبخ داكن فاخر بسطح رخام أسود', 'Dark luxury kitchen with black marble'],
            ['مطبخ U بخزائن حتى السقف', 'U-shaped kitchen with floor-to-ceiling cabinets'],
            ['جزيرة بمقاعد للإفطار وسطح رخام أبيض', 'Island with breakfast seating and white marble'],
            ['خشب دافئ مع تفاصيل ذهبية', 'Warm wood with gold details'],
            ['تصميم مفتوح على الصالة بأجهزة مدمجة', 'Open-plan design with integrated appliances'],
        ]);
        $heroAr = ['id' => 'home-hero', 'type' => 'hero', 'visible' => true, 'data' => [
            'heading' => 'مطبخك الذي تحلم به، يُصمَّم ويُصنَّع ويُركَّب بإتقان',
            'subheading' => 'أوبس ون تصمّم وتصنّع وتركّب مطابخ مفصّلة على مقاس بيتك، وتوفّر أرقى إكسسوارات المطابخ من مقابض ومفصلات ومنظمات وخلاطات وإضاءة. معاينة وقياس مجانيان في الرياض وجدة والدمام.',
            'cta_label' => 'اطلب معاينة مجانية', 'cta_link' => '#contact', 'image' => $images[0]]];
        $heroEn = ['id' => 'home-hero', 'type' => 'hero', 'visible' => true, 'data' => [
            'heading' => 'Your dream kitchen, designed, built and installed with care',
            'subheading' => 'OpsOne designs, manufactures and installs made-to-measure kitchens, and supplies premium kitchen accessories: handles, hinges, organizers, mixers and lighting. Free survey and measurement in Riyadh, Jeddah and Dammam.',
            'cta_label' => 'Book a free survey', 'cta_link' => '#contact', 'image' => $images[0]]];
        $services = fn (string $l) => ['id' => 'home-services', 'type' => 'services', 'visible' => true, 'data' => ['heading' => $l === 'ar' ? 'خدماتنا' : 'What we do', 'items' => $l === 'ar' ? [
            ['title' => 'تصميم ثلاثي الأبعاد', 'text' => 'مصمّمونا يحوّلون مساحتك وأسلوب حياتك إلى تصميم واقعي تراه قبل التصنيع وتعتمده بنفسك.'],
            ['title' => 'تصنيع محلي بجودة عالية', 'text' => 'ورشتنا في الرياض تصنع من ألواح مقاومة للرطوبة وواجهات أكريليك وخشب طبيعي بفحص جودة قبل كل تسليم.'],
            ['title' => 'تركيب بفرق متخصصة', 'text' => 'فنيو تركيب في كل فرع يلتزمون بالمواعيد ويسلّمون الموقع نظيفًا مع ضمان على التصنيع والتركيب.'],
            ['title' => 'أسطح كوارتز ورخام', 'text' => 'نوفّر أجود الأسطح بقص وتركيب دقيقين وحواف مصقولة وحوض مدمج.'],
            ['title' => 'إكسسوارات مطبخ جاهزة', 'text' => 'مقابض ومفصلات ومجاري أدراج ومنظمات وخلاطات وإضاءة LED، متوفرة بالمخزون في فروعنا ومن المتجر.'],
            ['title' => 'ضمان وخدمة ما بعد البيع', 'text' => 'ضمان سنتين، وبلاغات الصيانة تُتابع من حسابك حتى الإغلاق.'],
        ] : [
            ['title' => '3D design', 'text' => 'Our designers turn your space and lifestyle into a realistic design you can see and approve before we build.'],
            ['title' => 'High-quality local manufacturing', 'text' => 'Our Riyadh workshop builds with moisture-resistant boards, acrylic fronts and natural wood, with a quality check before every handover.'],
            ['title' => 'Installation by specialists', 'text' => 'Installation crews in every branch keep to schedule and hand over a clean site with a manufacturing and fitting warranty.'],
            ['title' => 'Quartz and marble tops', 'text' => 'The finest worktops, precisely cut and fitted, with polished edges and an integrated sink.'],
            ['title' => 'Ready kitchen accessories', 'text' => 'Handles, hinges, drawer slides, organizers, mixers and LED lighting, in stock in our branches and in the store.'],
            ['title' => 'Warranty and after-sales', 'text' => 'Two-year warranty, and service requests are tracked from your account until they are closed.'],
        ]]];
        $process = fn (string $l) => ['id' => 'home-process', 'type' => 'text', 'visible' => true, 'data' => $l === 'ar' ? [
            'heading' => 'كيف نعمل: من الفكرة إلى مطبخ جاهز',
            'body' => "١. معاينة وقياس: يزورك مختص في الموقع ويأخذ المقاسات بدقة.\n٢. تصميم واعتماد: تصميم ثلاثي الأبعاد ثم عرض سعر واضح بالتفصيل وضريبة القيمة المضافة.\n٣. اتفاق ودفعة أولى: دفعة أولى 50% وتتابع مراحل مشروعك من حسابك الشخصي.\n٤. تصنيع: في ورشتنا 3 إلى 4 أسابيع مع فحص جودة.\n٥. تركيب وتسليم: فريق التركيب في الموعد، والدفعة الأخيرة بعد التسليم والمعاينة النهائية.",
        ] : [
            'heading' => 'How we work: from idea to finished kitchen',
            'body' => "1. Survey and measure: a specialist visits and measures your site precisely.\n2. Design and approval: a 3D design, then a clear itemised quote including VAT.\n3. Agreement and first payment: 50% down, and you follow every stage from your own account.\n4. Manufacturing: 3 to 4 weeks in our workshop with a quality check.\n5. Installation and handover: our crew on the agreed day, final payment after the final walkthrough.",
        ]];
        $gal = fn (string $l) => ['id' => 'home-gallery', 'type' => 'gallery', 'visible' => $gallery[$l] !== [], 'data' => ['heading' => $l === 'ar' ? 'من مطابخنا' : 'Featured kitchens', 'images' => $gallery[$l]]];
        $goods = fn (string $l) => ['id' => 'home-goods', 'type' => 'ready_goods', 'visible' => true, 'data' => $l === 'ar'
            ? ['heading' => 'إكسسوارات المطبخ من متجرنا', 'text' => 'اطلب مقابض ومفصلات ومنظمات وخلاطات وإضاءة بتوصيلها إلى أقرب فرع، وبأسعار شاملة الضريبة.']
            : ['heading' => 'Kitchen accessories from our store', 'text' => 'Order handles, hinges, organizers, mixers and lighting for pick-up at your nearest branch, prices include VAT.']];
        $testimonials = fn (string $l) => ['id' => 'home-testimonials', 'type' => 'testimonials', 'visible' => true, 'data' => ['heading' => $l === 'ar' ? 'ماذا يقول عملاؤنا' : 'What our customers say', 'items' => $l === 'ar' ? [
            ['quote' => 'التزموا بالموعد حرفيًا، والتصميم الثلاثي الأبعاد كان مطابقًا تمامًا للنتيجة. المطبخ أجمل من توقعاتنا.', 'author' => 'سارة العتيبي', 'role' => 'الرياض — مطبخ L بسطح كوارتز'],
            ['quote' => 'تعاملنا معهم في ست وحدات سكنية، جودة موحدة وتسليم على دفعات كما اتفقنا. شريك ممتاز للمطورين.', 'author' => 'م. عبدالله الراجحي', 'role' => 'مطور عقاري — جدة'],
            ['quote' => 'أعجبني أن كل مرحلة أتابعها من حسابي: الدفعات والإيصالات والمواعيد. لا حاجة للاتصال المستمر.', 'author' => 'خالد الدوسري', 'role' => 'الدمام — مطبخ جزيرة'],
            ['quote' => 'اشتريت المقابض والخلاط من المتجر واستلمتها من الفرع في اليوم نفسه. جودة ممتازة وسعر منافس.', 'author' => 'ريم الغامدي', 'role' => 'جدة — عميلة المتجر'],
        ] : [
            ['quote' => 'They kept the date to the letter and the 3D design matched the result exactly. The kitchen is better than we expected.', 'author' => 'Sarah Al-Otaibi', 'role' => 'Riyadh — L-shaped kitchen with quartz top'],
            ['quote' => 'We worked with them on six apartments: consistent quality and staged delivery as agreed. A great partner for developers.', 'author' => 'Eng. Abdullah Al-Rajhi', 'role' => 'Developer — Jeddah'],
            ['quote' => 'I loved following every stage from my account: payments, receipts and appointments. No need to keep calling.', 'author' => 'Khaled Al-Dosari', 'role' => 'Dammam — island kitchen'],
            ['quote' => 'I bought the handles and mixer from the store and picked them up the same day. Excellent quality, fair price.', 'author' => 'Reem Al-Ghamdi', 'role' => 'Jeddah — store customer'],
        ]]];
        $faq = fn (string $l) => ['id' => 'home-faq', 'type' => 'faq', 'visible' => true, 'data' => ['heading' => $l === 'ar' ? 'أسئلة شائعة' : 'Frequently asked questions', 'items' => $l === 'ar' ? [
            ['question' => 'كم يستغرق تنفيذ المطبخ؟', 'answer' => 'من اعتماد التصميم والدفعة الأولى إلى التركيب عادة من 5 إلى 7 أسابيع، تشمل أسبوعين للتصميم والاعتماد وثلاثة إلى أربعة أسابيع للتصنيع.'],
            ['question' => 'هل المعاينة والقياس مجانيان؟', 'answer' => 'نعم، زيارة المعاينة والقياس مجانية داخل الرياض وجدة والدمام ومحيطها.'],
            ['question' => 'هل الأسعار شاملة ضريبة القيمة المضافة؟', 'answer' => 'نعم، جميع الأسعار المعروضة والعروض شاملة ضريبة القيمة المضافة 15%.'],
            ['question' => 'ما نظام الدفع؟', 'answer' => 'دفعة أولى 50% عند اعتماد العرض، والباقي عند اكتمال التركيب. يمكنك رفع إيصال التحويل من حسابك ونؤكده لك خلال يوم عمل.'],
            ['question' => 'ما مدة الضمان؟', 'answer' => 'ضمان سنتان على التصنيع والتركيب، وضمان الشركة المصنعة على الأجهزة والأسطح والإكسسوارات.'],
            ['question' => 'هل يمكنني شراء الإكسسوارات دون تفصيل مطبخ؟', 'answer' => 'بالتأكيد، متجرنا يبيع المقابض والمفصلات والمنظمات والخلاطات والإضاءة لكل الناس مع إمكانية الاستلام من الفرع.'],
        ] : [
            ['question' => 'How long does a kitchen take?', 'answer' => 'From design approval and first payment to installation it is usually 5 to 7 weeks, including two weeks for design and approval and three to four weeks of manufacturing.'],
            ['question' => 'Are the survey and measurement free?', 'answer' => 'Yes, the survey and measurement visit is free in and around Riyadh, Jeddah and Dammam.'],
            ['question' => 'Do prices include VAT?', 'answer' => 'Yes, all displayed prices and quotes include 15% VAT.'],
            ['question' => 'How do payments work?', 'answer' => '50% on quote approval and the rest when installation is complete. You can upload your transfer receipt from your account and we confirm it within one working day.'],
            ['question' => 'What is the warranty?', 'answer' => 'Two years on manufacturing and installation, plus the manufacturer warranty on appliances, worktops and accessories.'],
            ['question' => 'Can I buy accessories without ordering a kitchen?', 'answer' => 'Of course. Our store sells handles, hinges, organizers, mixers and lighting to everyone, with pick-up from the branch.'],
        ]]];
        $contactSection = fn (string $l) => ['id' => 'home-contact', 'type' => 'contact', 'visible' => true, 'data' => $l === 'ar'
            ? ['heading' => 'ابدأ مشروع مطبخك اليوم', 'text' => 'اترك بياناتك وسيتصل بك مستشار من أقرب فرع خلال يوم عمل لتحديد موعد المعاينة المجانية.', 'show_form' => true]
            : ['heading' => 'Start your kitchen project today', 'text' => 'Leave your details and an adviser from your nearest branch will call you within one working day to book the free survey.', 'show_form' => true]];

        $build = fn (string $l) => [
            'title' => $l === 'ar' ? 'مطابخ مفصّلة وإكسسوارات مطابخ' : 'Custom kitchens and kitchen accessories',
            'meta_description' => $l === 'ar'
                ? 'أوبس ون: تصميم وتصنيع وتركيب مطابخ مفصّلة في الرياض وجدة والدمام، مع متجر إكسسوارات مطابخ بأسعار شاملة الضريبة. اطلب معاينة مجانية.'
                : 'OpsOne designs, manufactures and installs custom kitchens in Riyadh, Jeddah and Dammam, with a kitchen accessories store. Prices include VAT. Book a free survey.',
            'sections' => [$l === 'ar' ? $heroAr : $heroEn, $services($l), $gal($l), $goods($l), $process($l), $testimonials($l), $faq($l), $contactSection($l)],
        ];

        return ['ar' => $build('ar'), 'en' => $build('en')];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function about(SitePage $page): array
    {
        $images = $this->photos($page, [534151]);
        $hero = fn (string $h, string $sub, string $cta) => ['id' => 'about-hero', 'type' => 'hero', 'visible' => true, 'data' => ['heading' => $h, 'subheading' => $sub, 'cta_label' => $cta, 'cta_link' => 'contact', 'image' => $images[0]]];

        return [
            'ar' => ['title' => 'من نحن', 'meta_description' => 'تعرّف على أوبس ون: فريق سعودي يصمّم ويصنّع ويركّب المطابخ منذ سنوات، بثلاثة فروع في الرياض وجدة والدمام.', 'sections' => [
                $hero('نبني مطابخ تُعاش، لا تُشاهد فقط', 'فريق سعودي من المصممين والفنيين يجمع بين الحرفة والتقنية في كل مطبخ.', 'تواصل معنا'),
                ['id' => 'about-story', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'قصتنا', 'body' => "بدأت أوبس ون من ورشة صغيرة في الرياض بهدف واحد: أن يحصل كل بيت على مطبخ يناسب عائلته ويصمد لسنوات. اليوم نخدم عملاء الرياض وجدة والدمام عبر ثلاثة فروع وورشة تصنيع مركزية، ومعارض نعرض فيها خاماتنا وإكسسواراتنا.\n\nنؤمن أن الشفافية جزء من الجودة؛ لذلك يتابع العميل مشروعه من حسابه: المراحل والمواعيد والعروض والدفعات والإيصالات، دون مفاجآت."]],
                ['id' => 'about-values', 'type' => 'services', 'visible' => true, 'data' => ['heading' => 'قيمنا', 'items' => [
                    ['title' => 'الدقة', 'text' => 'قياس في الموقع وتصميم ثلاثي الأبعاد وفحص جودة قبل كل تسليم.'],
                    ['title' => 'الوضوح', 'text' => 'عرض سعر مفصّل شامل الضريبة، وإيصال رسمي لكل دفعة.'],
                    ['title' => 'الالتزام', 'text' => 'مواعيد مؤكدة وتذكيرات، وفريق يقف خلف ضمان سنتين.'],
                    ['title' => 'الاستدامة', 'text' => 'ألواح مقاومة للرطوبة بإنتاج محلي وتقليل الهدر بالتفصيل الدقيق.'],
                ]]],
            ]],
            'en' => ['title' => 'About us', 'meta_description' => 'Meet OpsOne: a Saudi team that has designed, built and installed kitchens for years, with three branches in Riyadh, Jeddah and Dammam.', 'sections' => [
                $hero('We build kitchens to be lived in, not just looked at', 'A Saudi team of designers and technicians combining craft and technology in every kitchen.', 'Contact us'),
                ['id' => 'about-story', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'Our story', 'body' => "OpsOne started as a small workshop in Riyadh with one aim: every home should have a kitchen that fits its family and lasts for years. Today we serve Riyadh, Jeddah and Dammam through three branches and a central workshop, with showrooms for our materials and accessories.\n\nWe believe transparency is part of quality, so customers follow their project from their account: stages, appointments, quotes, payments and receipts, with no surprises."]],
                ['id' => 'about-values', 'type' => 'services', 'visible' => true, 'data' => ['heading' => 'Our values', 'items' => [
                    ['title' => 'Precision', 'text' => 'On-site measurement, 3D design and a quality check before every handover.'],
                    ['title' => 'Clarity', 'text' => 'An itemised quote including VAT and an official receipt for every payment.'],
                    ['title' => 'Commitment', 'text' => 'Confirmed appointments and reminders, and a team behind a two-year warranty.'],
                    ['title' => 'Sustainability', 'text' => 'Moisture-resistant boards made locally and less waste through precise cutting.'],
                ]]],
            ]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function kitchens(SitePage $page): array
    {
        $ids = [8583735, 12700379, 4800189, 14569329, 6283972, 20326399, 35189706, 6538939, 10099318, 7167993, 3718434, 16056401];
        $uuids = $this->photos($page, $ids);
        $captions = [
            ['مطبخ L بخزائن بيضاء وسطح كوارتز — الرياض', 'L-shaped kitchen, white cabinets and quartz top — Riyadh'], ['مطبخ U مفتوح — جدة', 'Open U-shaped kitchen — Jeddah'],
            ['خشب دافئ وجزيرة بمقاعد — الدمام', 'Warm wood and an island with seating — Dammam'], ['مطبخ أنيق بدرجات الأبيض — الرياض', 'Elegant kitchen in whites — Riyadh'],
            ['مطبخ متوازي لشقة — جدة', 'Galley kitchen for an apartment — Jeddah'], ['جزيرة رخام أبيض — الرياض', 'White marble island — Riyadh'],
            ['مطبخ لفيلا بأجهزة مدمجة — الدمام', 'Villa kitchen with integrated appliances — Dammam'], ['مطبخ أسود وخشب — الرياض', 'Black and wood kitchen — Riyadh'],
            ['إضاءة LED مخفية وأسطح داكنة — جدة', 'Hidden LED lighting and dark surfaces — Jeddah'], ['مطبخ كلاسيكي بخشب طبيعي — الرياض', 'Classic natural-wood kitchen — Riyadh'],
            ['جزيرة وبار إفطار — الدمام', 'Island with breakfast bar — Dammam'], ['تصميم مفتوح على المعيشة — جدة', 'Open-plan living kitchen — Jeddah'],
        ];
        $gallery = $this->gallery($uuids, $captions);

        return [
            'ar' => ['title' => 'مشاريعنا ومعرض المطابخ', 'meta_description' => 'معرض مطابخ أوبس ون: تصاميم L وU وجزيرة ومطابخ متوازية بخامات أكريليك وخشب وكوارتز ورخام، من مشاريعنا في الرياض وجدة والدمام.', 'sections' => [
                ['id' => 'kit-text', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'مطابخ نفتخر بتسليمها', 'body' => 'نماذج حقيقية من مشاريعنا الأخيرة. كل مطبخ يبدأ بقياس الموقع وينتهي بتسليم مفصّل حسب أسلوب حياة صاحبه. اطلب معاينة مجانية ليرى مصمّمنا مساحتك.']],
                ['id' => 'kit-gallery', 'type' => 'gallery', 'visible' => $gallery['ar'] !== [], 'data' => ['heading' => 'معرض المشاريع', 'images' => $gallery['ar']]],
                ['id' => 'kit-contact', 'type' => 'contact', 'visible' => true, 'data' => ['heading' => 'أعجبك تصميم؟', 'text' => 'اكتب لنا وسنجهّز لك تصورًا وعرض سعر.', 'show_form' => true]],
            ]],
            'en' => ['title' => 'Our kitchens and projects', 'meta_description' => 'OpsOne kitchens gallery: L-shaped, U-shaped, island and galley designs in acrylic, wood, quartz and marble from our projects in Riyadh, Jeddah and Dammam.', 'sections' => [
                ['id' => 'kit-text', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'Kitchens we are proud to deliver', 'body' => 'Real examples from our recent projects. Every kitchen starts with measuring the site and ends in a handover tailored to its owner. Book a free survey so our designer can see your space.']],
                ['id' => 'kit-gallery', 'type' => 'gallery', 'visible' => $gallery['en'] !== [], 'data' => ['heading' => 'Project gallery', 'images' => $gallery['en']]],
                ['id' => 'kit-contact', 'type' => 'contact', 'visible' => true, 'data' => ['heading' => 'Like a design?', 'text' => 'Write to us and we will prepare a concept and a quote.', 'show_form' => true]],
            ]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function accessories(SitePage $page): array
    {
        $images = $this->photos($page, [39443630]);
        $hero = fn (string $h, string $s, string $c) => ['id' => 'acc-hero', 'type' => 'hero', 'visible' => true, 'data' => ['heading' => $h, 'subheading' => $s, 'cta_label' => $c, 'cta_link' => null, 'image' => $images[0]]];

        return [
            'ar' => ['title' => 'إكسسوارات المطابخ', 'meta_description' => 'متجر إكسسوارات المطابخ من أوبس ون: مقابض ومفصلات صامتة ومنظمات أدراج وخلاطات وشرائط LED. أسعار شاملة الضريبة واستلام من الفرع.', 'sections' => [
                $hero('إكسسوارات مطبخ تدوم', 'مقابض ومفصلات ومجاري أدراج ومنظمات وخلاطات وإضاءة، منتقاة بعناية ومتوفرة في فروعنا.', ''),
                ['id' => 'acc-goods', 'type' => 'ready_goods', 'visible' => true, 'data' => ['heading' => 'تسوّق الآن من المتجر', 'text' => 'اختر منتجاتك وادفع إلكترونيًا واستلمها من أقرب فرع. استخدم كود الخصم الموسمي عند الدفع.']],
                ['id' => 'acc-services', 'type' => 'services', 'visible' => true, 'data' => ['heading' => 'أقسام المتجر', 'items' => [
                    ['title' => 'المقابض والمسكات', 'text' => 'بار ألمنيوم وكرة وحلق ومقابض مخفية بألوان أسود وذهبي وستانلس.'],
                    ['title' => 'المفصلات ومجاري الأدراج', 'text' => 'نظام صامت يدوم لسنوات، وفتح بالدفع، وتاندم بوكس للأدراج العميقة.'],
                    ['title' => 'منظمات الأدراج والخزائن', 'text' => 'أدوات المائدة والتوابل والزجاجات ودوّار الزاوية وسلال المهملات.'],
                    ['title' => 'الخلاطات والأحواض', 'text' => 'خلاطات أحادية ورأس سحب وأحواض ستانلس وجرانيت.'],
                    ['title' => 'إضاءة LED', 'text' => 'شرائط دافئة وباردة بمحولات وحساسات حركة لإضاءة تحت الخزائن.'],
                ]]],
            ]],
            'en' => ['title' => 'Kitchen accessories', 'meta_description' => 'The OpsOne kitchen accessories store: handles, soft-close hinges, drawer organizers, mixers and LED strips. Prices include VAT with branch pick-up.', 'sections' => [
                $hero('Kitchen accessories built to last', 'Handles, hinges, drawer slides, organizers, mixers and lighting, carefully selected and in stock in our branches.', ''),
                ['id' => 'acc-goods', 'type' => 'ready_goods', 'visible' => true, 'data' => ['heading' => 'Shop now in the store', 'text' => 'Pick your products, pay online and collect from your nearest branch. Use the seasonal coupon at checkout.']],
                ['id' => 'acc-services', 'type' => 'services', 'visible' => true, 'data' => ['heading' => 'Store departments', 'items' => [
                    ['title' => 'Handles and pulls', 'text' => 'Aluminium bar, knob, ring and hidden pulls in black, gold and steel.'],
                    ['title' => 'Hinges and drawer slides', 'text' => 'Soft-close systems that last for years, push-to-open and tandem boxes for deep drawers.'],
                    ['title' => 'Drawer and cabinet organizers', 'text' => 'Cutlery, spices, bottles, corner carousels and waste bins.'],
                    ['title' => 'Mixers and sinks', 'text' => 'Single-lever and pull-out mixers, steel and granite sinks.'],
                    ['title' => 'LED lighting', 'text' => 'Warm and cool strips with drivers and motion sensors for under-cabinet light.'],
                ]]],
            ]],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contact(): array
    {
        return [
            'ar' => ['title' => 'تواصل معنا', 'meta_description' => 'فروع أوبس ون في الرياض وجدة والدمام: العناوين وساعات العمل وأرقام التواصل، واطلب معاينة مجانية لمطبخك.', 'sections' => [
                ['id' => 'con-branches', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'فروعنا', 'body' => "الرياض (المقر الرئيسي): طريق الملك فهد، حي العليا — هاتف 011 234 5678\nجدة: طريق الأمير سلطان، حي الشاطئ — هاتف 012 345 6789\nالدمام: طريق الملك عبدالعزيز، حي الشاطئ — هاتف 013 456 7890\n\nساعات العمل: من الأحد إلى الخميس، 8 صباحًا حتى 5 مساءً. الجمعة والسبت إجازة."]],
                ['id' => 'con-form', 'type' => 'contact', 'visible' => true, 'data' => ['heading' => 'اطلب معاينة مجانية', 'text' => 'أخبرنا عن مطبخك وسنتصل بك خلال يوم عمل.', 'show_form' => true]],
            ]],
            'en' => ['title' => 'Contact us', 'meta_description' => 'OpsOne branches in Riyadh, Jeddah and Dammam: addresses, opening hours and contact numbers. Request a free survey for your kitchen.', 'sections' => [
                ['id' => 'con-branches', 'type' => 'text', 'visible' => true, 'data' => ['heading' => 'Our branches', 'body' => "Riyadh (HQ): King Fahd Road, Al Olaya — tel 011 234 5678\nJeddah: Prince Sultan Road, Al Shati — tel 012 345 6789\nDammam: King Abdulaziz Road, Al Shati — tel 013 456 7890\n\nOpening hours: Sunday to Thursday, 8 am to 5 pm. Friday and Saturday closed."]],
                ['id' => 'con-form', 'type' => 'contact', 'visible' => true, 'data' => ['heading' => 'Request a free survey', 'text' => 'Tell us about your kitchen and we will call you within one working day.', 'show_form' => true]],
            ]],
        ];
    }
}
