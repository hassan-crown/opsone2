# تدقيق أمني قبل الإطلاق — OpsOne (نوفمبر 2026)

- **النطاق:** تطبيق Laravel في `src/` على `main` (commit `dbd13a3`)، مع ملفات البنية التحتية في جذر المستودع بقدر ما تؤثر على أمن التطبيق.
- **النوع:** تقرير فقط. لم يُعدَّل أي كود. الإصلاحات تُنفَّذ لاحقًا في جلسات منفصلة.
- **المنهج:** قراءة ساكنة للكود (كل ملفات `routes/*.php` وكل الـ controllers وطبقات `App\Access` و`Portal` و`Field` و`Store` و`Finance/Gateway` و`EInvoicing` و`Messages`)، ثم التحقق اليدوي من كل نتيجة مصنَّفة متوسطة أو أعلى بقراءة السطور المذكورة. لم تُشغَّل اختبارات التطبيق ولم يُجرَ فحص runtime (انظر §6).
- **التبعيات:** `composer audit --locked` و`pnpm audit --prod` نُفِّذا فعلًا (§4).
- المسارات أدناه نسبية إلى `src/` ما لم تبدأ بـ `../` أو باسم ملف في الجذر.

## 1. الملخص

| الخطورة | العدد |
|---|---|
| Critical | 0 |
| High | 1 |
| Medium | 6 |
| Low | 14 |

لا توجد ثغرة تسمح لزائر مجهول بالوصول إلى بيانات أو تنفيذ أوامر في بيئة **production** مضبوطة كما يفترض الـ installer. أخطر ما وُجد:

1. **H-1** بيئة `dev` المنشورة على الإنترنت تعمل بـ `APP_ENV=local` و`APP_DEBUG=true`، فتتعطل كل الحمايات المربوطة بـ `environment('local')`: حارس demo-OTP، وحارس SSRF على SMTP، وTrustHosts، وصفحات الأخطاء. **(أُصلح)**
2. **M-1** صاحب صلاحية P-95 على مستوى فرع يغيّر سياسة العمولات للمنشأة كلها ونِسب كل الموظفين (ويرفع نسبته بشكل غير مباشر).
3. **M-2** انقلاب الهرمية في إدارة الأعضاء: مدير فرع يعطّل أو يسحب أدوار مدير منطقة أو مدير منشأة فرعه الأساسي هو نفس الفرع.
4. **M-3** رمز التحقق في الدفع يعيد تفعيل بوابة عميل عطّلها الموظفون، بميزانية تخمين منفصلة.
5. **M-4** إغراق رسائل SMS (SMS pumping) عبر نموذج الدفع لأي رقم دولي بلا سقف عام.

## 2. النتائج مرتبة حسب الخطورة

### H-1 — High: بيئة `dev` المنشورة تعمل كـ `local` مع `APP_DEBUG=true`

- **الحالة:** أُصلح (نوفمبر 2026، بموافقة صاحب المنتج). `sync-app-env.sh` يربط dev بـ `APP_ENV=development` و`APP_DEBUG=false`. `manage.sh` (`config`/`start`/`restart`/`install`/`jobs`/`deploy`) والـ installer يرفضان `APP_ENV=local|testing` أو `APP_DEBUG=true` في dev/stage/prod. `ops:preflight` أضاف فحص `exposure` لأي `APP_URL` عام. الـ installer لا يثبّت `require-dev` في البيئات المنشورة. الاختبارات في `tests/Feature/Security/DeployedDevEnvironmentTest.php` (demo code وحارس SSRF وTrustHosts وpreflight في `development`).

- **الموقع:** `../scripts/sync-app-env.sh:57-64` (أي `ENVIRONMENT` غير prod/stage يصبح `APP_ENV=local` و`APP_DEBUG=true`)، و`../manage.sh:183-185` (dev تستخدم `docker-compose.prod.yml`، أي Traefik مع TLS عام)، و`APP_URL=https://${WEB_DOMAIN}` في dev. وكذلك `../scripts/installer.sh` يثبّت `require-dev` (Boost وPail) خارج prod/stage.
- **ما يتعطل في dev:**
  - `config/portal.php:12`: `demo_otp_environments` تشمل `local`، فلو ضُبط `PORTAL_DEMO_OTP` في dev يُقبل الرمز الثابت لأي عميل (`app/Portal/CustomerOtp.php:83-98`).
  - `app/Integrations/PublicHost.php:15` و`app/Http/Requests/Notifications/UpdateSmtpRequest.php:50,58`: حارس SSRF على SMTP يُتجاوز في `local`.
  - TrustHosts لا يُطبَّق في `local` (سلوك Laravel)، فيمكن تسميم Host header في روابط إعادة تعيين كلمة المرور والروابط الموقعة.
  - `APP_DEBUG=true` يكشف stack traces والاستعلامات والمسارات و(حسب الصفحة) قيم الإعداد.
