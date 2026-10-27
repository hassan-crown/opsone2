# دمج نظام تصميم OpsOne في التطبيق

> الحالة: **خطة معتمدة للتنفيذ بعد قرارات المستخدم** (انظر «أسئلة مفتوحة»). لم يُعدَّل أي ملف تحت `src/` بعد.
> المصدر: نظام OpsOne على claude.ai (نسخة `1793085078-55b5`)، والنسخة الخام في `docs/design-system/source/` (انظر `SOURCE.md`).
> المصدر الوحيد للقيم هو `docs/design-system/source/tokens.json`. أي قيمة في هذا الملف مأخوذة منه؛ عند التعارض يُعتمد `tokens.json`.

## 1. الخلاصة

- النظام مكتمل في الرموز (72 لونًا بثلاثة ثيمات، سلّما خط، 10 مسافات، 4 زوايا، ظل واحد، مقاسات، نقطتا كسر، طبقتان) و39 مكوّنًا موثقًا بمصدر React 18.
- لا نستورد حزمته (`bundle.js` IIFE على `window.React` وReact 18). **نعيد بناء المكونات بـ TypeScript فوق shadcn/ui الموجود في starter kit**، ونستخدم `bundle.css` و`components/src/*.jsx` مرجعًا للسلوك والقيم فقط.
- الرموز تدخل `src/resources/css/app.css` كمتغيرات CSS، وتُربط بأسماء shadcn (`--primary`، `--border`…) فتعمل مكونات shadcn الحالية بالهوية الجديدة دون إعادة كتابة.
- الاتجاه من اللغة على `<html>`، والخصائص المنطقية فقط، و`DirectionProvider` لمكونات Radix.
- الثيمات: `light` / `dark` / `contrast` / `system` بتوسيع `use-appearance` الموجود.
- ثيم Alpha للمستأجر: يتغيّر فقط لون الإجراء ومشتقاته، محسوبًا في الخادم مع تحقق تباين، ومحصورًا بالموقع ومساحة العميل.

## 2. الوضع الحالي في `src/` (قراءة فقط، 2026-10-27)

| البند | القيمة الحالية | الأثر |
| --- | --- | --- |
| `components.json` | shadcn `new-york`، `baseColor: neutral`، `iconLibrary: lucide` | نُبقيه؛ نستبدل الألوان المحايدة |
| `resources/css/app.css` | `@theme` بأسماء shadcn، `--radius: 0.625rem`، `@custom-variant dark (&:is(.dark *))`، خط Instrument Sans | يُعاد كتابة جزء الرموز |
| `resources/views/app.blade.php` | `lang` من `app()->getLocale()`، فئة `dark` من كوكي `appearance`، سكربت مضمّن | نضيف `dir` و`data-theme` و`data-surface` |
| `hooks/use-appearance.tsx` | `light / dark / system` بفئة `.dark` | نضيف `contrast` |
| مكونات `components/ui` | alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, input, label, navigation-menu, select, separator, sheet, sidebar, skeleton, sonner, spinner, toggle(-group), tooltip | نعيد تنسيقها |
| الاعتمادات | `lucide-react ^0.475`، `@radix-ui/*`، `sonner ^2`، `tailwindcss ^4`، `tw-animate-css` | لا مكتبات UI جديدة؛ إضافة `@radix-ui/react-direction` فقط بعد التحقق من النسخة |
| السكربتات | `build: vp build`، `types:check: tsc --noEmit` | للتحقق |

> جلسة project-init قد تغيّر هذه الملفات؛ أعد قراءتها قبل التنفيذ.

## 3. الرموز (Tokens)

### 3.1 البنية

```
src/resources/css/
  app.css                 ← يستورد الملفات أدناه + @theme + ربط shadcn
  opsone/fonts.css        ← @font-face
  opsone/tokens.css       ← متغيرات الأدوار لكل ثيم (مولّد من tokens.json)
  opsone/base.css         ← body، سلّما الخط حسب data-surface، focus، reduced-motion، forced-colors
  opsone/tenant.css       ← (اختياري) قيم Alpha الافتراضية؛ القيم الفعلية تُحقن من الخادم
src/resources/fonts/      ← ملفات woff2 + OFL.txt
```

**توليد `tokens.css`:** سكربت صغير `scripts/design-tokens.mjs` في جذر المستودع (أو `src/scripts/`، حسب قرار البنية) يقرأ `docs/design-system/source/tokens.json` ويكتب `opsone/tokens.css`. يُشغّل يدويًا عند تحديث النظام، ويُضاف فحص في CI يقارن الناتج. البديل المقبول للمرحلة الأولى: كتابته يدويًا مرة واحدة ومقارنته بالسكربت لاحقًا.

قواعد التحويل:
- `color.tokens[].value` نص → قيمة `:root`؛ كائن → `light` في `:root`، و`dark` في `.dark`، و`contrast` في `[data-theme="contrast"]`. **الثيم الذي لا قيمة له يرث `light`** (هكذا يعرّف النظام الوراثة؛ `contrast` له 26 قيمة فقط من 72).
- الاسم المستعار `"{background}"` → `var(--background)`.
- اللوحات الخام (`teal-*`, `ink-*`, `amber-*`) تُكتب في `:root` لكن **لا تُعرَّض كأدوات Tailwind** (لا `--color-teal-600` في `@theme`)، حتى لا تُستخدم مباشرة. الاستثناء: الشعار يحتاج `teal-600/400` و`ink-900/25`.
- `shadow-raised` له قيمتان (فاتح/داكن).

### 3.2 الألوان → Tailwind و shadcn

أدوات Tailwind باسم الدور (في `@theme inline`):

