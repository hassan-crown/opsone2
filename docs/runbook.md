# Runbook — تشغيل نشر منشأة واحدة

> نشر مستقل لكل منشأة (ADR-0002): قاعدة وملفات وRedis و`APP_KEY` وأسرار خاصة بها. القرار التقني في
> `docs/decisions/0028-operations.md`. كل الأوامر من جذر المستودع عبر `./manage.sh` (لا أوامر Docker يدوية).
> لا تعرض قيم الأسرار في الطرفية أو المحادثات أو التذاكر. ما يحتاج موافقة مسؤول السيرفر معلَّم بـ **[موافقة]**.

## 0. ما يحتاج موافقة مسؤول السيرفر قبل الإطلاق

| البند | القيمة الافتراضية (Q-54) | من يقرر |
|---|---|---|
| RPO (أقصى فقد بيانات) | 24 ساعة (نسخة يومية) | **[موافقة]** مسؤول السيرفر |
| RTO (أقصى مدة استعادة) | 4 ساعات | **[موافقة]** مسؤول السيرفر |
| مكان النسخ خارج الخادم | قرص S3-compatible (`OPS_BACKUP_DISK=backups_s3`) | **[موافقة]** + بيانات اعتماد مستقلة لكل منشأة |
| مدة الاحتفاظ | 30 يومًا (`OPS_BACKUP_RETENTION_DAYS`) | **[موافقة]** |
| حفظ نسخة ثانية من `OPS_BACKUP_KEY` | خزنة أسرار خارج الخادم | **[موافقة]** |
| تثبيت `league/flysystem-aws-s3-v3` لتفعيل S3 | غير مثبت حاليًا | تغيير تبعيات منفصل بموافقة |
| تعديل `manage.sh deploy` ليشغّل `ops:preflight` | مقترح في `docs/proposals/manage-sh-operations.md` | **[موافقة]** |
| سعة الموارد لكل منشأة (Q-57) | app/web/worker/scheduler + DB + Redis | قياس P9 ثم **[موافقة]** |

حتى تُعتمد هذه القيم تبقى النسخ على القرص الخاص للخادم نفسه — هذا **لا يحقق** هدف «خارج الخادم».

## 1. تجهيز نشر منشأة جديدة

1. **المستودع والبيئة:** نسخة نظيفة من المستودع على الخادم في مجلد خاص بالمنشأة، ثم:
   ```bash
   ./manage.sh init opsone_<facility>
   ```
   راجع `.env` الجذر: `APP_NAME` فريد لكل منشأة، `ENVIRONMENT` (dev/stage/prod)، `WEB_DOMAIN`.
2. **`src/.env`** من `src/.env.example`، ثم اضبط:
   - `APP_URL` = النطاق العام `https://…`، `APP_ENV=production`، `APP_DEBUG=false`، `APP_VERSION` = وسم الإصدار.
   - `DB_HOST=mariadb`، `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` كما في `.env` الجذر، `REDIS_HOST=redis`.
   - `CACHE_STORE=redis`، `QUEUE_CONNECTION=redis`، `SESSION_DRIVER=redis` (أو database).
3. **المفاتيح (مرة واحدة لكل نشر جديد فقط):**
   ```bash
   ./manage.sh artisan key:generate --force
   ./manage.sh artisan ops:backup-key
   ```
   - `key:generate` فقط لنشر جديد بلا بيانات؛ **لا تعِد توليد APP_KEY لنشر قائم أبدًا**.
   - انسخ سطر `OPS_BACKUP_KEY=…` إلى `src/.env` وإلى خزنة الأسرار خارج الخادم **[موافقة]**. بدونه لا تُستعاد أي نسخة.
4. **الترخيص:** `LICENSE_PUBLIC_KEY` و`LICENSE_KEY_ID` من التطبيق المركزي، و`LICENSE_DEPLOYMENT_ID` = معرف هذا النشر.
5. **التشغيل والترحيل:**
   ```bash
   ./manage.sh build
   ./manage.sh start
   ./manage.sh install
   ./manage.sh node-build
   ./manage.sh jobs
   ```
6. **تثبيت الترخيص** (الملف من التطبيق المركزي):
   ```bash
   ./manage.sh artisan license:install storage/app/private/incoming-license.json
   ./manage.sh artisan license:status
   ```
