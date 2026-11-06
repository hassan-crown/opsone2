# Development Workflow — سير العمل

1. أنشئ مستودع المشروع من القالب: **Use this template** على GitHub، أو `gh repo create my-project --template asbahi-m/laravel-vibe-starter --private --clone`. افتح جذر مستودع المشروع في Claude Code، واستدعِ `/project-init`. لا تطوّر داخل مستودع القالب نفسه.
2. حدد نوع الواجهة ومتطلبات المصادقة والإضافات. راجع الخطة ثم وافق على تنفيذ التثبيت.
3. للحياة اليومية: `./manage.sh start` ثم `./manage.sh jobs`، وللتطوير التفاعلي `./manage.sh node-dev`.
4. لكل Feature: استدعِ `/feature-development`، اقرأ المتطلبات والمعايير، ضع Acceptance Criteria، ثم نفّذ واختبر.
5. نفّذ الاختبارات والتحقق: `./manage.sh artisan test`, `./manage.sh node-build`, وكذلك Pint/Larastan/lint حين تُثبّت.
6. راجع الفرق (Diff)، لا ترفع أسرارًا أو ملفات تشغيل مؤقتة، وافتح PR يشرح الوظيفة والمخاطر ونتائج الاختبارات.
7. عند تغيير المعمارية سجّل ADR وحدّث `docs/tech-stack.md` والتوثيق المناسب.

## المنصات المدعومة

القالب يعمل بالأوامر نفسها على Linux وmacOS وWindows.

| المنصة | المتطلبات | الطرفية |
|---|---|---|
| Linux | Docker Engine + Compose v2 | Bash |
| macOS | Docker Desktop (أو OrbStack) | Terminal؛ يكفي Bash 3.2 المضمّن |
| Windows | Docker Desktop (WSL2 backend) | Git Bash أو WSL2، وليس PowerShell أو cmd |

- `./manage.sh init <APP_NAME>` يضبط `WWWUSER/WWWGROUP` تلقائيًا حسب نظام التشغيل، ويولّد كلمات مرور قاعدة البيانات.
- `manage.sh` والسكربتات تعالج تحويل المسارات في Git Bash تلقائيًا.
- نهايات الأسطر LF يفرضها `.gitattributes`.
- على Windows، وضع المستودع داخل نظام ملفات WSL2 أسرع من قرص Windows.

## الاختبارات على MariaDB

- الإنتاج يعمل على MariaDB 11، لذلك يشغّل CI المجموعة الكاملة مرتين: `laravel-tests` على SQLite (في الذاكرة) و`laravel-tests-mariadb` على حاوية خدمة `mariadb:11`. كلاهما يجب أن ينجح قبل الدمج.
- محليًا (من داخل حاوية `app`، فـ `phpunit.xml` يفرض SQLite ما لم تُمرَّر المتغيرات):
  `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=<قاعدة_الاختبار> <app>_local_app php artisan test`
  — استخدم قاعدة تجريبية فقط: `RefreshDatabase` يعيد بناءها.
- فروق شائعة بين المحركين: `SUM()` يعيد نصًا على MariaDB (حوّل إلى `(int)` قبل المقارنة الصارمة)، وأوامر DDL (`CREATE/ALTER/DROP TABLE`) تُنهي المعاملة ضمنيًا فتدمّر نقاط الحفظ داخل الاختبارات (استخدم جدولًا `temporary()` في أدوات الاختبار)، وبناء الواجهة (`pnpm run build`) مطلوب لاختبارات Inertia.

## المنافذ (Ports)