```css
@theme inline {
  --color-background: var(--background);
  --color-surface: var(--surface);
  --color-raised: var(--raised);
  --color-fill: var(--fill);
  --color-hover: var(--hover);
  --color-field: var(--field);
  --color-overlay: var(--overlay);
  --color-text-strong: var(--text-strong);   /* text-text-strong — أو اسم مختصر: --color-strong */
  --color-text-weak: var(--text-weak);
  --color-text-placeholder: var(--text-placeholder);
  --color-stroke-weak: var(--stroke-weak);
  --color-stroke: var(--stroke);
  --color-stroke-control: var(--stroke-control);
  --color-brand: var(--brand);  /* + brand-hover, brand-active, brand-subtle, brand-subtle-text, on-brand */
  --color-link: var(--link);
  --color-focus: var(--focus);
  --color-focus-halo: var(--focus-halo);
  --color-highlight: var(--highlight); /* + on-highlight, highlight-text */
  --color-success-text: var(--success-text); --color-success-bg: var(--success-bg);
  /* warning-*, danger-*, info-*, neutral-*, danger, danger-hover, on-danger,
     disabled-bg, disabled-text, tooltip-bg, on-tooltip, switch-thumb, skeleton */
}
```

> لتجنب `text-text-strong` يُقترح الاسمان `--color-strong` و`--color-weak` و`--color-placeholder` مع إبقاء متغيرات CSS بأسمائها الأصلية. القرار للمنفّذ، ويُوثّق في `docs/design-system/usage.md`.

ربط متغيرات shadcn بالأدوار (في `:root` مرة واحدة؛ تتبع الثيم تلقائيًا لأن الأدوار هي التي تتغير):

| shadcn | الدور في OpsOne | ملاحظة |
| --- | --- | --- |
| `--background` | `background` | الاسم نفسه؛ لا يُعاد تعريفه |
| `--foreground` | `text-strong` | |
| `--card` / `--card-foreground` | `surface` / `text-strong` | |
| `--popover` / `--popover-foreground` | `raised` / `text-strong` | مع `shadow-raised` |
| `--primary` / `--primary-foreground` | `brand` / `on-brand` | |
| `--secondary` / `--secondary-foreground` | `surface` / `text-strong` | الزر الثانوي بحد `stroke-control` |
| `--muted` / `--muted-foreground` | `fill` / `text-weak` | |
| `--accent` / `--accent-foreground` | `hover` / `text-strong` | المرور في القوائم |
| `--destructive` / `--destructive-foreground` | `danger` / `on-danger` | |
| `--border` | `stroke-weak` | |
| `--input` | `stroke-control` | |
| `--ring` | `focus` | |
| `--sidebar` / `--sidebar-foreground` | `surface` / `text-weak` | |
| `--sidebar-primary` / `-foreground` | `brand` / `on-brand` | |
| `--sidebar-accent` / `-foreground` | `brand-subtle` / `brand-subtle-text` | العنصر المحدد |
| `--sidebar-border` / `--sidebar-ring` | `stroke-weak` / `focus` | |
| `--chart-1..5` | `brand`، `info-text`، `highlight`، `success-text`، `neutral-text` | **غير معرّفة في النظام** — مؤقتة، انظر الفجوات |

### 3.3 الخطوط

- النسخ: `docs/design-system/source/fonts/*.woff2` → `src/resources/fonts/` (11 ملفًا، ≈ 730KB؛ يُحمَّل فعليًا ما تحتاجه الصفحة فقط). تُضاف `OFL.txt` بجانبها.
- `@font-face` في `opsone/fonts.css` بمسار نسبي (`url('../../fonts/IBMPlexSansArabic-Regular.woff2')`) فيعالجها Vite ويضيف بصمة الكاش. `font-display: swap`. **لا** `public/fonts` (بلا بصمة كاش).
- لا تُقطَّع الخطوط (subset) ولا تُعدَّل: رخصة OFL تحجز الاسم «Plex»، والنسخة المعدّلة لا يجوز أن تحمل الاسم.
- اختياري: `<link rel="preload">` لملف Arabic Regular عبر `Vite::asset()` في `app.blade.php`.

```css
@theme {
  --font-sans: "IBM Plex Sans Arabic", "IBM Plex Sans", system-ui, sans-serif;
  --font-latin: "IBM Plex Sans", "IBM Plex Sans Arabic", system-ui, sans-serif;
  --font-mono: "IBM Plex Mono", ui-monospace, "SF Mono", Menlo, monospace;
}
:root:lang(en) body, [lang|="en"] { font-family: var(--font-latin); }
[lang|="ar"] { font-family: var(--font-sans); }
```

يُزال Instrument Sans واستدعاؤه من Bunny Fonts في `app.blade.php` (إن وُجد).

### 3.4 سلّما الخط حسب السطح

نعرّف متغيرات دلالية تتبدل مع `data-surface`، ونعرّضها كأدوات Tailwind:

```css
:root {            /* panel — الافتراضي */
  --o1-fs-title: 24px; --o1-lh-title: 36px;   /* 600 */
  --o1-fs-section: 20px; --o1-lh-section: 30px;
  --o1-fs-subtitle: 16px; --o1-lh-subtitle: 26px;
  --o1-fs-body: 14px; --o1-lh-body: 24px;
  --o1-fs-control: 15px; --o1-lh-control: 24px;
  --o1-fs-label: 14px; --o1-lh-label: 20px;
  --o1-fs-caption: 13px; --o1-lh-caption: 20px;
}
[data-surface="site"] { /* 32/44 · 24/36 · 20/30 · 16/28 · 16/24 · 14/20 · 14/22 */ }

@theme inline {
  --text-title: var(--o1-fs-title);  --text-title--line-height: var(--o1-lh-title);  --text-title--font-weight: 600;
  --text-section: …; --text-subtitle: …; --text-body: …; --text-control: …; --text-label: …; --text-caption: …;
  --text-display: 40px; --text-display--line-height: 56px; --text-display--font-weight: 700; /* site فقط */
  --text-ref: 14px; --text-ref--line-height: 20px;
}
```