- **سيناريو الاستغلال:** بيئة dev على `dev.example.com` فيها نسخة من بيانات حقيقية أو بيانات العميل التجريبية. مهاجم:
  - يطلب OTP لجوال أي عميل ويدخل بالرمز الثابت (إن كان مضبوطًا) فيرى طلباته ومستنداته ويوقّع باسمه؛
  - أو يسبّب خطأ ويقرأ صفحة Ignition؛
  - أو، إن كان موظفًا بصلاحية إعدادات الإشعارات، يضبط SMTP host إلى `169.254.169.254` أو `mariadb` أو `redis` ويستخدم "اختبار SMTP" كأداة SSRF.
- **الإصلاح:**
  - ربط dev بـ `APP_ENV=development` و`APP_DEBUG=false` افتراضيًا.
  - أو حماية dev في Traefik بـ IP allowlist أو basic-auth.
  - إضافة فحص في `OpsPreflightCommand`/installer يرفض (fail) `APP_ENV=local` أو `APP_DEBUG=true` عندما يكون `APP_URL` على https عام.
  - هذا تعديل بنية تحتية مشتركة، ويحتاج موافقة صريحة.
- **اختبار يُضاف:**
  - Unit: `CustomerOtp::demoCode()` و`PublicHost::allows('10.0.0.1')` يرفضان في `APP_ENV=development`.
  - اختبار shell لـ `sync-app-env.sh` يؤكد أن `ENVIRONMENT=dev` لا ينتج `APP_ENV=local`.

### M-1 — Medium: P-95 بنطاق فرع يغيّر سياسة العمولات للمنشأة كلها

- **الموقع:** `app/Actions/Commissions/UpdateCommissionSettings.php:43-46,53` (`Access::authorize($actor, Permission::P95)` بلا record). المسار `routes/commissions.php:33` → `SalesSettingsController::updateCommissions`.
- **السبب:**
  - `app/Access/ScopeResolver.php:16` يعيد `true` لأي grant عندما يكون الـ record قيمة null.
  - دور `finance` يحمل P-95 بنطاقات `department/branches/region/tenant` (`app/Access/PermissionCatalogue.php:875-881`).
- **سيناريو الاستغلال:** مسؤول مالي على الفرع A يرسل `PUT /settings/sales/commissions` بـ `default_rate_bp=10000` ونِسب لأعضاء في الفرع B. النتيجة 200 وتتغير السياسة للمنشأة كلها.
  - الحماية من "تعديل نسبتي" (`:69-73`) تقتصر على صف العضو نفسه، فيستطيع رفع نسبة قالب دوره أو `default_rate_bp` ويستفيد شخصيًا.
  - `SalesSettingsController::edit` يعرض `rate_bp` لكل أعضاء المنشأة لأي حامل P-95 بأي نطاق.
- **الإصلاح:**
  - اشتراط grant بنطاق Tenant (نمط `UpdateRoleTemplate::assertFacilityWide`).
  - منع تعديل نسبة قالب دور المنفِّذ نفسه ومنع تعديل `default_rate_bp` لمن يتأثر بها.
  - إخفاء `members[].rate_bp` في `edit` عمّن لا يملك P-95 على مستوى tenant.
- **اختبار يُضاف:** عضو بـ P-95 بنطاق `branches[A]` يرسل PUT يغيّر عضوًا في B و`default_rate_bp`. المتوقع 403، و`CommissionSettings` و`CommissionRate` بلا تغيير.
- **نمط عام:** أي `Access::authorize/can($user, P-xx)` بلا record لصلاحية تقبل نطاقًا أضيق من tenant يُعامل كـ tenant-wide. يُنصح بفحص آلي (Larastan rule أو اختبار معماري) يمنع النداء بلا record لصلاحيات لا تكون tenant-only. انظر أيضًا L-8.

### M-2 — Medium: انقلاب الهرمية في تعطيل الأعضاء وتعديل أدوارهم

- **الموقع:**
  - `app/Models/Membership.php:101-104`: `accessBranchIds()` تعيد الفروع الأساسية للعضو فقط.
  - `app/Actions/StateMachines/M22Membership/T01DisableMembership.php:36`: `authorize(P06, $membership)`.
  - `T03UpdateMemberAccess` (P-04).
  - `DelegationGuard` يفحص الصلاحيات المضافة فقط، لا الصلاحيات الحالية للهدف ولا المسحوبة.
- **سيناريو الاستغلال:** مدير الفرع A (P-06 بنطاق `branches[A]`) يرسل `POST /org/team/members/{id}/disable` لمدير منطقة أو مدير tenant-wide فرعه الأساسي A. يُعطَّل الحساب وتُلغى جلساته. أو يرسل `PUT .../access` بأدوار فارغة فتُسحب صلاحياته.
  - الحمايات الحالية تغطي المالك والنفس فقط.
  - (مذكور كبند مفتوح في مراجعة P2؛ لم يُغلق.)
- **الإصلاح:** قبل التعطيل أو تعديل الوصول، اشتراط أن تغطي grants المنفِّذ كل grants الهدف الحالية (`DelegationGuard::assertCanGrant($actor, currentGrants($target))`).
- **اختبار يُضاف:** عضو بـ P-06 بنطاق `branches[A]` يعطّل عضوًا يحمل P-37 بنطاق region. المتوقع 403/422 وبقاء الحالة Active.

