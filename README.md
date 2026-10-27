# Laravel + Docker + Traefik — Company Starter

قالب شركة موحد لبدء مشاريع Laravel باستخدام Docker، مُعدّ للتطوير بمساعدة الذكاء الاصطناعي (vibe coding) عبر Claude Code. يفصل القالب بصرامة بين التطبيق داخل `src/` وبين البنية التحتية وملفات AI والتوثيق في الجذر.

هذا المستودع **GitHub Template**، وليس مكانًا لتطوير المشاريع. لا يحتوي تطبيق Laravel، و`src/` يُنشأ داخل مستودع كل مشروع.

## البدء بمشروع جديد

كل مشروع يبدأ دائمًا من القالب بمستودع مستقل خاص به. لا تستنسخ هذا المستودع للعمل عليه مباشرة.

**1. أنشئ مستودع المشروع من القالب**

- من GitHub: افتح [laravel-vibe-starter](https://github.com/asbahi-m/laravel-vibe-starter)، ثم **Use this template** ← **Create a new repository**، واختر اسم المشروع واجعله Private.
- أو من الطرفية عبر GitHub CLI:

```bash
gh repo create my-project --template asbahi-m/laravel-vibe-starter --private --clone
```

**2. افتح المشروع في Claude Code**

افتح **جذر مستودع المشروع**، وليس `src/`.

**3. هيّئ التطبيق**

اكتب `/project-init` واذكر اسم المشروع ونوعه (الافتراضي React + Inertia). يعرض Claude Code خطة بالعربية، ولا يبدأ التنفيذ إلا بعد موافقتك. يتولى الأمر إنشاء `.env` واختيار منافذ متاحة وتثبيت Laravel والتحقق من التشغيل، ثم يقدّم تقريرًا نهائيًا.

**4. طوّر عبر Claude Code**

| المهمة | الأمر |
|---|---|
| ميزة جديدة أو تعديل | `/feature-development` |
| مراجعة الكود أو PR | `/code-review` |

بعد ذلك احفظ التغييرات في مستودع المشروع (commit ثم push) كأي مشروع عادي.

**المتطلبات على الجهاز:** Docker Desktop أو Docker Engine مع Compose v2، وGit، وClaude Code. على Windows استخدم Git Bash أو WSL2، ولا يلزم تثبيت PHP أو Node محليًا. التفاصيل في `docs/development.md`.

> **تطوير القالب نفسه:** التحسينات العامة التي تفيد كل المشاريع تُضاف إلى هذا المستودع عبر Pull Request. المشاريع التي أُنشئت سابقًا لا تتأثر، والتحديث يظهر في المشاريع الجديدة فقط.

## البنية

```text
.
├── src/                         # Laravel + UI code (empty until bootstrapped)
├── .claude/
│   ├── rules/                   # Backend, frontend, infrastructure, testing, security
│   └── skills/                  # /project-init, /feature-development, /code-review
├── docs/                        # Architecture, ADRs, stack, development, deployment
├── scripts/
│   ├── new-laravel-from-docker.sh
│   ├── post-create-app.php      # company conventions applied to a new app
│   ├── sync-app-env.sh
│   └── installer.sh
├── Dockerfile                  # PHP 8.5 FPM + pdo_mysql + pdo_sqlite
├── Dockerfile.node             # Node 24 + Corepack
├── docker-compose.yml
├── docker-compose.prod.yml     # Traefik override (dev/stage/prod)
├── manage.sh
├── CLAUDE.md
├── .infra_env.example
└── README.md
```

## التقنية الافتراضية

- Laravel 13 / PHP 8.5 / MariaDB 11 / Redis 7 / Nginx / Traefik v3.
- React 19 + Inertia 3 + TypeScript + Tailwind CSS 4 + shadcn/ui.
- Node 24 + pnpm + Vite. الموقع الافتراضي: تطبيق Laravel واحد وواجهة React داخل `src/resources/js`.
- المسموح اختياره أيضًا: `plain` (API-only/تهيئة مخصصة) أو `livewire`، أو React SPA مستقلة تتطلب إعداد Compose/Nginx إضافيًا.
- تجنب تثبيت Sanctum وPassport معًا افتراضيًا، وأضف المكتبات الاختيارية حسب متطلبات المشروع.

## التهيئة اليدوية (بدون Claude Code)

الطريقة المعتمدة هي `/project-init`. المسار اليدوي مرجع فقط، ويُنفَّذ أيضًا **داخل مستودع مشروع أُنشئ من القالب**، لا داخل القالب نفسه. للاستخدام النصي خارج Skill: `docs/project-init-prompt.md`.

```bash
chmod +x manage.sh scripts/*.sh
./manage.sh init my_project        # ينشئ .env: كلمات مرور عشوائية، UID/GID حسب النظام، منافذ متاحة
# راجع .env (ENVIRONMENT وWEB_DOMAIN عند الحاجة)
LARAVEL_PRESET=react LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh
./manage.sh start
./manage.sh install
./manage.sh node-build
./manage.sh jobs
```

بعدها يعمل التطبيق على `http://localhost:<APP_PORT>`، والقيمة الافتراضية 8080. قبل التشغيل يتحقق `manage.sh` من أن كل منفذ غير مستخدم؛ وإن كان مستخدمًا ينقله إلى منفذ متاح ويحدّث `.env`. افحص حالة الحاويات بالأمر `./manage.sh ps`.

**ملحوظة:** يتطلب استخدام حزمة React الرسمية الاتصال بـ Packagist؛ وتعتمد مكونات Starter Kit على إصداراته الرسمية. ينشئ السكربت المشروع باستخدام `composer create-project --no-scripts` حتى لا تُنفّذ migrations قبل تهيئة قاعدة البيانات. افحص أوامر `composer.json` الأولية وتحقق من الحاجة إلى `artisan install:features` (إن كان موجودًا) أثناء تنفيذ Skill.

لإنشاء Laravel فارغ (API-only مثلًا):

```bash
LARAVEL_PRESET=plain LARAVEL_VERSION=13 ./scripts/new-laravel-from-docker.sh
```

لا تستخدم أكثر من أمر إنشاء على `src/` نفسه.

## أوامر الإدارة

```bash
./manage.sh init my_project      # first run only: create .env
./manage.sh help
./manage.sh ports                # show/resolve host ports
./manage.sh config
./manage.sh build                  # PHP image
./manage.sh build-node             # Node image
./manage.sh start                  # app, web, mariaDB, redis
./manage.sh install                # dependencies, APP_KEY, migrations, storage
./manage.sh node-build             # install pnpm packages + build (frontend only)
./manage.sh node-dev               # Vite HMR on localhost:<VITE_PORT> (local/dev only)
./manage.sh jobs                   # scheduler + Redis worker
./manage.sh stop-jobs
./manage.sh artisan about
./manage.sh logs
./manage.sh restart
./manage.sh ps
```

`./manage.sh deploy` مخصص للمستودعات المربوطة بـ Git، ويتطلب مراجعة وتحضير بيئة النشر والنسخ الاحتياطية مسبقًا؛ **ليس zero downtime**. لا تستخدم `down-volumes` إلا مع فهم أنه يحذف قواعد البيانات والأحجام المسماة.

## ملفات البيئة

1. **Root `.env`:** إعدادات Docker Compose وبيانات اتصال MariaDB. يبدأ من `.infra_env.example`، وهو سري وغير متتبع Git.
2. **`src/.env`:** Laravel APP_ENV/APP_URL/APP_KEY وDB وRedis؛ ينشأ من `src/.env.example` ويتزامن أوليًا عبر `scripts/sync-app-env.sh`.

لا تضع قيمة DB_PASSWORD داخل الوثائق أو رسائل Claude Code. استخدم كلمات مرور قوية ومختلفة لكل مشروع. لا تعِد توليد APP_KEY لمشروع موجود.

## البيئات وTraefik

| Environment | Compose override | Traefik |
|---|---|---|
| `local` | base فقط | لا |
| `dev` | base + prod override | نعم، يحتاج شبكة proxy خارجية |
| `stage` | base + prod override | نعم، يحتاج شبكة proxy خارجية |
| `prod` | base + prod override | نعم، يحتاج شبكة proxy خارجية |

تهيئة Traefik تفترض أن هناك شبكة Docker خارجية باسم `proxy` وEntryPoints باسم `web` و`websecure` وResolver باسم `letsencrypt`. تأكد من مطابقة بيئتك الفعلية. لم يتم تضمين حاوية Traefik نفسها في المشروع.

## ملاحظات التشغيل والجودة

- تأكد من تطابق UID/GID مع المستخدم المحلي؛ تجنب إنشاء ملفات جذرية في `src/`.
- بناء الواجهة يتم عبر صورة Node مستقلة؛ لا تفترض أن Node مثبت في حاوية PHP.
- يمكن الوصول لقاعدة البيانات وRedis من المضيف على `127.0.0.1` في `local` فقط؛ في البيئات التي تستخدم Traefik تُلغى هذه المنافذ.
- phpMyAdmin خدمة اختيارية، تشغيلها: `./manage.sh pma`، ولا ينصح بنشرها علنًا بلا ضوابط إضافية.
- `Redis` محدود الذاكرة؛ استخدام volatile-lru يمنع طرد مفاتيح Queues التي لا TTL لها، لكنه لا يلغي احتمال OOM عند امتلاء الذاكرة.
- يجب تحديث الاختبارات والتحقق منها بعد تثبيت كل تطبيق؛ القالب وحده لا يثبت نجاح Laravel ولا جاهزية الإنتاج.

راجع `docs/architecture.md`, `docs/development.md`, `docs/deployment.md`, `docs/tech-stack.md`.
