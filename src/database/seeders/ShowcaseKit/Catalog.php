<?php

namespace Database\Seeders\ShowcaseKit;

use App\Actions\Catalog\SaveMaterial;
use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveReadyProduct;
use App\Actions\Catalog\SaveVariant;
use App\Actions\Catalog\SetCatalogStatus;
use App\Catalog\CatalogStatus;
use App\Models\Catalog\CatalogMaterial;
use App\Models\Catalog\CatalogProduct;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\StockLocation;
use Carbon\CarbonImmutable;

/**
 * The catalogue of OpsOne: 8 made-to-order kitchen products with their specification fields, materials (wood, MDF,
 * quartz, marble, acrylic, hardware) and ~40 ready accessories with variants, plus one stock location per branch.
 * Photos are attached through the attachments service.
 */
final class Catalog
{
    /** @var array<string, CatalogProduct> */
    public array $products = [];

    /** @var array<string, CatalogMaterial> */
    public array $materials = [];

    /** @var list<CatalogReadyVariant> */
    public array $variants = [];

    /** @var array<string, list<CatalogReadyVariant>> category => variants */
    public array $byCategory = [];

    /** @var array<string, StockLocation> */
    public array $locations = [];

    public function __construct(private readonly Showcase $s) {}

    public function run(): void
    {
        Timeline::freeze(CarbonImmutable::parse('2026-04-22 10:00', 'Asia/Riyadh'));
        $this->locations();
        $this->materials();
        $this->products();
        $this->ready();
    }