### M-3 — Medium: رمز الدفع يعيد فتح بوابة عميل عطّلها الموظفون

- **الموقع:** `app/Store/CheckoutOtp.php:124-130` (`portal_login_enabled` يصبح `true` بصمت)، ثم `PortalSession::signIn` في `CheckoutController`.
  - عدّاد الفشل `store-otp:fail:*` (`:105`) منفصل عن `portal-otp:fail:*`.
  - السلوك مثبّت عمدًا في `tests/Feature/Store/StoreScreensTest.php:71-83`، وهو بند مفتوح من مراجعة P8.
- **سيناريو الاستغلال:** الموظف يعطّل بوابة عميل (انتقل الرقم لشخص آخر، أو نزاع). حامل الرقم يفتح `/ar/store/checkout` ويطلب رمزًا ويتحقق، فيُفعَّل الحساب ويدخل إلى كل الطلبات والمستندات والملفات. ويحصل المخمّن على ميزانية تخمين إضافية لنفس الحساب، دون حدث `PortalSignInLocked`.
- **الإصلاح:**
  - تمييز "لم يُدعَ بعد" عن "عطّله الموظفون" (مثل `portal_blocked_at`).
  - رفض الدخول عبر الدفع في حالة الحظر، مع السماح بإكمال الشراء كضيف أو رفضه حسب قرار المنتج.
  - توحيد عدّاد الفشل لكل معرّف بين المسارين.
- **اختبار يُضاف:** عميل `portal_blocked_at != null`، ثم checkout code ثم verify. المتوقع عدم إنشاء جلسة بوابة، و`portal_login_enabled` يبقى `false`. ومحاولات فاشلة في المسارين تُحسب في عدّاد واحد.

### M-4 — Medium: إغراق رسائل SMS (toll fraud) من نموذج الدفع

- **الموقع:** `CheckoutController` → `app/Store/CheckoutOtp.php::issue` → `app/Customers/Phone.php:44-45` (يقبل أي رقم E.164 دولي).
  - الحدود لكل IP أو لكل رقم فقط (`app/Providers/StoreServiceProvider.php:42`: 20/دقيقة/IP، بالإضافة إلى حدود الإرسال لكل معرّف).
  - لا يوجد سقف عام.
- **سيناريو الاستغلال:** مهاجم بشبكة IPs يرسل آلاف طلبات الرمز إلى أرقام دولية عالية التكلفة يتقاسم عائدها مع المشغّل. الفاتورة على المنشأة، وقد يُعلَّق حساب مزوّد SMS.
- **الإصلاح:**
  - قصر رمز الدفع على مفتاح دولة المنشأة (`+966`) أو قائمة سماح قابلة للإعداد.
  - سقف عام بالساعة واليوم (`store-otp:global`) مع إشعار للمالك عند الاقتراب منه.
- **اختبار يُضاف:**
  - `+447700900000` يُرفض بخطأ تحقق.
  - بعد N رسالة في الساعة يُرمى `OtpThrottled` حتى مع IP مختلف.

### M-5 — Medium: الرمز الثابت في بيئة `demo` يعمل لأي عميل، والـ installer يكتفي بتحذير

- **الموقع:**
  - `config/portal.php:9-12` و`app/Portal/CustomerOtp.php:83-98`: الرمز مقبول للدخول وللتوقيع الإلكتروني لأي عميل.
  - `../scripts/installer.sh:64-68`: `WARNING` فقط عند `INFRA_ENVIRONMENT=prod` مع `APP_ENV` غير `production`.
- **ما ثبت آمنًا:** في production يلزم شرطان معًا: `PORTAL_DEMO_OTP` و`APP_ENV ∈ {local,testing,demo}`. والقيمة الافتراضية لـ `APP_ENV` هي `production`، فمتغير واحد خاطئ لا يكفي.
- **سيناريو الاستغلال:** نسخة "تجربة" لعميل حقيقي تعمل بـ `APP_ENV=demo` وفيها بيانات حقيقية (الوثائق تصف الرمز بأنه "للعروض والتجارب"). من يعرف الرمز يدخل بوابة أي عميل ويوقّع مستندات باسمه. أو نسخة prod ضُبط فيها `APP_ENV=demo` بالخطأ، ولم يفشل التثبيت.
- **الإصلاح:**
  - حصر الرمز الثابت في عملاء البذرة (علم `is_demo` أو قائمة جوالات في الإعداد).
  - جعل `installer.sh` يفشل (exit 1) عند عدم تطابق `APP_ENV` في prod/stage.
  - منع `APP_ENV=demo` على بنية prod.
- **اختبار يُضاف:** في `APP_ENV=demo` لا يُقبل الرمز الثابت لعميل ليس من بيانات العرض. وفي `APP_ENV=production` يعيد `CustomerOtp::demoCode()` قيمة null رغم ضبط `PORTAL_DEMO_OTP` (يُبقى كاختبار انحدار).

### M-6 — Medium (infra): phpMyAdmin منشور عبر Traefik بحماية Basic Auth فقط

