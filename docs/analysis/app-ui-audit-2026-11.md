# تدقيق تصميم شاشات التطبيق — نوفمبر 2026

> النطاق: شاشات الموظفين والمدير والإدارة، وبوابة العميل، وتطبيق الميدان (PWA) على `main` (`dbd13a3`).
> تقرير فقط؛ لم يُعدَّل أي كود تطبيق.

## المنهج وما شُغِّل فعلًا

- **البيئة:** حزمة Docker مستقلة `opsone_uia` (`http://localhost:8088`)، وفيها `migrate` ثم `license:dev-issue` ثم `ShowcaseSeeder` (محلي فقط)، ثم `node-build`. لم يُستخدم `migrate:fresh` ولم تُعدَّل أي بنية تحتية.
- **اللقطات:** Playwright (Chromium) بالعربية RTL (`lang=ar`, `dir=rtl`) بعرض 375 و768 و1440، وهي صفحة كاملة (full-page) ومحفوظة في [`docs/screenshots/app-ui-audit/`](../screenshots/app-ui-audit/).
  - الحسابات: المالك للشاشات الإدارية؛ الفني `turki.albaqami` لتطبيق الميدان؛ عميلا البوابة `…0004` (دفع/طلب) و`…0002` (توقيع).
  - كل الصفحات أعادت 200، ولم يظهر تمرير أفقي على مستوى الصفحة في أي عرض (`scrollWidth − innerWidth = 0`).
- **اختبار break-ui:** غيّرت اسم عميل في قاعدة البيانات المحلية إلى اسم منشأة طويل («مؤسسة عبدالرحمن الشهري للمقاولات العامة والديكورات الداخلية والتشطيبات») عبر حدّ البيانات لا الكود، ثم صوّرت (`50-*`, `51-*`)، ثم أعدت الاسم كما كان.
- **المهارات المطبَّقة:**
  - `impeccable` (critique + audit + `detect`). أعاد `detect` على `src/resources/js` و`css` صفر نتائج، لكنه فحص regex فقط على `.tsx/.css`، وقواعد التباين والنص الصغير لم تعمل. يُعدّ هذا نقصًا في العدّ لا شهادة نظافة.
  - `emil-design-eng`.
  - `review-animations`: الحركة نظيفة؛ لا `transition-all` ولا `ease-in`، والمدد 120ms.
  - `mobile-native` على البوابة والميدان.
  - `break-ui`.
- **مراجعة الكود:** ثلاث مراجعات قراءة فقط موازية (الشاشات الأساسية، المالية/الإعدادات/المتجر، البوابة/الميدان)، وتحققتُ يدويًا من البنود المذكورة هنا. البنود المعلّمة *(كود)* مُثبتة من الكود فقط وليس من لقطة.

### ما صمد (لا تلمسوه)

- **الرموز:**
  - صفر ألوان Tailwind خام، وصفر hex خارج canvas التوقيع ومعاينة الثيم.
  - صفر أصناف فيزيائية (`ml-`/`pr-`/`left-`…)؛ كل شيء منطقي (`ms`/`pe`/`start`).
- **`Amount`:** يجمع `bdi dir=ltr` و`tabular-nums` و`nowrap`، والسالب `danger-text`.
- **الجداول:** `TableWrap`/`Table` مع `overflow-x-auto`.
- **المكوّنات المشتركة:** `EmptyState` و`PageHeader` مستخدمة غالبًا.
- **الحركة:** 120ms بمنحنى `--ease-o1`، والـ spinners محترمة لـ `prefers-reduced-motion`.
- **مكوّن `Icon`:** يعكس الأيقونات الاتجاهية تلقائيًا في RTL (`rtl:-scale-x-100`).
- **الأفاتار:** حرف واحد للأسماء العربية (موجود على main).
- **الاسم الطويل جدًا** في رأس الطلب يلتفّ بلا كسر (`50-worstcase-order-show-375.png`).
- **هوية المنشأة:** البوابة تأخذ لون المنشأة (البني) وشاشات الموظفين تبقى بهوية OpsOne. هذا صحيح.

