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

## المنافذ (Ports)

- `start` و`restart` و`pma` و`node-dev` تتحقق قبل التشغيل من أن المنافذ المطلوبة غير مستخدمة على الجهاز.
- إذا كان المنفذ مستخدمًا ينقله `manage.sh` إلى أول منفذ متاح، ويحدّث `.env`. وإذا تغيّر `APP_PORT` يحدّث `APP_URL` في `src/.env` أيضًا.
- لعرض المنافذ الحالية وفحصها يدويًا: `./manage.sh ports`.
- في `dev` و`stage` و`prod` لا تُنشر منافذ web وDB وRedis على المضيف، لأن Traefik يتولى التوجيه.

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
