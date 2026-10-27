# ADR-0001: Laravel Docker Company Starter

- **Status:** Accepted as starter standard; runtime verification pending
- **Date:** 2026-10-14

## القرار

اعتماد مستودع واحد يفصل تطبيق Laravel داخل `src/` عن Docker/Traefik/AI/docs في الجذر؛ اعتماد Laravel 13 + PHP 8.5 + React/Inertia كافتراضي لإنشاء مشاريع جديدة، وتفعيل المسارات API-only وLivewire وAPI+SPA بطلب صريح.

## الأسباب

- منع اختلاف بنى مشاريع الشركة.
- توفير بيئة تشغيل قابلة للتكرار مع Docker.
- تقليل تكلفة التهيئة على الفريق بمساعدة Claude Code /project-init.
- إبقاء امتيازات الأسرار وعمليات النشر منفصلة عن كود التطبيق.

## النتائج والقيود

- يجب أن تراجع الفرق والتقنيات والإصدارات لكل مشروع؛ القالب لا يغني عن اختبار التشغيل.
- مسار API+SPA يحتاج تكييف Compose/Nginx على مستوى المشروع.
- bind mounts لا تحقق نشرًا ذريًا/zero-downtime.
- لا تُثبّت الإضافات الاختيارية افتراضيًا.