- **الحالة:** أُصلح (نوفمبر 2026، بموافقة صاحب المنتج). أُزيلت labels الـ Traefik لـ phpMyAdmin من `docker-compose.prod.yml`، فيبقى في prod على الشبكة الداخلية و`127.0.0.1` فقط (نفق SSH). في dev/stage يحمّل `./manage.sh pma` الملف `docker-compose.pma.yml` (ipallowlist قبل basicauth، مع `${VAR:?}` إلزامية)، ويرفض التشغيل عند غياب `PMA_IP_ALLOWLIST` أو `PMA_BASIC_AUTH` أو بقاء قيمة المثال أو `0.0.0.0/0`. و`./manage.sh config` يذكر السبب.

- **الموقع:** `../docker-compose.prod.yml:51-74`: routers على `PMA_DOMAIN` مع `basicauth.users=${PMA_BASIC_AUTH}`، بلا IP allowlist ولا rate limit. يعمل عند `./manage.sh pma` (profile tooling) في dev/stage/prod.
- **سيناريو الاستغلال:** متى شُغّل phpMyAdmin صار عامًا. تخمين basic-auth ثم بيانات DB يعطي وصولًا كاملًا للقاعدة. وسلوك Traefik إذا كان `PMA_BASIC_AUTH` فارغًا غير مضمون (يحتاج فحص runtime).
- **الإصلاح:**
  - إزالة labels الـ Traefik في prod والاكتفاء بـ SSH tunnel إلى `127.0.0.1`.
  - أو إضافة `ipallowlist` middleware.
  - فشل صريح في `manage.sh pma` عند غياب `PMA_BASIC_AUTH`.
  - هذا تعديل بنية تحتية مشتركة ويحتاج موافقة.
- **اختبار يُضاف:** فحص في `manage.sh config`/preflight يرفض تشغيل pma في prod بلا allowlist أو بقيمة auth فارغة.

### L-1 — Low: مكتبة `symfony/mime` v8.1.7 متأثرة بـ CVE-2026-45070

- **التفاصيل:** Email header injection عبر أحرف non-token في أسماء MIME parameters. الخطورة في الـ advisory: medium. المصدر `src/composer.lock` (`symfony/mime` v8.1.7، والإصلاح في ≥ 8.1.10).
- **التقدير:** لا يوجد في الكود ما يمرّر اسم parameter من المستخدم، فالاستغلال المباشر غير ظاهر. لذلك صُنّفت Low.
- **الإصلاح:** `composer update symfony/mime` (patch، دون كسر).
- **الاختبار:** `composer audit` في CI يفشل على أي advisory.

### L-2 — Low: حرمان من الخدمة بقفل دخول العميل والتوقيع

- **الموقع:** `app/Portal/CustomerOtp.php:157-189,364-367`.
  - الفشل يُحسب لكل معرّف حتى بلا رمز نشط.
  - المفتاح `portal-otp:fail:{hash}` مشترك مع رمز التوقيع (`:252`)، و`send:{hash}` مشترك بين الدخول والتوقيع (`:115,208`).
- **سيناريو الاستغلال:** من يعرف جوال العميل يرسل 10 رموز خاطئة كل 15 دقيقة، فيبقى العميل مقفولًا عن الدخول وعن توقيع عقد قبل انتهاء صلاحيته.
- **الإصلاح:**
  - احتساب الفشل لكل معرّف فقط عند وجود رمز نشط، أو ربطه بالجلسة أو الـ IP.
  - فصل ميزانية `step_up` عن الدخول.
- **اختبار يُضاف:** 10 محاولات خاطئة من IP آخر لا تمنع عميلًا مسجّلًا من طلب رمز التوقيع.

### L-3 — Low: لا سقف يومي لتخمين OTP لكل عميل

- **الموقع:** `app/Portal/CustomerOtp.php:43,51`: نافذة 900 ثانية، و10 محاولات فاشلة لكل معرّف. هذا يعني نحو 960 تخمينًا يوميًا لكل معرّف، والجوال والبريد معرّفان منفصلان، بالإضافة إلى ميزانية المتجر (M-3).
- **الإصلاح:** سقف يومي لكل `customer_id` (مثل 30 محاولة فاشلة في اليوم) مع حدث Audit.
- **اختبار يُضاف:** المحاولة 31 في يوم واحد ترمي `OtpThrottled` بعد انقضاء نافذة الـ 15 دقيقة.

### L-4 — Low: استرداد أو chargeback من Moyasar على دفعة مؤكدة يُتجاهل بصمت

- **الموقع:**
  - `app/Finance/Gateway/Moyasar/MoyasarGateway.php:151`: `refunded` يصبح `unresolved`.
  - `app/Finance/Gateway/GatewayPayments.php:156`: أي حدث على `Confirmed/Corrected` يصبح no-op.
- **سيناريو الاستغلال:** استرداد من لوحة Moyasar، أو نزاع بطاقة، بعد تأكيد الدفعة. دفتر المال يُظهر مبلغًا محصّلًا لم يعد موجودًا، والطلب يُسلَّم.
- **الإصلاح:** عند `refunded` أو `voided` لدفعة مؤكدة: Audit مع notice للمالك والمالية، دون عكس تلقائي.
- **اختبار يُضاف:** webhook بحالة `refunded` لدفعة Confirmed ينشئ notice، والحالة لا تتغير.