- عنوان الصفحة في الموقع وزنه 700 (`site-title`) وفي اللوحة 600: متغير `--o1-fw-title` يتبدل مع السطح.
- قواعد: لا `tracking-*` سالب مع العربية، `leading` ≥ 1.6 للنص، لا `italic` ولا `font-light`.
- تُمنع أحجام Tailwind الخام (`text-sm`, `text-lg`…) في الشاشات الجديدة بقاعدة مراجعة (وبقاعدة lint لاحقًا إن أمكن).

### 3.5 المسافات والزوايا والظلال والمقاسات

- **المسافات:** سلم Tailwind 4 الافتراضي (`--spacing: 0.25rem` = 4px) يطابق `space-N` حرفيًا (`p-1`=4 … `p-16`=64). نُبقيه. المسموح فقط: 1، 2، 3، 4، 5، 6، 8، 10، 12، 16 (والكسور `0.5` للحدود الدقيقة عند الضرورة). قاعدة مراجعة تمنع 7، 9، 11، 14… والقيم العشوائية `p-[13px]`.
- **الزوايا:** يجب استبدال حساب shadcn (`--radius-md: calc(var(--radius) - 2px)`) بقيم النظام صراحة، وإلا تختلف عن المرجع:
  ```css
  @theme { --radius-sm: 6px; --radius-md: 8px; --radius-lg: 12px; --radius-full: 9999px; }
  :root { --radius: 12px; } /* لما بقي من مكونات shadcn تقرأ --radius */
  ```
  ثم مراجعة كل مكون shadcn: الأزرار والحقول والقوائم `rounded-md`، البطاقات والنوافذ والجداول `rounded-lg`، الشارات `rounded-sm`.
- **الظل:** `--shadow-raised` (فاتح/داكن) كأداة `shadow-raised`؛ وتُصفَّر ظلال shadcn على البطاقات والأزرار والحقول (`shadow-xs`/`shadow-sm`) لأن «حدود لا ظلال».
- **المقاسات:** `--control-height: 44px` (`h-11`)، `--control-height-sm: 36px` (`h-9`)، `--icon-size: 20px` (`size-5`)، `--icon-size-sm: 16px`، `--sidebar-width: 264px`، `--topbar-height: 64px`، `--content-max: 1200px`، `--reading-max: 720px` → `@theme { --container-content: 1200px; --container-reading: 720px; }` لاستخدام `max-w-content`.
- **نقاط الكسر:** `md` 768 و`lg` 1024 تطابق Tailwind الافتراضي. لا نضيف غيرها.
- **الطبقات:** `z-10` (`z-sticky`) و`z-40` (`z-overlay`) موجودتان في Tailwind.

### 3.6 الحركة

غير موجودة في `tokens.json`؛ مأخوذة من README و`bundle.css`:

```css
@theme { --ease-o1: cubic-bezier(0, 0, 0.58, 1); --default-transition-duration: 120ms; }
```

- تغيّر الألوان 120ms `ease-out`، امتلاء التقدم 200ms، المؤشر الدائري 800ms خطي، النبض والتقدم غير المحدد 1.4s.
- **لا حركة دخول/خروج للنوافذ والقوائم والتلميحات**: تُحذف أصناف `data-[state=open]:animate-in`، `fade-in-0`، `zoom-in-95`، `slide-in-from-*` من dialog وdropdown-menu وtooltip وsheet وselect. (قرار مطلوب حول الدُّرج على الجوال — انظر الأسئلة.)
- `@media (prefers-reduced-motion: reduce)`: إيقاف النبض والتقدم غير المحدد وإبطاء المؤشر (2.4s).

## 4. الاتجاه RTL واللغة

1. **الخادم مصدر الحقيقة:** middleware `SetLocale` يقرأ اللغة (مسار/كوكي/تفضيل المستخدم — قرار منفصل في ميزة i18n)، ويشارك Inertia `locale` و`dir` في `HandleInertiaRequests`.
2. **`app.blade.php`:**
   ```blade
   <html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
         data-theme="{{ $theme }}" @class(['dark' => $theme === 'dark'])>
   <body data-surface="{{ $surface ?? 'panel' }}">
   ```
   تغيير اللغة يعيد تحميل الصفحة كاملة (أبسط وأضمن للنصوص والخط). إن اختير التبديل دون تحميل: دالة `setLanguage()` تضبط `lang` و`dir` على `document.documentElement`.