7. **دعوة المالك الأول:**
   ```bash
   ./manage.sh artisan org:invite-owner owner@example.com --name="اسم المالك"
   ```
8. **فحص الجاهزية:**
   ```bash
   ./manage.sh artisan ops:preflight --skip-backup-check
   ./manage.sh artisan ops:backup
   curl -fsS https://<domain>/health
   ```
   ثم سجّل أول استعادة تجريبية (القسم 3) قبل تسليم المنشأة.

## 2. النسخ الاحتياطي

- يعمل تلقائيًا من خدمة `scheduler`: `ops:backup --prune` يوميًا 02:30 (`OPS_BACKUP_TIME`) مع حذف ما تجاوز 30 يومًا،
  والأحدث يبقى دائمًا.
- المحتوى: لقطة متسقة لكل جداول قاعدة هذه المنشأة + الملفات الخاصة (`attachments`) + manifest بالإصدار والترحيلات
  وعدد الصفوف وsha256. مشفّر بـ `OPS_BACKUP_KEY` (ليس APP_KEY). بجانبه ملف `.json` بلا أسرار.
- يدويًا:
  ```bash
  ./manage.sh artisan ops:backup
  ./manage.sh artisan ops:backup-list
  ```
- **ليس في النسخة:** ملف الترخيص و`.env` والمفاتيح (إعدادات النشر، تُحفظ في خزنة الأسرار)، وبيانات الجلسات والكاش
  والطابور (تُحفظ بنيتها فقط).
- إن فشل: يظهر في سجل التطبيق، ويرفض `ops:preflight` أي ترحيل بلا نسخة أحدث من 24 ساعة.

## 3. الاستعادة وتجربة الاستعادة (drill)

**تجربة شهرية (مطلوبة قبل الإطلاق):** على قاعدة تجريبية جديدة أنشأها المسؤول، لا على قاعدة المنشأة:

```bash
./manage.sh artisan ops:restore opsone-YYYYMMDD-HHMMSSZ.opsbak --verify-only
```

للتجربة الكاملة على قاعدة تجريبية: شغّل الأمر داخل حاوية `app` مع `DB_DATABASE=<قاعدة_تجريبية>` و`--force
--confirm=<قاعدة_تجريبية>`. ينجح فقط إذا طابقت كل الجداول checksums الـ manifest. سجّل المدة الفعلية مقابل RTO.

**استعادة حقيقية للمنشأة** (تستبدل كل جداول قاعدتها):

1. `./manage.sh artisan down` ثم `./manage.sh stop-jobs` (لا عمل جديد ولا Worker أثناء الاستعادة).
2. خذ نسخة من الحالة الحالية أولًا إن كانت القاعدة قابلة للقراءة: `./manage.sh artisan ops:backup`.
3. تحقق ثم استعد:
   ```bash
   ./manage.sh artisan ops:restore <name> --verify-only
   ./manage.sh artisan ops:restore <name> --force
   ```
   يطلب كتابة اسم القاعدة حرفيًا. يُرفض: نسخة من schema أحدث من الكود، محرك مختلف، أو نشر مختلف
   (`--allow-other-deployment` فقط عند نقل المنشأة نفسها لخادم آخر).
4. إن ذكر الأمر ترحيلات أحدث من النسخة: `./manage.sh artisan migrate --force`.
5. `./manage.sh artisan optimize:clear`، ثم `./manage.sh jobs`، ثم `./manage.sh artisan up`.
6. تحقق: `/health` = ok، تسجيل دخول المالك، آخر الطلبات. الاستعادة مسجلة في سجل التدقيق (`ops.backup_restored`).

**العزل:** الأمر يقرأ ويكتب فقط عبر اتصال وأقراص هذا النشر؛ لا يمكن أن يلمس منشأة أخرى لأن إعداداتها في نشر آخر.

## 4. التحديث والتراجع (NFR-15)

**قاعدة الترحيلات:** كل ترحيل جديد عليه `#[Expand]` (إضافة فقط؛ الإصدار السابق يعمل عليها) أو `#[Contract]`
(حذف/إعادة تسمية؛ يصدر بعد إصدار توقف فيه الكود عن استخدام العمود). الاختبارات ترفض ترحيلًا بلا علامة.

**خطوات التحديث:**