### L-5 — Low: Markdown link injection في البريد

- **الموقع:** `resources/views/mail/org-message.blade.php:4` (`{{ $line }}` داخل `<x-mail::message>`). يُستدعى من `app/Notifications/Channels/EmailChannel.php:30`.
  - المصادر: ردود التذاكر (`app/Tickets/TicketNotices.php:66`)، ونص الإلغاء (`app/Cancellation/CancellationNotices.php:79`)، وطلبات الدعم (`app/Http/Controllers/Help/SupportRequestController.php:55`).
- **سيناريو الاستغلال:** موظف (أو حساب مخترق) يكتب `[ادفع هنا](https://evil.test)` في رد تذكرة، فيصل للعميل رابط تصيّد قابل للنقر داخل بريد بهوية المنشأة. HTML مُهرَّب، و`javascript:` محجوب.
- **الإصلاح:** قالب HTML/text عادي بدل markdown، أو هروب أحرف Markdown قبل التمرير.
- **اختبار يُضاف:** `Mail::fake()` ثم رد تذكرة نصه `[x](https://evil.test)`. الـ HTML الناتج لا يحتوي `<a href="https://evil.test"`.

### L-6 — Low: ثغرات في حارس SSRF على SMTP

- **الموقع:** `app/Integrations/PublicHost.php:25` و`app/Http/Requests/Notifications/UpdateSmtpRequest.php:49`.
  - `NO_PRIV_RANGE|NO_RES_RANGE` لا يغطي `100.64.0.0/10` (CGNAT).
  - فجوة TOCTOU: Symfony Mailer يعيد حل الاسم بعد الفحص (DNS rebinding).
  - المنفذ غير مقيّد.
- **سيناريو الاستغلال:** موظف بصلاحية إعدادات الإشعارات يضبط اسمًا يحل أولًا لـ IP عام ثم لـ `10.x`، فيتصل الخادم بخدمة داخلية عبر "اختبار SMTP".
- **الإصلاح:** `FILTER_FLAG_GLOBAL_RANGE`، وتمرير الـ IP المتحقق منه إلى الـ transport، وحصر المنافذ في 25 و465 و587 و2525.
- **اختبار يُضاف:** `100.64.1.1` و`:6379` يُرفضان.

### L-7 — Low: حدود دخول الموظفين

- **الموقع:** `app/Providers/FortifyServiceProvider.php:101-105`: المحدِّد مبني على `email|ip` فقط. و`:53-59`: `Hash::check` لا يُنفَّذ إلا عند وجود المستخدم.
- **سيناريو الاستغلال:** هجوم موزّع على IPs كثيرة يخمّن كلمة مرور بريد واحد بلا سقف. وفرق التوقيت يكشف وجود بريد الموظف.
- **الإصلاح:** حد إضافي لكل بريد (مثل 20 في الساعة)، وتنفيذ `Hash::check` على hash وهمي عند غياب المستخدم.
- **اختبار يُضاف:** محاولات من IPs مختلفة على بريد واحد تُخنق بعد N.

### L-8 — Low: `ManageStockSetup::updateItem` يقبل P-49 بنطاق فرع على صنف مشترك

- **الموقع:** `app/Actions/Inventory/ManageStockSetup.php:76` (authorize بلا record).
- **سيناريو الاستغلال:** مستخدم مخزون في فرع A يغيّر وحدة أو حد إعادة الطلب لصنف تستخدمه كل الفروع.
- **الإصلاح:** اشتراط نطاق tenant، أو ربط حد إعادة الطلب بالموقع.
- **اختبار يُضاف:** P-49 بنطاق `branches[A]` يرسل PUT. المتوقع 403.

### L-9 — Low: رابط تفعيل البوابة لا يُلغى لمدة 7 أيام

- **الموقع:** `app/Portal/PortalInvitation.php:30` (`temporarySignedRoute` بـ `customer` id فقط).
- **الأثر:** لا يسجّل الدخول وحده. لكنه يكشف اسم العميل ووجهة الإرسال مقنّعة، ويسمح بتشغيل إرسال رموز (3 كل 15 دقيقة) طوال 7 أيام حتى بعد التفعيل أو تعطيل البوابة.
- **الإصلاح:** إضافة نسخة للدعوة (`v`) تتغير عند التفعيل أو التعطيل، والتحقق منها.
- **اختبار يُضاف:** الرابط القديم يعيد 403 بعد التفعيل الأول.

### L-10 — Low: الـ CSP لا يقيّد `script-src`