- `start` و`restart` و`pma` و`node-dev` تتحقق قبل التشغيل من أن المنافذ المطلوبة غير مستخدمة على الجهاز.
- إذا كان المنفذ مستخدمًا ينقله `manage.sh` إلى أول منفذ متاح، ويحدّث `.env`. وإذا تغيّر `APP_PORT` يحدّث `APP_URL` في `src/.env` أيضًا.
- لعرض المنافذ الحالية وفحصها يدويًا: `./manage.sh ports`.
- في `dev` و`stage` و`prod` لا تُنشر منافذ web وDB وRedis على المضيف، لأن Traefik يتولى التوجيه.
- phpMyAdmin مربوط على `127.0.0.1:<PHPMYADMIN_PORT>` في كل البيئات، فيُفحص منفذه في كل البيئات.

## البيئات المنشورة (dev/stage/prod)

- `dev` منشورة على الإنترنت، لذلك `APP_ENV=development` و`APP_DEBUG=false` (التدقيق H-1). `local` محجوزة لجهاز المطوّر فقط.
  - كل الحراسات المربوطة بـ `local` تعمل في dev كما في الإنتاج: TrustHosts، وحارس SSRF في SMTP (`PublicHost`)، وقيد `PORTAL_DEMO_OTP`.
  - نتيجة ذلك في dev: الرسائل النصية وواتساب لا تُكتب في السجل بل تحتاج مزوّدًا حقيقيًا، و`license:dev-issue` غير متاح (استخدم `license:install`)، وصفحة `/_design` غير موجودة.
  - بيئة dev قائمة تعمل بـ `local`: شغّل `scripts/sync-app-env.sh` (أو عدّل `APP_ENV`/`APP_DEBUG` في `src/.env`) ثم `./manage.sh install`.
- `./manage.sh config` و`start` و`restart` و`install` و`deploy` ترفض `APP_ENV=local|testing` أو `APP_DEBUG=true` في `src/.env` خارج local، والـ installer يرفضها أيضًا، و`ops:preflight` يفشل في فحص `exposure` إذا كان `APP_URL` عامًا.
- الـ installer لا يثبّت حزم `require-dev` في dev/stage/prod. الاستثناء: `./manage.sh install refresh` في dev يثبّتها للـ seed ثم يزيلها.

## phpMyAdmin

- `local`: `./manage.sh pma` ثم `http://127.0.0.1:<PHPMYADMIN_PORT>`.
- `prod`: لا مسار عبر Traefik (التدقيق M-6). `./manage.sh pma` ثم من جهازك:
  `ssh -N -L 8081:127.0.0.1:<PHPMYADMIN_PORT> <user>@<server>` وافتح `http://127.0.0.1:8081`. أوقفه بعدها: `./manage.sh stop-pma`.
- `dev`/`stage`: يُنشر على `PMA_DOMAIN` عبر `docker-compose.pma.yml` بشرطين في root `.env`:
  `PMA_IP_ALLOWLIST` (IPs/CIDRs مفصولة بفواصل، لا `0.0.0.0/0`) و`PMA_BASIC_AUTH` (من `htpasswd -nb`، مع `$$` بدل `$`).
  `./manage.sh pma` يرفض التشغيل عند غياب أي منهما أو بقاء قيمة المثال، و`./manage.sh config` يذكر السبب.

## الترخيص محليًا

التحقق من الترخيص لا يُعطَّل في أي بيئة (ADR-0004). محليًا: `./manage.sh artisan license:dev-issue`
(خيارات `--plan=basic|growth|scale`، `--days`، `--unactivated`) يوقّع بمفتاح اختبار غير سري ويركّبه، و
`./manage.sh artisan license:status` للتشخيص. في dev/stage/prod: `license:install <path>` لملف من التطبيق المركزي.

## معيار Pull Request مختصر

- لا نقل لتطبيق Laravel خارج `src/`.
- لم تُضَف مكتبات غير مبررة ولا أسرار داخل Git.
- المصادقة والصلاحيات مفحوصة على الخادم.
- توجد اختبارات كافية للتدفقات الحساسة والتغييرات السلوكية.
- أي Migration موثّقة ولها خطة Rollback قابلة للتطبيق.
- يتم توثيق أوامر التحقق المنفّذة والفاشلة والمتروكة مع السبب.