    private function locations(): void
    {
        $main = StockLocation::query()->where('code', 'MAIN')->first();
        $main?->update(['name' => ['ar' => 'المستودع المركزي', 'en' => 'Central warehouse']]);
        foreach ([
            'RUH' => ['RUH-WH', 'مستودع الرياض', 'Riyadh warehouse'], 'JED' => ['JED-WH', 'مستودع جدة', 'Jeddah warehouse'],
            'DMM' => ['DMM-WH', 'مستودع الدمام', 'Dammam warehouse'],
        ] as $branch => [$code, $ar, $en]) {
            $this->locations[$branch] = StockLocation::query()->create(['code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'branch_id' => $this->s->branches[$branch]->id]);
        }
        $this->locations['RUH-SHOW'] = StockLocation::query()->create([
            'code' => 'RUH-SHW', 'name' => ['ar' => 'معرض الرياض', 'en' => 'Riyadh showroom'], 'branch_id' => $this->s->branches['RUH']->id,
        ]);
    }

    private function materials(): void
    {
        $owner = $this->s->users['owner'];
        $save = app(SaveMaterial::class);
        // key => [group, ar, en, unit, supplier, track stock]
        $rows = [
            'beech' => ['الأخشاب', 'خشب زان طبيعي', 'Natural beech wood', 'م²', 'مصنع الأخشاب الوطنية', false],
            'oak' => ['الأخشاب', 'خشب بلوط مستورد', 'Imported oak', 'م²', 'مصنع الأخشاب الوطنية', false],
            'mdf_moisture' => ['ألواح MDF', 'MDF مقاوم للرطوبة 18 ملم', 'Moisture-resistant MDF 18 mm', 'لوح', 'شركة الألواح المتقدمة', true],
            'mdf_laminate' => ['ألواح MDF', 'MDF لامينيت مطفي', 'Matte laminate MDF', 'لوح', 'شركة الألواح المتقدمة', true],
            'quartz_white' => ['الأسطح', 'كوارتز أبيض نقي', 'Pure white quartz', 'م²', 'الأسطح الحجرية', false],
            'quartz_grey' => ['الأسطح', 'كوارتز رمادي فاتح', 'Light grey quartz', 'م²', 'الأسطح الحجرية', false],
            'marble_carrara' => ['الأسطح', 'رخام كرارا', 'Carrara marble', 'م²', 'الأسطح الحجرية', false],
            'marble_black' => ['الأسطح', 'رخام أسود ماركينا', 'Black Marquina marble', 'م²', 'الأسطح الحجرية', false],
            'acrylic_gloss' => ['الواجهات', 'أكريليك لامع أبيض', 'Gloss white acrylic', 'م²', 'ألوان الشرق', false],
            'acrylic_matte' => ['الواجهات', 'أكريليك مطفي رمادي', 'Matte grey acrylic', 'م²', 'ألوان الشرق', false],
            'hinge_hardware' => ['الاكسسوارات والمعدات', 'مفصلات هيدروليك للأبواب', 'Soft-close door hinges', 'قطعة', 'الحديد الألماني', true],
            'slide_hardware' => ['الاكسسوارات والمعدات', 'مجرى أدراج كامل السحب', 'Full-extension drawer slide', 'زوج', 'الحديد الألماني', true],
            'edge_banding' => ['الاكسسوارات والمعدات', 'شريط حواف PVC', 'PVC edge banding', 'متر', 'شركة الألواح المتقدمة', true],
            'led_profile' => ['الاكسسوارات والمعدات', 'قطاع ألمنيوم للإضاءة', 'Aluminium LED profile', 'متر', 'الإضاءة الحديثة', true],
        ];
        foreach ($rows as $key => [$group, $ar, $en, $unit, $supplier, $track]) {
            $material = $save->handle($owner, null, [
                'material_group' => $group, 'name' => ['ar' => $ar, 'en' => $en], 'description' => null, 'unit' => $unit,
                'availability' => 'available', 'supplier_name' => $supplier, 'track_quantity' => $track,
            ]);
            $this->materials[$key] = $material->refresh();
        }
    }

    private function products(): void
    {
        $owner = $this->s->users['owner'];
        $save = app(SaveProduct::class);
        $m = fn (string ...$keys) => array_map(fn (string $k) => $this->materials[$k]->id, $keys);
        $groups = fn (array $doors, array $tops) => [
            ['key' => 'door', 'label' => ['ar' => 'خامة الواجهات', 'en' => 'Door material'], 'type' => 'single', 'required' => true,
                'values' => [['ar' => 'أكريليك لامع', 'en' => 'Gloss acrylic'], ['ar' => 'أكريليك مطفي', 'en' => 'Matte acrylic'], ['ar' => 'خشب طبيعي', 'en' => 'Natural wood'], ['ar' => 'لامينيت', 'en' => 'Laminate']],
                'material_ids' => $doors],
            ['key' => 'top', 'label' => ['ar' => 'سطح العمل', 'en' => 'Countertop'], 'type' => 'single', 'required' => true,
                'values' => [['ar' => 'كوارتز', 'en' => 'Quartz'], ['ar' => 'رخام', 'en' => 'Marble']], 'material_ids' => $tops],
            ['key' => 'color', 'label' => ['ar' => 'اللون', 'en' => 'Colour'], 'type' => 'single', 'required' => true,
                'values' => [['ar' => 'أبيض', 'en' => 'White'], ['ar' => 'رمادي', 'en' => 'Grey'], ['ar' => 'بلوط فاتح', 'en' => 'Light oak'], ['ar' => 'أسود', 'en' => 'Black']]],
            ['key' => 'handles', 'label' => ['ar' => 'نوع المقابض', 'en' => 'Handle type'], 'type' => 'single', 'required' => false,
                'values' => [['ar' => 'مقبض مخفي', 'en' => 'Hidden pull'], ['ar' => 'مقبض بار ألمنيوم', 'en' => 'Aluminium bar'], ['ar' => 'فتح بالدفع', 'en' => 'Push-to-open']]],
            ['key' => 'length', 'label' => ['ar' => 'الطول الإجمالي', 'en' => 'Total length'], 'type' => 'number', 'required' => true, 'unit' => 'م'],
            ['key' => 'height', 'label' => ['ar' => 'ارتفاع السقف', 'en' => 'Ceiling height'], 'type' => 'number', 'required' => false, 'unit' => 'م'],
            ['key' => 'appliances', 'label' => ['ar' => 'الأجهزة المدمجة', 'en' => 'Built-in appliances'], 'type' => 'multiple', 'required' => false,
                'values' => [['ar' => 'فرن', 'en' => 'Oven'], ['ar' => 'غسالة صحون', 'en' => 'Dishwasher'], ['ar' => 'ثلاجة مدمجة', 'en' => 'Built-in fridge'], ['ar' => 'شفاط', 'en' => 'Hood']]],
            ['key' => 'plan', 'label' => ['ar' => 'مخطط الموقع', 'en' => 'Site plan'], 'type' => 'attachment', 'required' => false],
        ];

        $rows = [
            'l' => ['مطبخ على شكل L', 'L-shaped kitchen', 'تصميم الزاوية الأكثر طلبًا: يستثمر ضلعين متجاورين ويترك مساحة وسطية مريحة للحركة والطبخ.', 'The most requested layout: it uses two adjoining walls and keeps a comfortable working floor.', 380000],
            'u' => ['مطبخ على شكل U', 'U-shaped kitchen', 'ثلاثة أضلاع من الخزائن وسطح عمل متصل، مثالي للمطابخ الكبيرة والعائلات التي تطبخ كثيرًا.', 'Three runs of cabinets with a continuous worktop, ideal for large kitchens and families that cook a lot.', 460000],
            'island' => ['مطبخ مع جزيرة', 'Kitchen with island', 'جزيرة وسطية بسطح كوارتز أو رخام، تجمع التحضير والجلوس وتمنح المطبخ طابعًا عصريًا مفتوحًا.', 'A central island in quartz or marble that combines prep and seating for an open, modern kitchen.', 520000],
            'galley' => ['مطبخ متوازي', 'Galley kitchen', 'صفّان متقابلان يوفران أقصى كفاءة في المساحات الضيقة، مع تخزين ذكي وإضاءة مخفية.', 'Two facing runs for maximum efficiency in narrow spaces, with smart storage and concealed lighting.', 340000],
            'straight' => ['مطبخ مستقيم', 'Single-wall kitchen', 'حل أنيق وبسيط للشقق والاستوديوهات، بخزائن علوية وسفلية على جدار واحد.', 'A clean and simple solution for apartments and studios, with wall and base cabinets on one wall.', 290000],
            'peninsula' => ['مطبخ مع بار شبه جزيرة', 'Peninsula kitchen', 'امتداد من سطح العمل يعمل كبار للإفطار ويفصل المطبخ عن الصالة دون إغلاقه.', 'A worktop extension that works as a breakfast bar and separates the kitchen from the lounge without closing it.', 430000],
            'open' => ['مطبخ مفتوح عصري', 'Modern open-plan kitchen', 'مطبخ مفتوح على الصالة بواجهات أكريليك مطفية وأجهزة مدمجة بالكامل لإطلالة موحدة.', 'An open-plan kitchen with matte acrylic fronts and fully integrated appliances for a seamless look.', 560000],
            'prep' => ['مطبخ تحضيري ثانٍ', 'Secondary prep kitchen', 'مطبخ خلفي للتحضير والضيافة بجانب المطبخ الرئيسي، بخامات مقاومة للرطوبة والحرارة.', 'A back kitchen for prep and hospitality next to the main kitchen, in moisture- and heat-resistant materials.', 310000],
        ];
        foreach ($rows as $key => [$ar, $en, $dAr, $dEn, $price]) {
            $product = $save->handle($owner, null, [
                'category' => 'مطابخ', 'name' => ['ar' => $ar, 'en' => $en], 'description' => ['ar' => $dAr, 'en' => $dEn],
                'pricing_basis' => 'linear_meter', 'reference_price_minor' => $price,
                'option_groups' => $groups($m('acrylic_gloss', 'acrylic_matte', 'beech', 'mdf_laminate'), $m('quartz_white', 'quartz_grey', 'marble_carrara', 'marble_black')),
            ]);
            app(SetCatalogStatus::class)->handle($owner, $product->refresh(), CatalogStatus::Active);
            $this->products[$key] = $product->refresh();
        }

        $pools = array_merge(...array_values(Assets::KITCHENS));
        $i = 0;
        foreach ($this->products as $product) {
            for ($n = 0; $n < 2; $n++) {
                $this->s->assets->attach($owner, $product, $pools[$i++ % count($pools)]);
            }
        }
    }

    private function ready(): void
    {
        $owner = $this->s->users['owner'];
        $saveProduct = app(SaveReadyProduct::class);
        $saveVariant = app(SaveVariant::class);
        $publish = app(SetCatalogStatus::class);

        /** @var array<string, list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: string, 3: int}>, 3: list<int>}>> $catalogue category => [[ar, en, [[code, ar, en, price SAR]...], image pool]] */
        $catalogue = [
            'handles' => [
                ['مقبض بار ألمنيوم', 'Aluminium bar handle', [['HND-BAR-128-BLK', 'أسود مطفي 128 مم', 'Matte black 128 mm', 28], ['HND-BAR-160-STL', 'ستانلس 160 مم', 'Steel 160 mm', 32], ['HND-BAR-224-GLD', 'ذهبي 224 مم', 'Gold 224 mm', 46]], Assets::HANDLES],
                ['مقبض كرة معدني', 'Metal knob', [['HND-KNB-BLK', 'أسود', 'Black', 14], ['HND-KNB-GLD', 'ذهبي', 'Gold', 18]], Assets::HANDLES],
                ['مقبض مخفي (بروفايل G)', 'Hidden G-profile pull', [['HND-PRF-G-2M', 'ألمنيوم 2.4 م', 'Aluminium 2.4 m', 85]], Assets::HANDLES],
                ['مقبض حلق للأدراج', 'Ring drawer pull', [['HND-RNG-BRS', 'نحاسي', 'Brass', 22], ['HND-RNG-BLK', 'أسود', 'Black', 20]], Assets::HANDLES],
            ],
            'hinges' => [
                ['مفصلة هيدروليك صامتة', 'Soft-close hinge', [['HNG-SC-110', 'زاوية 110°', '110° angle', 12], ['HNG-SC-165', 'زاوية 165°', '165° angle', 19]], Assets::DRAWERS],
                ['مفصلة فتح بالدفع', 'Push-to-open hinge', [['HNG-PO-110', 'قياسي', 'Standard', 17]], Assets::DRAWERS],
                ['مفصلة زاوية للأبواب المتجاورة', 'Corner blind hinge', [['HNG-CRN-45', '45°', '45°', 24]], Assets::DRAWERS],
                ['مفصلة الباب المنزلق للأجهزة', 'Appliance lift hinge', [['HNG-LFT-AV', 'رفع أمامي', 'Front lift', 38]], Assets::DRAWERS],
            ],
            'organizers' => [
                ['منظم أدوات المائدة', 'Cutlery organizer', [['ORG-CUT-60', 'عرض 60 سم', '60 cm wide', 79], ['ORG-CUT-90', 'عرض 90 سم', '90 cm wide', 109]], Assets::DRAWERS],
                ['رف توابل سحاب', 'Pull-out spice rack', [['ORG-SPC-15', '15 سم', '15 cm', 189], ['ORG-SPC-20', '20 سم', '20 cm', 219]], Assets::DRAWERS],
                ['حامل زجاجات سحاب', 'Pull-out bottle rack', [['ORG-BTL-30', '30 سم', '30 cm', 245]], Assets::DRAWERS],
                ['دوّار الزاوية', 'Corner carousel unit', [['ORG-CRS-80', '80 سم', '80 cm', 520]], Assets::DRAWERS],
                ['سلة مهملات سحاب مزدوجة', 'Double pull-out bin', [['ORG-BIN-45', 'سعة 2×15 لتر', '2×15 L', 349]], Assets::DRAWERS],
                ['حامل أطباق للخزانة', 'Cabinet dish rack', [['ORG-DSH-60', 'ستانلس 60 سم', 'Steel 60 cm', 165]], Assets::DRAWERS],
                ['حامل أدوات معلّق', 'Hanging utensil rail', [['ORG-RAIL-80', 'ستانلس 80 سم', 'Steel 80 cm', 95]], Assets::DRAWERS],
                ['فاصل أدراج قابل للتعديل', 'Adjustable drawer divider', [['ORG-DIV-50', 'خشب 50 سم', 'Wood 50 cm', 69]], Assets::DRAWERS],
            ],
            'mixers' => [
                ['خلاط مطبخ أحادي المقبض', 'Single-lever kitchen mixer', [['MIX-SL-CHR', 'كروم', 'Chrome', 289], ['MIX-SL-BLK', 'أسود مطفي', 'Matte black', 349], ['MIX-SL-GLD', 'ذهبي مفرّش', 'Brushed gold', 429]], Assets::FAUCETS],
                ['خلاط مطبخ برأس سحب', 'Pull-out kitchen mixer', [['MIX-PO-CHR', 'كروم', 'Chrome', 389], ['MIX-PO-STL', 'ستانلس', 'Stainless', 459]], Assets::FAUCETS],
                ['خلاط مطبخ عالي', 'High-arc kitchen mixer', [['MIX-HA-BLK', 'أسود مطفي', 'Matte black', 399]], Assets::FAUCETS],
                ['حوض مطبخ ستانلس مزدوج', 'Double-bowl steel sink', [['SNK-DBL-80', '80 سم', '80 cm', 649], ['SNK-DBL-100', '100 سم', '100 cm', 849]], Assets::FAUCETS],
                ['حوض جرانيت أسود مفرد', 'Black granite single sink', [['SNK-GRN-60', '60 سم', '60 cm', 749]], Assets::FAUCETS],
            ],
            'led' => [
                ['شريط LED أسفل الخزائن', 'Under-cabinet LED strip', [['LED-STR-WRM-3', 'أبيض دافئ 3 م', 'Warm white 3 m', 99], ['LED-STR-CLD-3', 'أبيض بارد 3 م', 'Cool white 3 m', 99], ['LED-STR-WRM-5', 'أبيض دافئ 5 م', 'Warm white 5 m', 149]], Assets::KITCHENS['dark']],
                ['إضاءة LED بحساس حركة', 'Motion-sensor LED light', [['LED-MOT-60', 'طول 60 سم', '60 cm', 79]], Assets::KITCHENS['dark']],
                ['محوّل طاقة للإضاءة 60 واط', '60 W LED driver', [['LED-DRV-60', '12 فولت', '12 V', 69]], Assets::KITCHENS['dark']],
                ['كشاف سبوت غاطس', 'Recessed spotlight', [['LED-SPT-WRM', 'أبيض دافئ', 'Warm white', 35], ['LED-SPT-NAT', 'أبيض طبيعي', 'Neutral white', 35]], Assets::KITCHENS['dark']],
            ],
            'drawers' => [
                ['مجرى أدراج صامت كامل السحب', 'Soft-close full-extension slide', [['DRW-SLD-45', '45 سم', '45 cm', 42], ['DRW-SLD-50', '50 سم', '50 cm', 48]], Assets::DRAWERS],
                ['نظام درج تاندم بوكس', 'Tandem-box drawer system', [['DRW-TBX-45', 'ارتفاع متوسط 45 سم', 'Mid height 45 cm', 135], ['DRW-TBX-50', 'ارتفاع متوسط 50 سم', 'Mid height 50 cm', 149]], Assets::DRAWERS],
                ['مجرى فتح بالدفع', 'Push-to-open slide', [['DRW-PSH-45', '45 سم', '45 cm', 58]], Assets::DRAWERS],
                ['درج داخلي للخزائن العلوية', 'Inner drawer for wall cabinets', [['DRW-INR-60', '60 سم', '60 cm', 99]], Assets::DRAWERS],
            ],
            'extras' => [
                ['أرجل خزائن قابلة للتعديل', 'Adjustable cabinet legs', [['EXT-LEG-100', 'ارتفاع 10 سم (طقم 4)', '10 cm (set of 4)', 29]], Assets::DRAWERS],
                ['مثبّت رف مخفي', 'Hidden shelf bracket', [['EXT-BRK-HID', 'طقم 2', 'Set of 2', 19]], Assets::DRAWERS],
                ['ماصّات صدمات للأبواب', 'Door soft-close dampers', [['EXT-DMP-8', 'طقم 8', 'Set of 8', 22]], Assets::DRAWERS],
                ['قاعدة سيليكون لحماية الأدراج', 'Drawer protection mat', [['EXT-MAT-50', '50 سم', '50 cm', 25]], Assets::DRAWERS],
            ],
        ];

        $ids = [];
        foreach ($catalogue as $category => $products) {
            foreach ($products as [$ar, $en, $variants, $pool]) {
                $product = $saveProduct->handle($owner, null, ['name' => ['ar' => $ar, 'en' => $en], 'description' => null]);
                $product->refresh();
                foreach ($variants as $n => [$code, $vAr, $vEn, $price]) {
                    $variant = $saveVariant->handle($owner, $product, null, [
                        'code' => $code, 'name' => ['ar' => $vAr, 'en' => $vEn], 'price_minor' => $price * 100, 'is_default' => count($variants) === 1,
                    ]);
                    $this->variants[] = $variant->refresh();
                    $this->byCategory[$category][] = $variant;
                }
                $publish->handle($owner, $product->refresh(), CatalogStatus::Published);
                foreach ($product->variants()->get() as $v) {
                    $publish->handle($owner, $v, CatalogStatus::Published);
                }
                $ids[] = $product->id;
                $this->s->assets->attach($owner, $product, $pool[count($ids) % count($pool)]);
            }
        }
        // Refresh the variants now that they are published.
        $this->variants = array_values(CatalogReadyVariant::query()->orderBy('id')->get()->all());
        $this->byCategory = [];
        foreach ($this->variants as $v) {
            $this->byCategory[$this->categoryOf($v->code)][] = $v;
        }
    }

    private function categoryOf(string $code): string
    {
        return match (substr($code, 0, 3)) {
            'HND' => 'handles', 'HNG' => 'hinges', 'ORG' => 'organizers', 'MIX', 'SNK' => 'mixers', 'LED' => 'led', 'DRW' => 'drawers', default => 'extras',
        };
    }
}