- **الموقع:** `app/Http/Middleware/SecurityHeaders.php:18`: `base-uri 'self'; frame-ancestors 'self'; object-src 'none'` فقط.
- **الجاهزية:** توجد سكربتات inline في `resources/views/app.blade.php:15` و`resources/views/site/page.blade.php:27,96` و`resources/views/store/layout.blade.php:15`، وأنماط inline في عدة مواضع.
- **الإصلاح:** `Vite::useCspNonce()`، ثم `nonce` على السكربتات الثلاثة، ثم `script-src 'self' 'nonce-…'`. يُترك `style-src` الآن. الحاجة لـ `unsafe-eval` في build الإنتاج تحتاج فحص runtime.
- **اختبار يُضاف:** header الـ CSP يحتوي `script-src` ويطابق الـ nonce في الوسم.

### L-11 — Low (hardening): mPDF بلا تقييد للموارد البعيدة

- **الموقع:** `app/Agreements/Signing/PdfRenderer.php:55`: لا يُضبط `whitelistStreamWrappers`.
- **الوضع الحالي آمن:** كل قيم `resources/views/pdf/*` مهرّبة، والصور من `imageVars` بأسماء يولّدها الخادم. لكن أي `{!! !!}` يُضاف مستقبلًا يفتح SSRF.
- **الإصلاح:** `'whitelistStreamWrappers' => []`.
- **اختبار يُضاف:** بند فيه `<img src="http://127.0.0.1:1/x">`. الـ HTML المُولَّد يُظهره كنص مهرّب، ولا يحدث اتصال.

### L-12 — Low (hardening): القرص `local` بخاصية `serve => true`

- **الموقع:** `config/filesystems.php:36` (`storage/app/private`).
- **الأثر:** لا يوجد استدعاء `temporaryUrl` حاليًا، والمسار يتطلب توقيعًا، فلا استغلال الآن.
- **الإصلاح:** `'serve' => false`، وإزالة قرص `public` ورابطه إن لم يكونا مستخدمين.

### L-13 — Low: استثناءات webhook المرفوضة تُسجَّل كأخطاء

- **الموقع:** `app/Finance/Gateway/InvalidGatewaySignature.php` و`IgnoredGatewayEvent.php`: فيهما `render()` بلا `report(): false`.
- **الأثر:** كل webhook مزوّر يكتب stack trace في الـ log، فيُغرَق الـ log ويُخفي أخطاء حقيقية. الـ throttle 60/دقيقة/IP يحدّ منه.
- **الإصلاح:** `public function report(): bool { return false; }`.
- **اختبار:** webhook بتوقيع خاطئ لا يستدعي `Log`/`report` (`Exceptions::fake()`).

### L-14 — Low: نظافة إعدادات متفرقة

- `app/Providers/AppServiceProvider.php:93`: قواعد كلمة المرور القوية في `production` فقط، فبيئة `staging` المنشورة تقبل أي كلمة مرور. الإصلاح: `! app()->environment('local','testing')`.
- `app/Http/Controllers/Operations/HealthController.php`: `/health` يكشف أسماء التبعيات وحالتها لأي زائر. الإصلاح: التفاصيل خلف token.
- `composer.json`: `laravel/chisel` في `require` بدل `require-dev`.
- `app/Http/Controllers/Agreements/SalesSettingsController.php:89` و`app/Http/Controllers/Store/StoreAdminController.php:60,108`: تمرير `$request->all()` إلى services. التحقق والتفويض يتمان داخل الـ service (`UpdateCommissionSettings:53-55`، `StoreSetup:33-35`، `CouponAdmin:57-59`)، فهذا انحراف عن قاعدة Form Requests فقط وليس ثغرة.
- `app/Http/Requests/Customers/CustomerRequest.php:61`: `assignee_id` يُتحقق منه كعضوية نشطة فقط، دون صلاحية على فرع العميل (بند مفتوح من P4). الطلبات تفحصه عبر `OrderAccess::isValidAssignee`.
- `app/Http/Controllers/Audit/AuditLogController.php:183-187`: حدث عمولات بلا موضوع scoped يمرّ إلى `can(P94, null)`. نظري، لأن السجلات لا تُحذف.
- `WorkshopBoardController.php:44`: يعرض أرقام طلبات مرتبطة بأوامر شراء مرئية دون `OrderAccess` على الطلب. تسريب أرقام فقط.
- حد `throttle:password-reset` يُضاف داخل `app->booted` (`FortifyServiceProvider.php:115-120`). يحتاج تأكيدًا بعد `route:cache`، بإرسال 6 طلبات `password.email` من IP واحد والمتوقع 429.

## 3. ما تحقق أنه آمن

### التفويض

- **`Authorizer`:** deny افتراضيًا، ثم الترخيص، ثم العضوية النشطة، ثم النطاق، ثم الحالة. الـ memo مربوط بـ `access_version`.
- **`DelegationGuard`:** تغطية الصلاحيات المضافة صحيحة. P-07 ممنوعة، وحذف deny عن النفس يُرفض.
- **`UpdateRoleTemplate`:** يشترط P-04 بنطاق tenant، وقالب المالك مقفل.
- **`GrantResolver`:** الـ deny override يزيل الصلاحية.
- **مسح الـ controllers:** 312 action بلا نداء تفويض مباشر، تُتبّعت كلها إلى Form Request أو Action أو helper يفوّض.
- **الموارد المتداخلة** (`orders/{order}/lines/{line}` وغيرها) تستخدم `scopeBindings` أو فحص الأب صراحةً.
- **البوابة:** كل `Controllers/Portal/*` يمر عبر `PortalAccess`، والمقيّد بـ `customer_id` يعيد 404. يشمل ذلك:
  - الطلبات والمستندات والتوقيع والإثباتات والمواعيد والإلغاء والتركيبات والتذاكر؛
  - الرسائل الداخلية مستبعدة (`TicketPresenter:99`)؛
  - `order_id` في التذاكر وطلبات المواعيد مقيّد.