3. **Radix:** لفّ التطبيق في `app.tsx` بـ `<DirectionProvider dir={dir}>` (حزمة `@radix-ui/react-direction`، تحقق من النسخة المتوافقة مع React 19 قبل الإضافة) حتى تعمل أسهم Tabs وDropdownMenu وSlider بالاتجاه الصحيح.
4. **الخصائص المنطقية فقط:** `ms-*/me-*`, `ps-*/pe-*`, `start-*/end-*`, `text-start/end`, `border-s/e`, `rounded-s/e`. مراجعة مكونات shadcn الموجودة لاستبدال `ml/mr/pl/pr/left/right/text-left/text-right` (أمثلة معروفة: زر الإغلاق في dialog وsheet `right-4`، سهم القائمة الفرعية في dropdown-menu `ml-auto`، `side="left"` في sidebar وsheet).
5. **القائمة الجانبية:** `side={dir === 'rtl' ? 'right' : 'left'}`، ونقطة تحولها إلى دُرج **1024px** (النظام) لا 768 (`use-mobile.tsx` في shadcn) — يُضاف ثابت منفصل `useIsBelowLg` حتى لا يتأثر غيره.
6. **الأيقونات الاتجاهية** تُعكس بـ `rtl:-scale-x-100` داخل مكوّن `Icon`: `chevron-left/right`, `arrow-left/right`, `log-out`, `external-link`, `trending-up/down`. الشعار والأرقام والساعات لا تُعكس.
7. **المحتوى المختلط:** `Ref` (`<bdi dir="ltr">` بخط mono)، `Amount` (أرقام لاتينية `tabular-nums`، العملة بعد الرقم في العربية «ر.س» وقبله في الإنجليزية «SAR»)، حقول الجوال/البريد/الآيبان/المبالغ `dir="ltr"` مع `inputMode` و`autoComplete`. `Intl.NumberFormat('en-US')` للأرقام في الواجهتين.
8. **Sonner:** `dir` من اللغة و`position="bottom-left"` في RTL و`bottom-right` في LTR (= نهاية السطر)، وبعرض الشاشة على الجوال.
9. **الترجمة:** نصوص المكونات الداخلية (جارٍ التحميل، إغلاق، السابق/التالي، تخطَّ إلى المحتوى…) تُنقل من `STRINGS` في `components/src/opsone.jsx` إلى ملفات `lang/ar|en` أو قاموس الواجهة المعتمد، مع مسرد المصطلحات الثابت من `languages.md`.

## 5. الثيمات: فاتح، داكن، تباين عالٍ

- يُوسَّع `use-appearance.tsx` إلى `'light' | 'dark' | 'contrast' | 'system'` مع الكوكي `appearance` نفسه (لا كسر للموجود).
- `system`: داكن إن `prefers-color-scheme: dark`، وتباين عالٍ إن `prefers-contrast: more` وغير داكن، وإلا فاتح (نفس منطق `setTheme` في المرجع).
- على الجذر: فئة `.dark` تبقى (يعتمد عليها `@custom-variant dark` ومكونات shadcn) **و** `data-theme="light|dark|contrast"` دائمًا. CSS:
  ```css
  :root { /* light */ color-scheme: light; }
  .dark { /* dark */ color-scheme: dark; }
  [data-theme="contrast"] { /* contrast overrides */ }
  @custom-variant contrast (&:is([data-theme="contrast"] *));
  ```
- السكربت المضمّن في `app.blade.php` يُحدَّث ليضبط `data-theme` قبل الرسم (منع الوميض)، ويُحدَّث نمط `html.dark { background-color }` الموجود فيه إلى قيم `background` الجديدة (`#FFFFFF` / `#08121F`).
- إضافات ثيم التباين من `bundle.css`: حدود للشارات والتنبيهات، `outline-width: 3px` للتركيز، إطار داخلي للعنصر المحدد في القائمة.
- `forced-colors: active`: حدود نظامية للأزرار والشارات والتنبيهات.
- مبدّل الثيم في صفحة إعدادات المظهر الموجودة (`settings/appearance`) يُضاف إليه خيار «تباين عالٍ».

## 6. ثيم Alpha للمستأجر

النطاق: **الموقع العام ومساحة العميل فقط** (`data-surface="site"`). لوحة الموظفين (والإدارة) تبقى بهوية OpsOne. (مع نموذج «مثيل لكل مستأجر» المعتمد، إعدادات المستأجر هي إعدادات هذا المثيل.)

ما يخصصه المستأجر: الشعار، المفضلة، **لون واحد**، وضع العرض الافتراضي.

### 6.1 اشتقاق اللوحة (في الخادم)

خدمة `App\Support\Theme\BrandPalette` (PHP، بلا مكتبة خارجية؛ تحويل sRGB ↔ OKLCH بمعادلات معروفة):

1. المدخل: لون hex واحد (Form Request: `regex:/^#[0-9a-fA-F]{6}$/`).
2. التحويل إلى OKLCH، وإبقاء درجة اللون (H) والتشبع (C، بسقف لتجنب الألوان الفاقعة)، وتوليد درجات بإضاءة L ثابتة تحاكي سلم `teal-50…950` في النظام.
3. ربط الأدوار بنفس مواقعها في سلّم teal الأصلي:

| الدور | فاتح | داكن | تباين عالٍ |
| --- | --- | --- | --- |
| `brand` | 600 | 400 | 800 |
| `brand-hover` | 700 | 300 | 900 |
| `brand-active` | 800 | 200 | 950 |
| `brand-subtle` | 50 مخففة | 950 مخففة | — |
| `brand-subtle-text` | 700+ | 300 | 900 |
| `focus` | = brand | = brand | = brand |
| `focus-halo` | 200 | 800 | 200 |
| `link` | = brand | = brand | = brand |
| `on-brand` | أبيض أو `ink-900` | `teal-950` المشتق | أبيض |

4. **حارس التباين:** يُحسب WCAG لكل زوج (`on-brand`/`brand` ≥ 4.5، `brand` كنص على `surface` ≥ 4.5، `focus` على `background` ≥ 3، `brand-subtle-text`/`brand-subtle` ≥ 4.5؛ و7:1 في `contrast`). إن فشل زوج تُحرَّك L خطوةً خطوة حتى ينجح؛ وإن تعذّر يُرفض اللون برسالة («هذا اللون فاتح جدًا ليُقرأ عليه النص. اختر درجة أغمق.») ويُعرض في المعاينة.
5. التخزين: اللون الأصلي + اللوحة المشتقة (JSON) + نسخة الخوارزمية. تُعاد الحسبة عند تغيّر الخوارزمية.
6. الحقن: Blade يكتب `<style nonce>` صغيرًا لصفحات الموقع فقط:
   ```css
   [data-surface="site"] { --brand: …; --brand-hover: …; … }
   .dark [data-surface="site"], .dark[data-surface="site"] { … }
   [data-theme="contrast"] [data-surface="site"] { … }
   ```
   (`data-surface` على `body`، فالمحددات تشمل الحالتين.) القيم hex فقط، مولدة من الخادم، لا نص من المستخدم داخل CSS.
