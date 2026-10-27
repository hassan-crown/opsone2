# لقطات المرحلة P1 (الأساس البصري)

الالتقاط من المتصفح المدمج على `http://localhost:8084` (عرض 1280 للحاسوب و375 للجوال). صفحة `/_design` تظهر في بيئة `local` فقط.

| الملف | المحتوى |
| --- | --- |
| `design-desktop-{ar,en}-{light,dark}.jpg` | صفحة `/_design` كاملة، 4 تركيبات (لغة × ثيم) |
| `design-mobile-ar-light.jpg`, `design-mobile-en-dark.jpg` | `/_design` على 375px |
| `shell-desktop-dashboard-ar-light.jpg` | هيكل التطبيق: قائمة جانبية 264px على يمين RTL |
| `shell-desktop-settings-en-dark.jpg` | الهيكل بالإنجليزية الداكن، صفحة الإعدادات |
| `shell-mobile-ar-light.jpg`, `shell-mobile-drawer-ar-light.jpg` | الجوال: الشريط العلوي والدُّرج (يفتح من اليمين في RTL) |

تحقق آلي إضافي: لا تمرير أفقي عند 320px (`scrollWidth == clientWidth`) في `/_design` و`/dashboard`.