- **التطبيق الميداني:** `FieldAccess::isMember` يشترط العضوية في الموعد مع P-24، ويعاد الفحص بعد القفل (`FieldVisits::lockOpen`، `InstallationRemarks`).
- **الإدارة:**
  - `support-access` بـ P-79 للمالك، والدعم للقراءة فقط مع انتهاء صلاحية (`EnforceSupportAccess`).
  - `site-admin` بـ P-55/56/100/101.
  - `einvoicing` عبر `ZatcaAccess::manages`.
  - البحث يفحص الصلاحيات لكل provider.

### المصادقة

- **OTP** (البوابة والتوقيع والدفع):
  - 6 أرقام من `random_int`، صالحة 5 دقائق، و5 محاولات للرمز.
  - تُخزَّن HMAC بـ `app.key` مربوطة بالمعرّف، وتُقارن بـ `hash_equals`.
  - تُستهلك مرة واحدة داخل `lockForUpdate`.
  - ردود موحّدة لا تكشف وجود الحساب، وحدود إرسال وقفل لكل IP ولكل معرّف.
- **حارس demo-OTP في production:** سليم (انظر M-5 وH-1 للبيئات الأخرى). تحذير الإقلاع وحدث الـ Audit لا يطبعان القيمة.
- **الجلسات:**
  - `PortalSession::signIn` ينفّذ `regenerate(true)` و`regenerateToken`.
  - الخروج ينفّذ `invalidate`، و"الخروج من كل الأجهزة" عبر `portal_session_version`.
  - البوابة تستخدم cookie منفصلة (`UseCustomerSession`).
  - قبول الدعوات ينفّذ `regenerate`.
  - `SessionRevoker` يُستدعى عند تغيير كلمة المرور وإعادة تعيينها وتعطيل العضوية.
- **Fortify:**
  - التسجيل العام معطّل، و2FA مع `confirmPassword`.
  - رابط إعادة التعيين صالح 60 دقيقة، والاستجابة موحّدة (`UniformPasswordResetLinkResponse`).
  - حد `two-factor` 5/دقيقة.
  - كلمة المرور في production: 12+ حرفًا مع `uncompromised`.

### الروابط الموقّعة والرموز

- **التوقيع** (`SignLinks`):
  - 48 حرفًا عشوائيًا، تُخزَّن sha256، صالحة 7 أيام أو حتى انتهاء المستند.
  - تُلغى عند إصدار رابط جديد أو بعد الاستخدام أو البتّ في المستند.
  - تتطلب دخول العميل صاحب الطلب.
- **روابط السلة:** hash للبحث، والقيمة الأصلية مشفّرة ومخفية.
- **الدعوات:** 64 حرفًا مخزّنة hash، و10 محاولات لكل دعوة دون إعادة تصفير.
- **التنزيلات** (`AttachmentDownloadController`، `Portal/FileController`، تصدير التقارير والإغلاق):
  - موقّعة وقصيرة العمر، مربوطة بالمستخدم أو العميل (`u`/`c`)، مع إعادة فحص الصلاحية.
  - الـ headers: `Content-Disposition: attachment` و`nosniff` و`CSP sandbox`.

### المدخلات والمخرجات

- **الرفع:**
  - كل الرفع عبر `AttachmentService::persist` مع `AllowedAttachment`: pdf/png/jpg/webp/docx/xlsx فقط، بتحقق extension وfinfo MIME وmagic bytes. لا SVG ولا HTML.
  - الحد الأقصى 10MB، والاسم `Y/m/{uuid}.{ext}`، والقرص `attachments` خاص.
  - صور الموقع png/webp فقط مع `getimagesize`.
- **XSS:**
  - `dangerouslySetInnerHTML` الوحيد هو QR الخاص بالـ 2FA (من الخادم).
  - `{!! $themeCss !!}` و`{!! $facilityBrand !!}` مبنيان من hex وأسماء مُتحقق منها بـ regex، والخطوط من allowlist.
  - نصوص الأقسام تمر عبر `nl2br(e())`.
  - روابط CTA مقيدة بـ slug.
- **SSTI:** قوالب الرسائل والبنود وأمر الشراء تستخدم `preg_replace_callback('/\{([a-z_]+)\}/')` باستبدال قيم فقط. لا `Blade::render` ولا `eval` ولا `unserialize`.
- **SQL:** كل `*Raw` ثابت أو parameterized. `FulltextMatch` يربط الكلمات (bound)، ولا `orderBy` على مدخلات المستخدم.
- **Open redirect:** `intended()` من الجلسة. `redirect()->away` في الدفع يأخذ رابطه من Moyasar.
- **CSV injection:** معالَج (`CsvWriter.php:15`، `FacilityExporter.php:172`).
- **Mass assignment:**
  - الموديلات ذات `$guarded = []` لا تُملأ من الطلب.
  - `branch_id` ممنوع عند تعديل العميل، و`UpdateInterest` يطبّق allowlist.
  - حقول الطلب الحساسة غير قابلة للتعديل في `updating`.