7. **ثابت لا يتغير:** المحايدات (`ink`)، الحالات، `highlight` (الكهرماني)، الخطوط، المسافات، الزوايا. هكذا يبقى Alpha «OpsOne بلون المستأجر» لا ثيمًا حرًا.

### 6.2 الشعار والمفضلة

- رفع عبر Form Request: PNG/WebP/SVG للشعار، PNG/ICO/SVG للمفضلة؛ حدود حجم وأبعاد. **SVG يُنظَّف** (إزالة `script`، معالجات الأحداث، المراجع الخارجية) أو يُرفض — قرار مطلوب. يُخزن في قرص `public` بأسماء مولدة.
- شعار للفاتح وآخر اختياري للداكن؛ إن لم يُرفع الداكن يُعرض الفاتح على خلفية `surface`.
- إن لم يرفع المستأجر شعارًا: اسمه نصًا بنمط `site-subtitle` (لا شعار OpsOne في موقع المستأجر — قرار مطلوب).
- **«بواسطة OpsOne»** في التذييل بشعار `quiet` — قرار مطلوب.

### 6.3 وضع العرض الافتراضي

`default_appearance` للمستأجر (`light|dark|system`) يُستخدم حين لا يوجد كوكي `appearance` لدى الزائر.

## 7. الأيقونات

- **Lucide عبر `lucide-react` الموجود** (`^0.475`). النظام يذكر «Lucide v1.49.0»: يجب التحقق من رقم النسخة ومقارنة أسماء الأيقونات الـ52 (كلها موجودة بأسمائها الحالية: `triangle-alert`, `circle-alert`, `circle-check`, `circle-x`, `ellipsis`, `trash-2`…).
- مكوّن `resources/js/components/opsone/icon.tsx`: يقبل `name: IconName` (اتحاد الأسماء الـ52 من `index.d.ts`) أو مكوّن Lucide، الحجم 20 افتراضيًا (16 للصغير)، `strokeWidth={1.75}`، `aria-hidden` إلا مع `label`، وعكس الاتجاهية في RTL. استيراد مسمّى (tree-shaking)، لا `import *`.
- خريطة الأفكار الثابتة: الطلبات `clipboard-list`، العملاء `users`، المواعيد `calendar`، الفواتير `receipt`، التصدير `download`.
- الموجود `components/ui/icon.tsx` يُراجع ويُوحَّد مع هذا المكوّن.

## 8. الشعار وأيقونة التطبيق

- **الملفات الأصلية غير متاحة لنا حاليًا**: محفوظة في مخزن أصول النظام (12 ملف شعار، 7 أيقونات تطبيق/مفضلة)، والجلب يتطلب صلاحية تحرير. المطلوب من المستخدم: تصدير الملفات أو منح صلاحية التحرير (انظر الأسئلة).
- الوجهات بعد الحصول عليها:
  - `src/public/favicon.svg`, `favicon-32.png`, `favicon-16.png`, `apple-touch-icon-180.png` (تستبدل الموجودة، ووسوم `<link>` في `app.blade.php`).
  - `src/resources/images/brand/` لبقية ملفات الشعار (يعالجها Vite) — للبريد والمستندات النسخ PNG 1200/512.
- مكوّن `Logo` في React: يرسم **المسارات الأصلية نفسها** من `source/components/src/logo.js` (منسوخة حرفيًا من `opsone-logo.svg`، viewBox `0 0 262.11 64`، العلامة أول 7 أشكال `0 0 64 64`)، بتعبئات `teal-600`/`ink-900` في الفاتح و`teal-400`/أبيض/`ink-25` في الداكن. هذا نقل للملف الأصلي لا إعادة رسم؛ ومع ذلك يُطابق بصريًا مع ملف SVG الأصلي حين يتوفر.
- قواعد: `full` 28px في رأس القائمة الجانبية و32px في رأس الموقع، `mark` ≥ 24px، فراغ حوله ≥ ربع ارتفاع العلامة، لا انعكاس في RTL، لا ترجمة للاسم، لا إعادة تلوين.
- مكوّن `app-logo.tsx` و`app-logo-icon.tsx` الحاليان في starter kit يُستبدلان بالمكوّن الجديد.

## 9. المكونات: الخريطة إلى shadcn/ui

المفتاح: **إعادة تنسيق** = ملف shadcn الموجود في `components/ui` يُعدَّل بقيم النظام؛ **إضافة + تنسيق** = `shadcn add` ثم تعديل؛ **مخصص** = مكوّن جديد في `components/opsone/` يبني على عناصر أصلية أو على shadcn.