**الدرجة (impeccable audit):**

| المحور | الدرجة (من 4) |
|---|---|
| A11y | 3 |
| Performance | 3 |
| Theming | 4 |
| Responsive | 2 |
| Integrity | 3 |
| **المجموع** | **15/20 (Good)** |

الضعف الحقيقي في **التجاوب على الجوال** و**صدق المحتوى** (مفاتيح داخلية ووحدات إنجليزية وعناصر نائبة)، لا في نظام التصميم.

---

## النتائج

**درجات الخطورة:**
- **P0:** يمنع إكمال مهمة.
- **P1:** صعوبة كبيرة أو مظهر غير احترافي أمام العميل، ويجب إصلاحه قبل الإطلاق.
- **P2:** مزعج وله التفاف.
- **P3:** صقل.

المسارات نسبةً إلى `src/resources/js/` ما لم يُذكر غير ذلك.

### أهم 10

| # | خطورة | الشاشة / الملف | المشكلة ولماذا تهم | الإصلاح |
|---|---|---|---|---|
| 1 | **P0** | بوابة ← الدفع · `pages/portal/pay.tsx` | الصفحة تقول «ادفع بالتحويل البنكي ثم أرسل الإيصال»، لكن لا يوجد IBAN ولا اسم بنك ولا اسم مستفيد في أي مكان بالتطبيق (`grep -i iban` = صفر). العميل على جواله لا يعرف أين يحوّل، فيتصل بالمعرض أو يتوقف. (`45-portal-pay-375.png`) | حقل بيانات بنكية في «بيانات المنشأة» (قرار منتج مطلوب). في الصفحة: بطاقة `bg-fill rounded-lg` فيها IBAN بـ `<bdi dir="ltr" class="font-mono">`، وزر «نسخ» (`Button variant="outline"`) مع toast. |
| 2 | **P1** | بوابة/ميدان/الطلب · `pages/portal/document.tsx:164`، `pages/orders/show.tsx:484`، `pages/field/visit.tsx:395` | الوحدات تُعرض بكود الكتالوج الخام: «7.6 m»، «1 unit»، «U × 3 m». عرض السعر الذي يوقّعه العميل يبدو كمسودة تقنية. (`47-portal-sign-375.png`, `31-field-visit-375.png`, `08-order-show-1440.png`) | `formatQuantity(qty, unit, locale)` مشترك يترجم الوحدة (`units.m` = «م»، `units.unit` = «قطعة») ويلفّ الرقم بـ `<bdi dir="ltr">`، ويحذف أصفار الكسور («2.9» لا «2.900»). |
| 3 | **P1** | كل حقول الإدخال على iOS · `css/opsone/base.css:12` (`--o1-fs-control: 15px`)، `views/app.blade.php:64` (`data-surface="panel"` ثابت) | أي حقل أقل من 16px يجعل Safari يكبّر الصفحة عند التركيز ولا يرجع. يحدث هذا في دخول البوابة والـ OTP والدفع والتوقيع وملاحظات الفني. *(كود + mobile-native)* | في `base.css`: `@media (pointer: coarse) { :root { --o1-fs-control: 16px } }`. ممنوع `maximum-scale`. |
| 4 | **P1** | المالية · `pages/finance/index.tsx:178-260` | جدول يدوي بلا `min-w`: على 375 ينكسر رقم الطلب إلى 3 أسطر («ORD-/2026-/00137») ويُقصّ المبلغ عند الحافة. «الكل» يعرض ~130 صفًا بلا ترقيم، فطول الصفحة 11,761px على الجوال، والمتأخرات الأربع تضيع بين 117 «تسوية · غير مخصص». (`11-finance-375.png`) | استخدام `TableWrap`/`Table`/`Th`/`Td` من `components/org/data-table.tsx`، مع `whitespace-nowrap` على الرقم والمبلغ. ترقيم مثل `customers/pager`، و«التسوية» تبويب مستقل لا جزء من «الكل». على الجوال: قائمة بطاقات `md:hidden`. |
| 5 | **P1** | قائمة الطلبات · `pages/orders/index.tsx` | لا عمود «المرحلة الحالية»، والحالة كلها «جارٍ». البائع لا يرى أين يقف كل طلب (قياس؟ تصنيع؟ تركيب؟) دون فتحه. على الجوال تأخذ الفلاتر الشاشة كاملة قبل أول طلب. (`51-worstcase-orders-1440.png`, `51-…-375.png`) | عمود «المرحلة» بـ `Badge` (`neutral`، و`warning` عند التأخر). على `max-md`: يُطوى الفلتر في `Sheet` خلف زر «تصفية (n)»، وتُعرض الطلبات كبطاقات. |
| 6 | **P1** | صفحة الطلب · `pages/orders/show.tsx` (الرأس) | خمسة أزرار `outline` متساوية الوزن ولا مؤشر للمرحلة الحالية أو الإجراء التالي. على 375 تأخذ الأزرار 4 صفوف قبل المحتوى. «مراجعة الإلغاء» بطاقة مساوية لـ«التسليم» و«المواد». (`08-order-show-375.png`) | في الرأس شريط «المرحلة الحالية ← التالي» (من `components/flow`) مع زر أساسي واحد للإجراء التالي، والباقي في `Menu` («المزيد»). ويُنقل الإلغاء إلى العنصر الخطِر في القائمة نفسها (`danger` بعد فاصل). |
| 7 | **P1** | الصفحة الرئيسية · `pages/dashboard.tsx:151` | لوحة المالك لمنشأة فيها 261 طلبًا تعرض «0» و«0» وبطاقة نائبة «يتوفر مع الوحدة المرتبطة» رغم أن الوحدات تعمل. أول شاشة بعد الدخول تبدو فارغة وغير مكتملة. (`01-dashboard-1440.png`) | إزالة الـ widget النائب نهائيًا، وربط «طلبات تحتاج انتباهًا» ببيانات التأخر الموجودة. إن كان المستخدم مالكًا: تحويل أو رابط بارز إلى «لوحة المالك». |
| 8 | **P1** | إصدارات القواعد · `components/rules/release-changes.tsx`، `pages/rules/release.tsx` | تُعرض مفاتيح داخلية للمدير: `fifty_fifty`، `delivery.completed`، `stage.started:install`، `agreement`، `finance`. يُخلط الاسم الإنجليزي بالعربي في سطر واحد فتنقلب النسب («50% … %50»). (`15-release-show-1440.png`) | إخفاء المفاتيح، وترجمة الأحداث عبر `t('flow.event.*')`، وعرض الاسم بلغة الواجهة فقط. النِّسب داخل `<bdi>`. |
| 9 | **P1** | البوابة · `layouts/portal-layout.tsx:172-185` | شريط سفلي بـ 6 تبويبات (~62px لكل منها)، والعناوين `text-[0.6875rem]` (11px) مقطوعة. لا `viewport-fit=cover`، فـ `env(safe-area-inset-bottom)` بلا أثر في `app.blade.php:12`. العميل في الشمس لا يقرأ «التحديثات/المستندات». (`41-portal-home-375.png`) | 4–5 تبويبات (الإعدادات والتحديثات تنتقل إلى الرأس)، والعنوان `text-caption` (13px). إضافة `viewport-fit=cover` لمسارات البوابة والميدان، و`pb-[env(safe-area-inset-bottom)]` على الشريط. |
| 10 | **P1** | الأسهم في RTL · `components/customers/pager.tsx:51,62`، `pages/orders/sale.tsx:96`، `pages/finance/commission-member.tsx:82`، `pages/calendar/{schedule:151,appointment:267}`، `pages/appointment-requests/show.tsx:63`، `pages/help/*`، `pages/catalog/*` | الكود يمرّر `rtl:rotate-180` إلى `Icon` الذي يعكس أصلًا (`rtl:-scale-x-100`)، فينقلب السهم مرتين ويشير للاتجاه الخطأ: «التالي» يشير يمينًا في ترقيم الاهتمامات (`02-leads-1440.png`)، وسهم «عودة» يشير للأمام. فاصل الـ breadcrumb (`components/ui/breadcrumb.tsx:78`) `ChevronRight` خام بلا عكس («الإعدادات › الإصدارات» معكوس). | حذف `rtl:rotate-180` من 11 موضعًا، و`<Icon icon={ChevronRight}/>` في `BreadcrumbSeparator`. إضافة قاعدة lint بسيطة (`grep` في CI) تمنع `rtl:rotate-180` على `Icon`. |

