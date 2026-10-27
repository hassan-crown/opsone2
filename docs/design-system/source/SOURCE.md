# مصدر هذه الملفات

نسخة خام للقراءة فقط من نظام تصميم OpsOne المنشور على claude.ai:

- الرابط: https://claude.ai/artifact/TaXYxfSC5o5QgNuEwX2gE4
- النسخة: `1793085078-55b5` (آخر تعديل في الفهرس: 2026-10-22 بواسطة Abdullah Taha)
- تاريخ الجلب: 2026-10-27
- البصمات: `CHECKSUMS.sha256` (مسارات نسبية لهذا المجلد)

## ما يوجد هنا

| المسار | الأصل في النظام |
| --- | --- |
| `tokens.json` | `project/tokens.json` — المصدر الوحيد للقيم |
| `design-system.json` | `project/design-system.json` — الفهرس وسجلات الأصول (معرّفات blob) |
| `README.md`, `accessibility.md`, `languages.md` | دليل العلامة |
| `components/index.d.ts`, `components/bundle.css` | الأنواع والأنماط المرجعية |
| `components/src/*` | مصدر React 18 (IIFE على `window.React`) — مرجع للسلوك، لا يُستورد كما هو |
| `components/readmes/<Comp>.md` | إرشادات كل مكوّن |
| `assets/*.md` | أوصاف مجموعات Logos وAppIcons وIcons |
| `fonts/*.woff2` | IBM Plex Sans / Sans Arabic / Mono (400–700) |
| `licenses/*` | SIL OFL 1.1 (Plex) و ISC (Lucide) |

## ما لم يُجلب

- ملفات الشعار وأيقونة التطبيق والمفضلة (`assets/Logos/*`, `assets/AppIcons/*`) وأيقونات SVG الـ52: محفوظة في مخزن أصول النظام، ولا يُتاح جلبها إلا لحساب له صلاحية التحرير. المسارات الأصلية للشعار موجودة في `components/src/logo.js`.
- `tokens.css`: يولّده عارض النظام ولا يُنشر ملفًا.
- `artifact-type/*` و`SKILL.md`: خاصة بعارض claude.ai، لا علاقة لها بالتطبيق.

لا تعدّل هذه الملفات؛ عند تحديث النظام أعد جلبها وحدّث البصمات.