| مكوّن النظام | المقابل | القرار | ملاحظات التنفيذ |
| --- | --- | --- | --- |
| Button | `ui/button` | إعادة تنسيق | variants: `primary`(default)·`secondary`(outline)·`ghost`·`danger`(destructive)؛ احذف `link`؛ size `md` 44 / `sm` 36 / `icon`؛ أضف `loading` (`aria-busy`) و`aria-disabled` بدل `disabled` و`block`؛ أيقونة بداية/نهاية |
| Badge | `ui/badge` | إعادة تنسيق | tones: success·warning·danger·info·neutral·brand؛ `rounded-sm`، min-h 28 |
| Counter | — | مخصص | دائرة `highlight` + `on-highlight`، `99+`، `label` مقروء |
| Alert | `ui/alert` | إعادة تنسيق | 4 tones، أيقونة لكل حالة، `role=alert` للخطأ و`status` لغيره، `action`، `onDismiss` |
| Field / TextField | `ui/input` + `ui/label` (+ `textarea`) | مخصص فوق shadcn | تسمية ظاهرة، `*` للإلزامي، `optional`، hint، error بأيقونة، `aria-describedby`/`aria-invalid`؛ حد `stroke-control`، تركيز حد brand 2px + هالة `focus-halo`؛ يتكامل مع `<Form>` في Inertia 3 وأخطاء Laravel |
| SelectField | `ui/select` (Radix) | **مخصص بـ `<select>` أصلي** | النظام يطلب الأصلي عمدًا (الجوال وقارئ الشاشة)؛ يبقى Radix Select لحالات خاصة فقط — قرار |
| Checkbox | `ui/checkbox` (Radix) | إعادة تنسيق أو أصلي | سطر 44px والتسمية قابلة للنقر؛ النظام أصلي — نفضّل الأصلي لبساطة النماذج |
| RadioGroup | — | مخصص (أصلي) | `fieldset` + `legend`، `inline` |
| Switch | — | إضافة + تنسيق (`shadcn add switch`) | `role=switch` من Radix؛ المسار `stroke-control`/`brand`، المقبض `switch-thumb`، يتحرك نحو نهاية السطر (تحقق RTL) |
| SearchField | `ui/input` | مخصص | أيقونة بحث، زر مسح، Esc يمسح، `loading` |
| FileUpload | — | مخصص | زر أساسي + سحب وإفلات، قائمة ملفات بحالاتها، `Progress` |
| Tabs | — | إضافة + تنسيق (`shadcn add tabs`) | خط سفلي `brand` + وزن 600، `count`، تمرير أفقي على الجوال؛ الاتجاه عبر DirectionProvider |
| Card | `ui/card` | إعادة تنسيق | `rounded-lg`، حد `stroke-weak`، بلا ظل، حشوة 24/16، `title` h3، `actions`، `footer`، `padding="none"` |
| Table | — | إضافة (`shadcn add table`) + مخصص `DataTable` | `caption` إلزامي، تحميل/فراغ/خطأ، بطاقات مكدسة تحت 768، تمرير أفقي قابل للتركيز، بلا خطوط عمودية |
| Pagination | — | مخصص | Inertia `Link`، ملخص «عرض 61–80 من 236»، شكل الجوال، `aria-current="page"` |
| DescriptionList | — | مخصص | `dl` بأعمدة 1–3، «—» للفارغ |
| StatCard | — | مخصص | `delta` بسهم وإشارة ولون، `positive`، `href`، `loading` |
| Steps | — | مخصص | `ol`، `aria-current="step"`، أفقي ≥ 640 وعمودي دونها |
| Dialog | `ui/dialog` (Radix) | إعادة تنسيق | sizes 400/560/800، بلا حركة، أزرار التذييل (الرئيسي في نهاية السطر)، تكدس على الجوال، `dismissible` |
| Menu | `ui/dropdown-menu` | إعادة تنسيق | `align="end"` افتراضيًا، `danger` بعد فاصل، عناصر 44px |
| Tooltip | `ui/tooltip` | إعادة تنسيق | `tooltip-bg`/`on-tooltip`، تأخير 400ms، بلا حركة |
| ToastProvider | `ui/sonner` | إعادة تنسيق | tones، 5 ثوانٍ للنجاح/المعلومة، الخطأ وما فيه إجراء يبقى، 3 كحد أقصى، `role` مناسب؛ `use-flash-toast` الموجود يُربط به |
| Progress | — | إضافة + تنسيق | Radix Progress يستخدم `translateX(-…)` فينكسر في RTL: يُبنى بعرض `inline-size` بدل الإزاحة؛ 200ms؛ غير محدد يتوقف مع reduced-motion |
| Spinner | `ui/spinner` | إعادة تنسيق | `currentColor`، `role=status` + «جارٍ التحميل…» |
| Skeleton | `ui/skeleton` | إعادة تنسيق | لون `skeleton`، `lines`، نبض يتوقف مع reduced-motion |
| EmptyState | — | مخصص | أيقونة في دائرة `fill`، 3 أنواع، `compact`، `tone="danger"` |
| Avatar | `ui/avatar` | إعادة تنسيق | sm 32 · md 40 · lg 56؛ `brand-subtle` + `brand-subtle-text`؛ تجاهل «ال» في الحروف الأولى (تعديل `use-initials`) |
| Breadcrumbs | `ui/breadcrumb` | إعادة تنسيق | الفاصل `chevron-right` معكوس في RTL، يلتف على الجوال |
| PageHeader | — (starter `heading.tsx`) | مخصص يحل محل `heading` | h1 بنمط title، `description`، `actions`، `back` |
| AppShell + NavItem | `ui/sidebar` + `app-sidebar` + `app-header` | إعادة تنسيق | عرض 264، شريط علوي 64، دُرج تحت 1024 يحبس التركيز، «تخطَّ إلى المحتوى» أول عنصر، المحدد `brand-subtle` + `aria-current`، 5–7 عناصر والإعدادات في التذييل |
| SiteHeader | — | مخصص | للموقع ومساحة العميل، دُرج تحت 1024 والإجراءات فيه تحت 768، `<main id="o1-main">` بعرض `content-max` |
| Logo | `app-logo*` | مخصص | انظر §8 |
| Icon | `ui/icon` | إعادة تنسيق | انظر §7 |
| Ref | — | مخصص | `bdi dir=ltr`، mono، رابط اختياري |
| Amount | — | مخصص | `Intl.NumberFormat`، `tabular-nums`، العملة حسب اللغة، `strong`، السالب `danger-text` |