1. على بيئة stage أولًا بنسخة حديثة من بيانات مماثلة.
2. `./manage.sh artisan ops:backup` ثم `./manage.sh artisan ops:preflight` — **لا تكمل إن فشل**.
   - `queue` فشل: انتظر تفريغ الطابور.
   - `maintenance` فشل (ترحيل contract): `./manage.sh artisan down` بعد تفريغ الطابور.
   - `migrations` فشل: ترحيل بلا علامة أو expand يحذف — يُعاد للمطور.
3. `./manage.sh deploy` (يسحب الكود ويبني ويرحّل ويبني الواجهة).
4. `./manage.sh artisan up` إن كانت في الصيانة، ثم `/health`.

**التراجع:**

- إصدار جديد بترحيلات expand فقط: أعد الكود السابق (Git tag السابق ثم `deploy`)؛ `ops:preflight` على الكود القديم يعطي
  تحذيرًا `schema` فقط — مقبول.
- إصدار فيه contract: إعادة الكود وحده **مرفوضة** (`schema` = fail). استعد آخر نسخة قبل التحديث (القسم 3) على الكود
  السابق، مع قبول فقد ما بعد النسخة (ضمن RPO).
- `migrate:rollback` غير مستخدم في الإنتاج.

## 5. المراقبة والفحوص

- `GET /health` (بلا جلسة): `200 {"status":"ok"}` أو `503` مع كلمة لكل فحص: `database`، `redis`، `scheduler`
  (نبضة كل دقيقة، متأخرة بعد 3 دقائق)، `queue` (Worker يعالج نبضة، متأخرة بعد 5 دقائق). لا تفاصيل أخطاء فيه.
- يُستدعى من المراقب على نطاق المنشأة نفسه (المضيفات غير الموثوقة ترفض بـ 400، ADR-0025)؛ كل دقيقة تكفي (حد 60/دقيقة).
- `/up` فحص حياة Laravel البسيط فقط.
- يوميًا: `./manage.sh artisan ops:backup-list` (آخر نسخة عمرها < 24 ساعة)، وأسبوعيًا `./manage.sh logs-worker`.
- فشل الإشعارات والتحصيل يظهر للمسؤول من شاشاته (NFR-11)؛ لا أسرار في السجلات.

## 6. أساسيات الحوادث

| العرض | أول خطوة |
|---|---|
| `/health` → `database: fail` | `./manage.sh ps`، سجلات `mariadb`، المساحة على القرص |
| `scheduler: stale` | `./manage.sh ps`؛ أعد `./manage.sh jobs` |
| `queue: stale` | `./manage.sh logs-worker`؛ `./manage.sh artisan queue:restart` |
| `redis: fail` | `./manage.sh ps`؛ الذاكرة `REDIS_MAX_MEMORY` |
| لافتة الترخيص «يحتاج معالجة» | `./manage.sh artisan license:status`؛ الساعة؛ ملف الترخيص من المركزي |
| بيانات تالفة/محذوفة خطأً | القسم 3 (down → backup → restore) وتوثيق ما فُقد ضمن RPO |
| اشتباه تسرب أسرار | تدوير الأسرار المعنية (لا APP_KEY دون خطة لإعادة تشفير الحقول)، مراجعة سجل التدقيق |

سجّل كل حادث: الوقت، الأثر، المنشأة، الخطوات، والمدة مقابل RTO.

## 7. إقفال منشأة (Q-53 أ)

1. المالك يفتح «الإعدادات ← إقفال المنشأة»: قائمة ما بقي مفتوحًا من المصادر (طلبات، مستحقات، دفعات، إيصالات، ردود،
   نزاعات، صرف عمولات، تسويات، مرتجعات، حجوزات، أوامر شراء). كل بند يُعالج من شاشته.
2. «تجهيز التصدير» يبني ZIP على الطابور (CSV لكل جدول + الملفات). رابط موقّع 30 دقيقة، والملف يُحذف بعد 24 ساعة.
3. القراءة والتصدير يعملان في مرحلة «منتهٍ» حتى نهاية الاحتفاظ؛ لا حذف تلقائي بسبب الترخيص (NFR-16).
4. بعد انتهاء مدة الاحتفاظ التعاقدية: حذف النشر قرار تشغيلي **[موافقة]** مع آخر نسخة مشفرة محفوظة حسب العقد.
