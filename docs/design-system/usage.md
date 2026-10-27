# دليل استخدام نظام التصميم (المرحلة P1)

## الملفات
- `scripts/design-tokens.mjs` يولّد `src/resources/css/opsone/tokens.css` من `docs/design-system/source/tokens.json` (`node scripts/design-tokens.mjs`، و`--check` للفحص). لا تُعدَّل `tokens.css` يدويًا.
- `src/resources/css/app.css`: `@theme` وربط متغيرات shadcn بأدوار النظام. `opsone/base.css`: سلّما الخط حسب `data-surface` وأداة `field-control`.
- الخطوط في `src/resources/fonts` (IBM Plex، رخصة OFL.txt بجانبها، بلا تعديل).
- المكونات المخصصة في `resources/js/components/opsone/`، وإعادة تنسيق shadcn في `components/ui/`.

## أسماء الأدوات (Tailwind)
- ألوان: `bg-surface|raised|fill|hover|brand|brand-subtle|danger|*-bg`، نص: `text-strong|weak|placeholder|brand|danger-text|success-text…`، حدود: `border-stroke-weak|stroke|stroke-control`.
- نصوص: `text-title|section|subtitle|body|control|label|caption|ref|display` (تتبع `data-surface`). تُمنع `text-sm|lg…` و`tracking-*` و`italic`.
- زوايا: `rounded-sm|md|lg|full` (6/8/12). ظل: `shadow-raised` للنوافذ والقوائم فقط.
- اتجاه: خصائص منطقية فقط (`ms/me/ps/pe/start/end/inset-s/inset-e/text-start/border-s`). الأيقونات الاتجاهية عبر `Icon`.

## الاتجاه واللغة
- `SetLocale` (المستخدم ← كوكي `locale` ← `APP_LOCALE`) يضبط `<html lang dir>`؛ التبديل بطلب Inertia كامل `PUT /locale`.
- نصوص الواجهة في `src/lang/{ar,en}.json` (مفاتيح مسطحة) و`useT()` مضبوط الأنواع من `en.json`. اختبار يضمن تطابق المفتاحين.
- `Amount` و`formatAmount` المصدر الوحيد للعملة («ر.س» بعد الرقم / `SAR` قبله)؛ الأرقام لاتينية دائمًا.

## الثيم
- `light|dark|system` (+ `contrast` في الرموز بلا زر بعد). على الجذر: `data-theme` وفئة `.dark` معًا.

## قرارات افتراضية قابلة للتغيير
`select`/`checkbox`/`radio` أصلية، وRadix لـ Dialog وDropdownMenu وTooltip وTabs وSwitch؛ بلا حركة دخول؛ ألوان الرسوم مؤجلة (`--chart-*` مؤقتة).