مكونات shadcn الموجودة خارج النظام (`collapsible`, `navigation-menu`, `toggle`, `toggle-group`, `input-otp`, `separator`, `sheet`, `placeholder-pattern`): تُبقى وتُنسَّق بالرموز (`sheet` أساس الدُّرج)؛ `placeholder-pattern` تُزال من الشاشات الحقيقية.

## 10. ترتيب التنفيذ

كل مرحلة PR مستقلة تمر بـ `/feature-development` و`/code-review`.

1. **الأساس (بلا مكونات):** الخطوط، `tokens.css`، ربط shadcn، `@theme`، الزوايا، الظلال، الحركة، سلّما الخط، `base.css`؛ `app.blade.php` (`dir`, `data-theme`, `data-surface`، السكربت، المفضلة)، `use-appearance` + `contrast`، `DirectionProvider`. صفحة `/dev/design-system` (محلية فقط، خلف `app()->isLocal()`) تعرض الرموز والمكونات.
2. **الأوليات:** Icon، Logo، Button، Badge، Counter، Spinner، Skeleton، Ref، Amount، Avatar.
3. **النماذج:** Field/TextField، SelectField، Checkbox، RadioGroup، Switch، SearchField + تكامل أخطاء Laravel/Inertia `<Form>` ونقل التركيز لأول حقل خاطئ.
4. **الرسائل والتراكب:** Alert، ToastProvider (sonner)، Dialog، Menu، Tooltip.
5. **الهيكل:** AppShell/NavItem (إعادة تنسيق sidebar + header في starter)، PageHeader، Breadcrumbs، SiteHeader؛ تطبيق `data-surface="site"` على تخطيطات الموقع ومساحة العميل، وتعديل صفحات المصادقة والإعدادات الموجودة لتتبع النظام.
6. **البيانات:** Card، Table/DataTable، Pagination، DescriptionList، StatCard، EmptyState، Tabs، Steps، Progress، FileUpload.
7. **Alpha:** `BrandPalette` + اختباراته، نموذج إعدادات المستأجر، الحقن، رفع الشعار/المفضلة، الوضع الافتراضي.

## 11. الملفات المتوقع تغييرها أو إضافتها

| الملف | التغيير |
| --- | --- |
| `src/resources/css/app.css` | استيراد ملفات opsone، `@theme` جديد، ربط shadcn، `contrast` variant |
| `src/resources/css/opsone/{fonts,tokens,base}.css` | جديد |
| `src/resources/fonts/*.woff2`, `OFL.txt` | جديد (من `docs/design-system/source/fonts`) |
| `src/resources/views/app.blade.php` | `dir`، `data-theme`، `data-surface`، سكربت الثيم، المفضلة، إزالة Instrument Sans |
| `src/resources/js/app.tsx` | `DirectionProvider`، `Toaster` بالاتجاه |
| `src/resources/js/hooks/use-appearance.tsx` | `contrast` |
| `src/resources/js/hooks/use-initials.tsx` | تجاهل «ال» |
| `src/resources/js/components/ui/*` | إعادة تنسيق (§9) + إضافة switch, tabs, table, progress, textarea |
| `src/resources/js/components/opsone/*` | المكونات المخصصة |
| `src/resources/js/components/app-logo*.tsx`, `app-sidebar.tsx`, `app-header.tsx`, `heading.tsx` | استبدال/تنسيق |
| `src/resources/js/lib/i18n` (أو ما تعتمده ميزة اللغات) | نصوص المكونات |
| `src/app/Http/Middleware/HandleInertiaRequests.php` | مشاركة `locale`, `dir`, `appearance`, `tenantTheme` |
| `src/app/Http/Middleware/SetLocale.php` | جديد (إن لم تنشئه ميزة i18n) |
| `src/app/Support/Theme/BrandPalette.php` + Form Request + migration لإعدادات المستأجر | المرحلة 7 |
| `src/public/favicon*`, `apple-touch-icon*` | بعد توفر الملفات |
| `src/package.json` | `@radix-ui/react-direction` (بعد التحقق من النسخة) |
| `docs/design-system/usage.md` | دليل الاستخدام للمطورين (أسماء الأدوات، الممنوعات) |
| `docs/decisions/000X-design-system-integration.md` | ADR: إعادة البناء فوق shadcn بدل استيراد الحزمة، أصلي vs Radix، `.dark` + `data-theme` |
| `docs/tech-stack.md` | ذكر الخطوط والنظام (بعد انتهاء project-init) |

## 12. التحقق

لكل PR:

```bash
./manage.sh node-build
```

```bash
./manage.sh run node-build pnpm exec tsc --noEmit
```

```bash
./manage.sh artisan test
```

(اسم خدمة Node في الأمر الثاني يُتحقق منه في `docker-compose`؛ أو `pnpm run types:check` و`pnpm run check` داخل الحاوية.)

يدويًا في `/dev/design-system` وصفحات حقيقية — مصفوفة **2 اتجاه × 3 ثيم × 2 سطح × عرضان (375 و1280)**:
- القائمة الجانبية والدُّرج في الجهة الصحيحة، الأيقونات الاتجاهية معكوسة، الشعار غير معكوس.
- لا نص يتجاوز حاويته بالعربية (أطول بـ20–30%).
- التركيز ظاهر على كل عنصر (Tab)، الأسهم في Tabs وMenu باتجاه القراءة، Esc يغلق ويعيد التركيز.
- تباين: فحص آلي (axe في Playwright أو Lighthouse) للصفحات الأساسية في الثيمات الثلاثة؛ يُسجَّل الاستثناء المعروف `highlight` 2.9:1.
- `prefers-reduced-motion` و`forced-colors` (محاكاة DevTools).
- تكبير 200%: لا تمرير أفقي للصفحة.
- Alpha: ألوان متطرفة (أصفر فاتح، كحلي شبه أسود، رمادي) → إما لوحة ناجحة التباين أو رفض واضح؛ اختبارات Pest لـ `BrandPalette` (نسب التباين، الحدود، الإدخال غير الصالح).
- لقطات مرئية (Playwright screenshots) للمصفوفة أعلاه لاحقًا كاختبار انحدار — اختياري.

