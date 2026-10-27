# Architecture — معمارية القالب

## الحدود
- المستودع الجذري هو وحدة التحكم في Docker وTraefik والإعدادات والتوثيق وClaude Code.
- `src/` يحتوي Laravel والكود التطبيقي فقط، ويكون فارغًا عند نسخ القالب لأول مرة.
- داخل وضع Inertia، ملفات React في `src/resources/js/`، والمصادقة/التوجيه في Laravel.
- في وضع API+SPA (غير افتراضي)، تستلزم `src/frontend/` وحدها تعديل بناء الأصول وNginx ونشر الواجهات وإعدادات CORS/CSRF.

## الخدمات وتدفق الطلبات

```text
Internet --(TLS)--> external Traefik --> web (Nginx) --> app (PHP-FPM) --> MariaDB
                                                   |----> Redis (cache, sessions, queues)
                                                        ^
                                                        |
                                           worker (queue:work redis)
                                           scheduler (schedule:work)

Local: http://127.0.0.1:${APP_PORT} --> web --> app
Build time: node-build (pnpm/Vite) --> src/public/build
Development: node-dev (Vite HMR port 5173)
```

Traefik مستقل عن هذا المستودع. بيئات dev/stage/prod تستخدم `docker-compose.prod.yml` لربط web بشبكة `proxy` ومصادقة phpMyAdmin إذا شُغلت الخدمة.

## النشر

الوضع الحالي يعتمد Bind Mount للكود من المضيف. هذه آلية بسيطة لكنها ليست نشرًا ذريًا، وقد تتغير الملفات بينما التطبيق يعمل. قبل إنتاج كبير أو عالي التوافر خطط للانتقال إلى Immutable Images، عمليات نشر قابلة للتراجع وDB migrations متوافقة مع الإصدارات المتعاقبة.

## الموافقة على تغييرات المعمارية

توثق الخيارات الجوهرية في `docs/decisions/`، خاصة الهوية (Sanctum vs Passport)، فصل الواجهة، Multi-tenancy، قاعدة البيانات، تحديثات Docker والشبكات.