### التكاملات

- **Moyasar:**
  - `secret_token` يُقارن بـ `hash_equals`، وأحداث live مرفوضة في وضع test.
  - الدفعة تُقرأ من جديد من Moyasar ولا يُوثق بالـ body.
  - يجب أن تكون على invoice أنشأه التطبيق، والمبلغ والعملة يُقارنان.
  - idempotency عبر `lockForUpdate` وحالات نهائية no-op.
  - صفحة العودة لا تؤكد الدفعة.
  - استثناء CSRF على `webhooks/payments/{gateway}` فقط.
  - المفاتيح `encrypted:array` و`#[Hidden]`، ومفاتيح live مرفوضة.
- **WhatsApp:**
  - HMAC على `X-Hub-Signature-256` بـ `hash_equals` قبل الـ parse، والرفض بلا secret.
  - الحالات تتقدم للأمام فقط.
  - timeouts وretry محدود.
  - محتوى outbox (ومنه OTP) من نوع `encrypted:array`.
- **ZATCA:**
  - `private_key` والأسرار `encrypted` و`$hidden`، و`#[SensitiveParameter]`.
  - لا `withoutVerifying` في أي مكان، مع timeouts.
- **الأسرار:**
  - لا `env()` خارج `config/`، ولا أسرار مضمّنة في الكود.
  - كل استدعاءات `Log::` تسجّل معرّفات فقط.
  - `AuditRecorder::redact` يحجب password وsecret وtoken وotp وsignature وiban وcard بشكل recursive.

### الإعدادات

- `APP_DEBUG` الافتراضي false.
- Boost وPail في `require-dev`، والتثبيت في prod/stage بـ `--no-dev`. لا Telescope ولا Debugbar.
- لا CORS ولا API عامة.
- الجلسة: `http_only=true` و`same_site=lax`، و`secure` يُشتق من https.
- TrustHosts (APP_URL والدومينات الموثّقة)، والـ trusted proxies شبكات خاصة فقط.
- nginx:
  - `root public`، وتنفيذ `index.php` فقط.
  - منع dotfiles وملفات composer وPHP داخل storage.
  - `expose_php=Off` و`display_errors=Off`.
  - منافذ DB وRedis على `127.0.0.1` محليًا، ومحذوفة في prod.

### حدود المعدّل

- نموذج التواصل وطلب الموعد: throttle مع honeypot و`form_token`.
- المتجر: رموز OTP وبدء الدفع والقسيمة ورابط السلة.
- البوابة: رموز OTP وحد الكتابة `portal-write`.
- الدعوات، والـ webhooks (60–120/دقيقة).
- بلا throttle: `store.cart.update` و`locale` فقط، وأثرهما على الجلسة وحدها.

## 4. التبعيات (نُفِّذت فعلًا في 2026-11-04)

- **`composer audit --locked`** (PHP 8.5.12، Composer 2.10.3، في container مؤقت من صورة المشروع): advisory واحد هو `symfony/mime` CVE-2026-45070 (medium)، وهو L-1.
- **`pnpm audit --prod`** (pnpm 12.15.0 في `node:24-slim` على الـ lockfile): `No known vulnerabilities found`.

## 5. ترتيب الإصلاح المقترح

1. **قبل الإطلاق:** H-1، M-1، M-2، M-3، M-4، M-5، M-6، L-1.
2. **مع الإطلاق أو بعده مباشرة:** L-2، L-3، L-4، L-6، L-7، L-9، L-10.
3. **تقوية لاحقة:** L-5، L-8، L-11، L-12، L-13، L-14.

## 6. حدود هذا التدقيق

- قراءة ساكنة فقط. لم تُشغَّل اختبارات التطبيق: تعذّر إنشاء stack Docker خاص (`opsone_sec`) لأن Docker استنفد عناوين الشبكات (`all predefined address pools have been fully subnetted`) بسبب stacks الجلسات الأخرى، ولم تُوقف أي stack أخرى. الـ audits نُفِّذت في containers مؤقتة على الشبكة الافتراضية.
- **بنود تحتاج فحص runtime:**
  - سلوك Traefik مع `PMA_BASIC_AUTH` فارغ.
  - هل يحذف Traefik `X-Forwarded-For` القادم من العميل.
  - `throttle:password-reset` بعد `route:cache`.
  - حاجة build الإنتاج لـ `unsafe-eval`.
  - وجود `PORTAL_DEMO_OTP` في أي بيئة dev أو demo منشورة (لم يُقرأ أي `.env`).
- البنود المفتوحة سابقًا من مراجعات P2 وP8 لم تُكرَّر هنا إلا حين تبيّن أن لها أثرًا أمنيًا قائمًا (M-2، M-3، L-9).
