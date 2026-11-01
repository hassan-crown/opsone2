<?php

namespace Database\Seeders\ShowcaseKit;

/**
 * Saudi names, neighbourhoods and company names for customers, contacts and interests. Mobile numbers are generated
 * from a counter (+9665 + 8 digits), so they are unique and never real numbers of a person.
 */
final class People
{
    private const MALE = ['محمد', 'عبدالله', 'فهد', 'سعود', 'خالد', 'سلطان', 'ناصر', 'عبدالعزيز', 'تركي', 'بندر', 'فيصل', 'ماجد', 'سلمان', 'نواف', 'راشد', 'متعب', 'عادل', 'أحمد', 'يوسف', 'إبراهيم', 'عمر', 'حمد', 'وليد', 'طلال', 'مشاري', 'منصور', 'علي', 'صالح', 'هشام', 'زياد'];

    private const FEMALE = ['نورة', 'سارة', 'ريم', 'لمى', 'هند', 'منيرة', 'العنود', 'أمل', 'مها', 'جواهر', 'شهد', 'دانة', 'ليان', 'غادة', 'رغد', 'هيفاء', 'أسماء', 'عبير', 'سلمى', 'وفاء'];

    private const FAMILY = ['العتيبي', 'القحطاني', 'الدوسري', 'الشمري', 'المطيري', 'الحربي', 'الغامدي', 'الزهراني', 'العنزي', 'السبيعي', 'الشهري', 'البقمي', 'الرشيد', 'الصالح', 'الخالدي', 'العمري', 'الجهني', 'القرني', 'الأحمدي', 'المالكي', 'التميمي', 'الراجحي', 'السديري', 'الفوزان', 'الحازمي', 'البلوي', 'العصيمي', 'اليامي', 'الحمدان', 'باعشن', 'آل سعد', 'آل مبارك'];

    /** @var array<string, list<string>> */
    public const DISTRICTS = [
        'RUH' => ['النرجس', 'الياسمين', 'الملقا', 'حطين', 'العارض', 'القيروان', 'الصحافة', 'الرمال', 'المونسية', 'قرطبة', 'الندى', 'الخزامى'],
        'JED' => ['الشاطئ', 'الروضة', 'النعيم', 'أبحر الشمالية', 'الحمراء', 'الصفا', 'المحمدية', 'الزهراء', 'السلامة', 'ذهبان'],
        'DMM' => ['الشاطئ', 'الفيصلية', 'الريان', 'الجلوية', 'النزهة', 'الأمانة', 'الفناتير', 'الواحة', 'الراكة'],
    ];

    public const CITY = ['RUH' => 'الرياض', 'JED' => 'جدة', 'DMM' => 'الدمام'];

    private int $mobile = 10_000_000;

    public function __construct(private readonly Rng $rng) {}

    public function mobile(): string
    {
        $this->mobile += $this->rng->int(7, 997);

        return '+9665'.$this->prefix().str_pad((string) ($this->mobile % 10_000_000), 7, '0', STR_PAD_LEFT);
    }

    private function prefix(): string
    {
        return $this->rng->pick(['0', '3', '5', '0', '5', '9']);
    }

    /**
     * @return array{0: string, 1: bool} full name, is female
     */
    public function person(): array
    {
        $female = $this->rng->chance(35);
        $first = $this->rng->pick($female ? self::FEMALE : self::MALE);
        $father = $this->rng->pick(self::MALE);

        return [$first.' '.$father.' '.$this->rng->pick(self::FAMILY), $female];
    }

    /**
     * @return array{name: string, kind: string}
     */
    public function company(): array
    {
        $family = $this->rng->pick(self::FAMILY);
        $kinds = [
            ['شركة %s للتطوير العقاري', 'developer'], ['مؤسسة %s للمقاولات العامة', 'contractor'], ['شركة %s للمقاولات والإنشاءات', 'contractor'],
            ['مكتب %s للتصميم الداخلي', 'design'], ['شركة %s للاستثمار العقاري', 'developer'], ['مؤسسة %s للديكور والتشطيبات', 'contractor'],
            ['شركة %s للمشاريع السكنية', 'developer'],
        ];
        [$pattern, $kind] = $this->rng->pick($kinds);

        return ['name' => sprintf($pattern, $family), 'kind' => $kind];
    }

    public function email(string $local, int $n): string
    {
        return 'cust'.$n.'@mail-demo.test';
    }

    public function district(string $branch): string
    {
        return $this->rng->pick(self::DISTRICTS[$branch]);
    }

    public function crNumber(): string
    {
        return (string) (1010000000 + $this->rng->int(100000, 899999));
    }

    public function vatNumber(): string
    {
        return '3'.str_pad((string) $this->rng->int(0, 9999999999999), 13, '0', STR_PAD_LEFT).'3';
    }

    /**
     * @return list<string> need texts of the kitchen interests
     */
    public static function needs(): array
    {
        return [
            'أرغب بتفصيل مطبخ جديد لفيلا قيد الإنشاء، بتصميم عصري وجزيرة وسطية.',
            'شقة جديدة وأحتاج مطبخًا على شكل L بألوان فاتحة وسطح كوارتز.',
            'تجديد المطبخ الحالي بالكامل مع تغيير الواجهات والأسطح وإضافة إضاءة مخفية.',
            'مطبخ مفتوح على الصالة بواجهات أكريليك مطفية وأجهزة مدمجة.',
            'مطبخ ضيق ٣ أمتار أحتاج تصميمًا ذكيًا يستغل المساحة للتخزين.',
            'أحتاج مطبخًا رئيسيًا ومطبخ تحضير ثانيًا لمنزل العائلة.',
            'مطبخ لشقة للإيجار، أريد خامات عملية وسعرًا مناسبًا.',
            'زيارة المعرض وأعجبني التصميم الخشبي، أريد عرض سعر لمطبخ بمقاسات ٤×٣ م.',
            'تصميم مطبخ على شكل U لدور كامل في فيلا ونرغب بقياس الموقع قريبًا.',
            'مطبخ مع بار للإفطار وسطح رخام أبيض وإضاءة LED تحت الخزائن.',
            'أبحث عن تركيب مطبخ مع خزائن حتى السقف ومخزن مؤن.',
            'نموذج مطبخ لمشروع من ٦ وحدات سكنية، ونحتاج تسعيرًا للكمية.',
            'تعديل مطبخ قديم: واجهات جديدة ومفصلات صامتة ومنظمات أدراج.',
        ];
    }
}