## 13. المخاطر

| الخطر | التخفيف |
| --- | --- |
| تعارض أسماء الزوايا بين shadcn والنظام فتظهر 10px بدل 8 | تعريف صريح في `@theme` ومراجعة كل مكون |
| مكونات shadcn بخصائص فيزيائية تنكسر في RTL | تدقيق `grep` لـ `ml-|mr-|pl-|pr-|left-|right-|text-left|text-right` قبل كل PR |
| Radix بلا `DirectionProvider` يعكس الأسهم | إضافته في المرحلة 1 |
| Radix Progress وأي `translateX` يعكس الامتلاء في RTL | بناء بالعرض المنطقي |
| تحديثات shadcn المستقبلية (`shadcn add --overwrite`) تمحو التنسيق | المكونات المعدلة موثقة، والمكونات المخصصة في `components/opsone/` منفصلة |
| حجم الخطوط (≈730KB لكل الأوزان) | تحميل حسب الاستخدام + `font-display: swap` + preload للعربي 400 فقط |
| لون مستأجر يكسر التباين أو حقن CSS | حارس التباين في الخادم، hex فقط، `nonce` |
| SVG مرفوع من المستأجر (XSS) | تنظيف أو رفض SVG |
| انحراف القيم عن النظام مع الوقت | `tokens.css` مولّد من `tokens.json` + فحص CI |
| تعارض العمل مع جلسة project-init | لا تنفيذ قبل انتهائها ودمج فرعها |
| ملفات الشعار الأصلية غير متاحة | `Logo` من المسارات الأصلية مؤقتًا، والملفات قبل الإطلاق |

## 14. الفجوات والتعارضات في النظام نفسه

1. ملفات الشعار وأيقونات التطبيق والمفضلة غير قابلة للجلب بصلاحية العرض.
2. `tokens.css` غير منشور (مولّد في العارض) و`bundle.css` يعتمد عليه.
3. ثيم `contrast` يعرّف 26 لونًا من 72 والباقي يرث الفاتح (مقصود في صيغة النظام، لكن لا قيم `contrast` لـ `brand-subtle`، `success-bg`… فيجب التحقق بصريًا).
4. الحركة غير موجودة في `tokens.json`؛ في النص و`bundle.css` فقط.
5. لا ألوان رسوم بيانية (`chart-*`) ولا لوحة بيانات.
6. لا رموز لثيم Alpha ولا قواعد اشتقاق اللوحة؛ الخوارزمية في §6 اقتراح منّا.
7. «Lucide v1.49.0» في النظام مقابل `lucide-react ^0.475` في المشروع: رقم النسخة يحتاج تحققًا.
8. «الأرقام لاتينية في الواجهتين» بينما الأمثلة تستخدم «قبل ٥ دقائق» برقم هندي.
9. نص README لمكوّن Menu فيه قيم ناقصة (سطر محاذاة `align` بلا قيم).
10. مكونات النظام تعتمد عناصر أصلية (`select`, `checkbox`, `dialog`) وshadcn يعتمد Radix.
11. النظام يستخدم `data-theme` وshadcn/starter يستخدم فئة `.dark`.
12. الدُّرج على الجوال: «لا حركة دخول» قد يبدو مفاجئًا للدُّرج الجانبي.
13. المبالغ تستخدم «ر.س»؛ رمز الريال السعودي الجديد (U+20C1) غير مذكور وقد لا يدعمه Plex.
14. لا نصوص/قواعد لنماذج المصادقة الموجودة في starter kit (تسجيل الدخول، 2FA، passkeys).

## 15. أسئلة مفتوحة (قرار المستخدم)

1. **ملفات الشعار:** هل تصدّرون ملفات Logos وAppIcons الأصلية (أو تمنحون صلاحية تحرير مؤقتة على النظام) لنضعها في المشروع؟
2. **«الإدارة تحتفظ بهوية OpsOne»:** هل المقصود لوحة موظفي المستأجر كلها (AppShell) أم لوحة إدارة مركزية فقط؟ أي: هل يرى موظفو المستأجر لونه في لوحتهم؟
3. **ظهور OpsOne في موقع المستأجر:** «بواسطة OpsOne» في التذييل؟ وماذا يظهر حين لا يرفع المستأجر شعارًا؟
4. **SVG من المستأجر:** نقبله بعد التنظيف أم نقصر الرفع على PNG/WebP؟
5. **عناصر أصلية أم Radix:** نتبع النظام (`select` و`checkbox` أصليان) أم نُبقي Radix من shadcn؟ (التوصية: أصلي لـ Select وCheckbox وRadio، وRadix لـ Dialog/Menu/Tooltip/Tabs/Switch.)
6. **ثيم التباين العالي:** يدخل في الإصدار الأول أم لاحقًا؟
7. **رمز العملة:** «ر.س» كما في النظام أم رمز الريال الجديد؟
8. **حركة الدُّرج على الجوال:** نلتزم «بلا حركة» أم نسمح بانزلاق قصير (≤200ms) يتوقف مع reduced-motion؟
9. **تبديل اللغة:** بإعادة تحميل الصفحة (موصى به) أم دون إعادة تحميل؟ ومصدر اللغة: بادئة في المسار (`/ar`, `/en`) أم كوكي/تفضيل المستخدم؟ (يهم SEO للموقع العام.)
10. **ألوان الرسوم البيانية:** نطلب من مصمم النظام تعريفها أم نعتمد الاقتراح المؤقت؟