### باقي النتائج

| # | خطورة | الشاشة / الملف | المشكلة | الإصلاح |
|---|---|---|---|---|
| 11 | P1 | بوابة ← المستند · `pages/portal/document.tsx` | يُطلب من العميل اعتماد التصميم بينما الصور تظهر كأسماء ملفات فقط («kitchen-design-ORD-…jpg»)، بلا معاينة. (`43-portal-document-375.png`) | شبكة مصغّرات `aspect-[4/3] rounded-lg object-cover` تفتح `Dialog` بالحجم الكامل، والاسم في `title`. |
| 12 | P1 | بوابة ← الدفع/التذاكر · `pages/portal/pay.tsx:177`، `ticket-new.tsx:113`، `ticket.tsx:125`؛ ميدان `visit.tsx:219`؛ عمولات `components/commissions/parts.tsx:65` | `input type=file` الأصلي يعرض «Choose File / No file chosen» بالإنجليزية داخل واجهة عربية. (`45-portal-pay-375.png`) | مكوّن `FilePicker` مشترك: input مخفي `sr-only` + `Button variant="outline"` («اختيار صورة أو PDF») + اسم الملف المختار + زر إزالة 44px. |
| 13 | P1 | ميدان · `layouts/field-layout.tsx:~78`، `pages/field/visit.tsx` | عند انقطاع الشبكة لا يظهر إلا شارة صغيرة في الرأس، وأزرار «وصلت/انتهى» تفشل بخطأ Inertia عام. الفني في قبو أو موقع إنشاء لا يعرف ما حُفظ وما لم يُحفظ. *(كود)* | `Alert` دائم `bg-warning-bg` أعلى الزيارة عند `!online`، وتعطيل الأزرار التي تحتاج الشبكة مع نص السبب. تفريغ طابور الصور على `visibilitychange` أيضًا. |
| 14 | P1 | أهداف لمس صغيرة · `components/ui/button.tsx:26,29` | `size="sm"` = 36px و`icon-sm` = 36px. يُستخدمان في حذف البند (`orders/show.tsx:530`) وأزرار الميدان («خروج»، «التطبيق الكامل» `field-layout.tsx:105,111`) و«مسح» لوحة التوقيع (`sign-panel.tsx:110`). checkbox المالية الأصلي ~16px (`finance/index.tsx:213`). الفني بقفازات يضغط خطأ. | `sm: "min-h-11 md:min-h-9"` و`icon-sm: "size-11 md:size-9"`، وcheckbox داخل `label` بـ `min-h-11 min-w-11 grid place-items-center` و`size-5 accent-brand`. |
| 15 | P1 | `css/opsone/base.css` | لا `-webkit-tap-highlight-color` ولا `touch-action: manipulation` ولا `overscroll-behavior`. `field-control:hover` غير محمي بـ `@media (hover:hover)` فيلتصق بعد اللمس. *(كود + mobile-native)* | في `@layer base`: `html{-webkit-tap-highlight-color:transparent}`، `button,a,[role=button],label{touch-action:manipulation}`، `overscroll-behavior-y:none` لتخطيط الميدان، وتغليف hover بـ `(hover:hover) and (pointer:fine)`. ومع إزالة الوميض: `active:bg-fill` على صفوف البطاقات القابلة للنقر. |
| 16 | P2 | تنسيق التواريخ — عدة شاشات | أربع صيغ مختلفة: الاهتمامات `2026/11/01`، طلبات المواعيد `2026-10-29 10:15`، المالية `12 أغسطس 2026`، الطلب `25 أكتوبر 2026`. الميدان يعرض أرقامًا هندية في رأس اليوم («الاثنين، ٩ نوفمبر») وغربية في كل مكان آخر. | `formatDate`/`formatDateTime` موحّد في `lib/format` (`Intl.DateTimeFormat` بـ `ar-SA-u-nu-latn-ca-gregory`)؛ الجداول بصيغة قصيرة، والتفاصيل بصيغة طويلة. |
| 17 | P2 | نطاقات التاريخ والفواصل · `pages/appointment-requests/index.tsx`، `pages/field/visit.tsx` | «2026-11-05 → 2026-11-02» يقرأ معكوسًا في RTL (السهم يشير للبداية). الفاصل «·» يقفز لطرف الهاتف («+966551203344 ·»). سطر الزيارة «ORD… · 2026-12-07 · 13:00–15:00» مقلوب الترتيب. | كل جزء LTR في `<bdi>` مستقل، والسهم `←` في العربية أو `Icon ArrowRight` (معكوس آليًا). الفاصل خارج الـ bdi. |
| 18 | P2 | مراجع تنكسر · `pages/interests/index.tsx` (عمود المرجع)، `pages/finance/index.tsx` | «INT-/0304» على سطرين عند 1440، و«ORD-/2026-/00137» على 375. | `whitespace-nowrap` على كل `Ref`/رقم مستند (أو داخل `components/opsone/ref.tsx` نفسه). |
| 19 | P2 | أنماط الفلاتر غير موحّدة · interests، orders، appointment-requests، finance | الاهتمامات/الطلبات: حبوب صلبة + «تصفية» + «مسح التصفية». طلبات المواعيد: حبة `brand-subtle` بإطار + «تطبيق». المالية: حبوب بالعدد بعد الاسم. ترتيب العدد يتبدل («جديد 36» مقابل «4 جديد»). | مكوّن `FilterChips` + `FilterBar` مشترك في `components/opsone`، فيه حالة نشطة واحدة (`bg-brand text-on-brand`) والعدد في `Badge` بعد الاسم. |
| 20 | P2 | الشريط الجانبي · `lib/navigation.ts`، `components/opsone/app-shell.tsx:70-83` | 24 عنصرًا مسطّحًا بلا مجموعات، والقائمة تتجاوز ارتفاع 900px. مندوب المبيعات يبحث في التوريد والمرتجعات ليصل للاهتمامات. | مجموعات بعناوين `text-caption text-weak`: البيع (الاهتمامات، العملاء، الطلبات، التقويم، طلبات المواعيد)، التنفيذ، المالية، المخزون والتوريد، الإدارة. الشريط قابل للتمرير بنفسه `overflow-y-auto`. |
| 21 | P2 | جدول بنود الطلب على 375 · `pages/orders/show.tsx` | يظهر «البند» و«الكمية» فقط، و«سعر الوحدة/الإجمالي» مخفيان خلف تمرير أفقي بلا أي إشارة. (`08-order-show-375.png`) | على `max-md` يُعرض كل بند كصف بطاقة (الاسم، والكمية × السعر، والإجمالي `Amount` في الطرف) بدل الجدول. |
| 22 | P2 | `StatCard` · `components/opsone/stat-card.tsx:36-52` | القيمة بلا `bdi`/`nowrap`، فمبلغ 12,345,678.90 ينكسر وسط الرقم. التسمية بلا `min-w-0` والأيقونة بلا `shrink-0`. *(كود)* | قيمة داخل `<bdi dir="ltr" class="whitespace-nowrap tabular-nums">` كما في `executive/kpi-tile.tsx:29`، مع `min-w-0` و`shrink-0`. |
| 23 | P2 | الإعدادات · `pages/account/settings-home.tsx` | «الباقة والاستخدام: scale، 19 من 40 مقعدًا»: مفتاح الباقة الخام بالإنجليزية. (`13-settings-1440.png`) | `t('plans.scale')`. |
| 24 | P2 | نماذج الإعدادات الطويلة · `pages/store/settings.tsx`، `account/settings-{sales,work,einvoicing}.tsx`، `rules/{payment-plans,messages}.tsx` | زر الحفظ أسفل الطيّة على الجوال، ولا شريط ثابت. *(كود)* | `StickyActions` مشترك: `sticky bottom-0 border-t border-stroke-weak bg-surface p-3 pb-[env(safe-area-inset-bottom)]`. |
| 25 | P2 | بوابة ← التوقيع · `components/portal/sign-panel.tsx:35-48,163`، `pages/portal/document.tsx:272` | لوحة التوقيع تُقاس مرة واحدة (التدوير يمطّ الرسم)، وارتفاعها 180px، ولا تراجع خطوة. `sticky bottom-20` مثبّت على ارتفاع الشريط القديم. زر «مراجعة وتوقيع» بعد الشروط كلها، خارج منطقة الإبهام. | `ResizeObserver` يعيد القياس ويحفظ الخطوط، و`min-h-[200px]`، و«تراجع» + «مسح» بحجم 44px. متغير CSS لارتفاع الشريط بدل `bottom-20`. شريط إجراء ثابت أسفل الشاشة. |
| 26 | P2 | بنود العقد · `pages/portal/document.tsx`، قوالب البنود | «لا يشمل السعر (50315.20 ر.س)»: مبلغ مُدرج نصًا بلا فواصل آلاف، والأقواس تنقلب. ونفس المشكلة في `account/settings-einvoicing.tsx:165` (`toFixed(2)`). | تنسيق المتغير المالي بـ `amountParts` قبل الإدراج، ولفّه بـ `<bdi>`. |
| 27 | P2 | المتجر · `pages/store/links.tsx:261-270`، `coupons.tsx:98-110` | نسخ الرابط بلا toast ولا رابط ظاهر. سطر الاستخدام «uses · max ∞» نص واحد مختلط الاتجاه. *(كود)* | `toast` نجاح/فشل + حقل قراءة فقط `dir="ltr"` بالرابط، وأرقام الاستخدام في `<bdi>`. |
| 28 | P2 | إصدارات القواعد · `pages/rules/releases.tsx:372-378` | جداول معاينة الخطة `<table>` خام داخل عمود نصف عرض بلا `TableWrap` ولا `min-w-0`. الشريط الفرعي (9 حبوب) لا يميّز الصفحة الحالية بقوة. | `TableWrap` + `min-w-0` على العمود. `rules-nav.tsx` بنمط `Tabs` نفسه المستخدم في صفحة الطلب. |
| 29 | P2 | ميدان · `pages/field/visit.tsx:305-325` | `capture="environment"` يمنع اختيار صورة من المعرض، و`take()` بلا `catch`: فشل الضغط أو امتلاء التخزين صامت. زر إرسال رابط التوقيع بلا حالة تحميل، فالنقر المزدوج يرسل رسالتين. *(كود)* | زر ثانوي «من المعرض» بلا `capture`، و`catch` + toast، و`disabled={sending}`. |
| 30 | P2 | `public/field-sw.js:17` | `VERSION='v1'` ثابت، ومهلة الشبكة 6s قبل عرض النسخة المخزّنة. *(كود)* | رقم إصدار من البناء، ومهلة 3s، وشارة «تعرض بيانات محفوظة». |
| 31 | P2 | `views/app.blade.php:42` | `theme-color` واحد ثابت؛ شريط الحالة فاتح فوق الوضع الداكن. | وسمان `theme-color` مع `media="(prefers-color-scheme: …)"`. |
| 32 | P3 | التقويم · `pages/calendar/index.tsx:207`، `components/calendar/shared.tsx` | 7 أعمدة × ~150px عند 1280 تجعل بطاقة الموعد طويلة جدًا («فرع الرياض (المقر الرئيسي)» على سطرين). الأرقام بـ `font-mono` هنا وبالخط العادي في كل مكان آخر. الأعضاء مربوطون بفاصلة عربية ثابتة «، » حتى في الإنجليزية. | بطاقة مضغوطة (`detailed={false}`) حتى `2xl`، و`Ref` موحّد، و`Intl.ListFormat`. |
| 33 | P3 | البوابة · `pages/portal/order.tsx` | «رحلة الطلب» تكرّر «قيد التصنيع» مرتين. أقسام «طلب موعد/الدعم/الإلغاء» عناوين بروابط عائمة لا تشبه بقية البطاقات، و«طلب إلغاء» بنفس وزن «أبلغ عن مشكلة». | دمج المراحل المتكررة بالاسم (أو تمييزها). قائمة إجراءات واحدة `Card`، والإلغاء `text-danger-text` في آخرها. |
| 34 | P3 | `components/opsone/notifications-bell.tsx:119` | شارة العدد `text-[10px]`، وهي القيمة التعسفية الوحيدة تحت 12px في المشروع. | `text-caption` و`min-w-5 px-1` وحدّ «99+». |
| 35 | P3 | `layouts/field-layout.tsx:66` | `bg-surface/95 backdrop-blur` على رأس لاصق: كلفة إطارات على أندرويد ضعيف بلا فائدة مرئية. | `bg-surface` صلب. |
| 36 | P3 | `components/ui/switch.tsx:21` | حركة المقبض لا تحترم `prefers-reduced-motion` (review-animations #8). | `motion-reduce:transition-none` على الـ thumb. |

### ملاحظات على بيانات العرض (ليست كود واجهة)

- `ShowcaseSeeder` يستخدم أسماء من ثلاثة أو أربعة أجزاء: «عبدالله بن سعد القحطاني» (المالك)، «ياسر بن فهد القحطاني»، «ريم بنت محمد السبيعي»، «خالد بن سعود الشمري». ويستخدم أيضًا اسمًا لاتينيًا («Sarah Al-Qahtani») يلتفّ على سطرين.
  - القاعدة المعتمدة: **اسمان فقط**. هذه البيانات تظهر في كل لقطة عرض، وتُصلح في جلسة منفصلة على الـ seeder.
- أي رسوم للحالات الفارغة أو صور توضيحية مقترحة: مطابخ وخامات وأدوات وأيقونات فقط، **بلا أشخاص ولا نساء**.

---

## دفعات التنفيذ (بلا تداخل في الملفات)

**الترتيب:**
1. الدفعة **A** أولًا لأنها صغيرة ويعتمد عليها الباقي: `formatQuantity`، و`FilePicker`، و`StickyActions`، و`FilterBar`/`FilterChips`، وأحجام الأزرار.
2. بعدها **B، C، D، E** بالتوازي.

**ملفات الترجمة:** `lang/ar.json` و`lang/en.json` تُعدّلها كل الدفعات. لكل دفعة بادئة مفاتيح خاصة بها (`units.*`، `orders.*`، `finance.*`، `portal.*`، `field.*`)، ويحلّ المدير تعارضات الإضافة عند الدمج.

### A — الأساس المشترك و«الإحساس الأصلي» على الجوال
**الملفات:**
- `css/opsone/base.css`، `views/app.blade.php`.
- `components/ui/{button,breadcrumb,switch}.tsx`.
- `components/opsone/{stat-card,notifications-bell,ref,page-header}.tsx`.
- جديد: `components/opsone/{file-picker,sticky-actions,filter-bar}.tsx` و`lib/quantity.ts` (و`formatDate` في `lib/format.ts`).
- `lib/navigation.ts`، `components/opsone/app-shell.tsx`.
- مواضع `rtl:rotate-180`: `components/customers/pager.tsx`، `pages/calendar/{schedule,appointment}.tsx`، `pages/appointment-requests/show.tsx`، `pages/finance/commission-member.tsx`، `pages/orders/sale.tsx`، `pages/help/*`، `pages/catalog/*`.

**النتائج:** 3، 10، 14 (الأزرار)، 15، 16 (الدوال)، 18، 20، 22، 31، 34، 36.

### B — شاشات عمل المبيعات والطلبات
**الملفات:**
- `pages/dashboard.tsx`.
- `pages/orders/{index,show,agreement}.tsx` و`components/orders/*` و`components/flow/*`.
- `pages/interests/*`، `pages/appointment-requests/index.tsx`.
- `pages/calendar/index.tsx` و`components/calendar/*`.
- `components/org/data-table.tsx`.

**النتائج:** 2 (الطلب)، 5، 6، 7، 16 و17 و19 (تطبيقها)، 21، 32.

### C — المالية والإعدادات والإصدارات والمتجر
**الملفات:**
- `pages/finance/{index,commissions,invoices}.tsx` و`components/finance/*`.
- `pages/rules/*` عدا `appointment-requests.tsx`، و`components/rules/*`.
- `pages/account/settings-*.tsx`، `pages/store/*`.
- `components/commissions/parts.tsx`.

**النتائج:** 4، 8، 12 (العمولات)، 23، 24، 26 (الفوترة)، 27، 28.

### D — بوابة العميل
**الملفات:** `layouts/portal-layout.tsx`، `pages/portal/*`، `components/portal/*`.

**النتائج:**
- 1: الواجهة. حقل البيانات البنكية في «بيانات المنشأة» يحتاج قرار منتج وترحيلًا؛ يُنفّذ هنا إن وافق المالك، ويشمل `pages/account/settings-basic.tsx` والـ backend.
  - **نُفّذ** (2026-11-06): اعتمده صاحب المنتج، وأُضيف في صفحة إعدادات مستقلة «الحسابات البنكية» بجدول خاص — [ADR-0046](../decisions/0046-bank-transfer-details.md).
- 2 (البوابة)، 9، 11، 12 (البوابة)، 17 (البوابة)، 25، 26 (المستند)، 33.

### E — تطبيق الميدان
**الملفات:** `layouts/field-layout.tsx`، `pages/field/*`، `components/field/*`، `public/field-sw.js`.

**النتائج:** 2 (الميدان)، 12 (الميدان)، 13، 16 (أرقام الأيام)، 17 (سطر الزيارة)، 29، 30، 35.

**الفحوص بعد كل دفعة:**
- `./manage.sh node-build` و`tsc --noEmit`.
- لقطات 375/768/1440 بالعربية للشاشات المعنية مقابل هذا المجلد.
- اختبار break-ui (اسم منشأة طويل، ومبلغ 12,345,678.90، وقائمة فارغة) على الشاشات المعدّلة.

## فهرس اللقطات

| الملف | الشاشة |
|---|---|
| `01-dashboard-*` | الصفحة الرئيسية |
| `02-leads-*`، `03-lead-show-*` | الاهتمامات |
| `04-calendar-*`، `19-appointment-*` | التقويم والموعد |
| `05-appt-requests-*`، `06-appt-request-show-*` | طلبات المواعيد |
| `07-orders-*`، `08-order-show-*` | الطلبات وصفحة الطلب |
| `09-order-agreement-*`، `10-order-finance-*` | اتفاق الطلب وماليته |
| `11-finance-*`، `12-commissions-*` | المالية والعمولات |
| `13-settings-*`، `14-releases-*`، `15-release-show-*` | الإعدادات والإصدارات |
| `16/17/18-store-*` | إدارة المتجر |
| `30-field-home-*`، `31-field-visit-*`، `32-field-install-*` | تطبيق الميدان |
| `40-portal-login-*` … `47-portal-sign-*` | البوابة: الدخول، الرئيسية، المستندات، المستند، الطلب، الدفع، طلب موعد، التوقيع |
| `50/51-worstcase-*` | اختبار الاسم الطويل (لقطات viewport فقط) |
