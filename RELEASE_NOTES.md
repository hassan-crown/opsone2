# Release Notes — Laravel Company Starter / 2026-10-14

## المحتوى
- فصل تطبيق Laravel داخل `src/` عن Docker وTraefik وملفات AI والتوثيق في الجذر.
- Laravel 13 + PHP 8.5 + MariaDB 11 + Redis 7. المسار الافتراضي React/Inertia عبر الـ starter kit الرسمي، مع Node 24 وpnpm.
- مسارات plain وLivewire، وAPI+React SPA كمسار مخصص يحتاج تهيئة إضافية.
- `CLAUDE.md` والقواعد ومهارات Claude Code: `project-init` و`feature-development` و`code-review`.

## الإدارة والتشغيل
- `manage.sh init <APP_NAME>` ينشئ `.env` بكلمات مرور عشوائية، وUID/GID حسب نظام التشغيل، ومنافذ متاحة.
- فحص المنافذ قبل `start` و`restart` و`pma` و`node-dev`. المنفذ المستخدم يُنقل تلقائيًا إلى منفذ متاح في `.env`، ويُحدَّث `APP_URL` في `src/.env`.
- يعمل على Linux وmacOS (Bash 3.2) وWindows (Git Bash أو WSL2) بالأوامر نفسها، ونهايات الأسطر LF مفروضة عبر `.gitattributes`.
- إنشاء Laravel بصورة PHP موحدة، مع قفل يمنع الكتابة فوق تطبيق قائم، ثم تطبيق معايير الشركة على التطبيق الجديد (`scripts/post-create-app.php`).
- `sync-app-env.sh` يضبط DB وRedis وAPP_URL دون تغيير APP_KEY.
- صورة Node مستقلة للبناء وVite HMR، وتوليد أنواع Wayfinder داخل حاوية PHP.
- queue worker على Redis، وscheduler واحد، وفحوص health لـ PHP-FPM وNginx وMariaDB وRedis.

## التحقق
- مسار `react-inertia` كاملًا على Windows (Git Bash): `init` ثم bootstrap ثم `start` و`install` و`node-build` و`node-dev`، مع Pint وPHPStan والاختبارات و`tsc` و`vp check` وHTTP.
- نقل المنافذ المستخدمة تلقائيًا (web وRedis وVite) مع تحديث `APP_URL` وعنوان Vite HMR.
- `manage.sh init/ports`: Linux (Debian) وBash 3.2 (إصدار macOS).

## حدود معروفة
- النشر الحالي bind-mounted وغير ذري؛ لا يوجد zero-downtime أو rollback مؤتمت.
- مسار API+SPA يحتاج إعداد Nginx وبناء واجهة ومصادقة SPA مخصصة.
- للإنتاج: حدّد استراتيجية backup/restore، وتدوير الأسرار، والمراقبة، وrollback مختبر.
