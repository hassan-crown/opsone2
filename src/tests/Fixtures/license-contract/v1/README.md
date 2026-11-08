# متجهات عقد الترخيص — الإصدار 1

متجهات ذهبية مشتركة بين المركز (`opsone-central`) والمنشأة (`opsone2`) لعقد ADR-0004. المولّد هو
`App\Licensing\Contract\ContractVectors`، وكل ملف هنا ناتجه **بايتاً ببايت** (عدا هذا الملف). لا تعدّلها يدوياً.

```bash
php artisan license:contract-vectors          # إعادة التوليد
php artisan license:contract-vectors --check  # يفشل إن اختلف أي بايت
```

## الملفات

| الملف                   | المحتوى                                                                                                                                                                                                      |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `keys.json`             | زوج مفاتيح Ed25519 **غير سري** بـ `kid = opsone-contract-test`، مشتق من `sha256("opsone-contract-test/v1")`. المفتاحان base64 عادي (32 و64 بايتاً). لا يُوثق خارج الاختبارات، والمعرّف محجوز في سجل مفاتيح المركز |
| `envelopes/<case>.json` | الظرف كما يُركَّب في المنشأة: JSON منسّق بـ `JSON_PRETTY_PRINT \| JSON_UNESCAPED_SLASHES` وسطر أخير، وهو ناتج `LicenseEnvelope::sign()` في `opsone2` حرفياً                                                       |
| `payloads/<case>.json`  | بايتات الحمولة الموقّعة كما هي (دون سطر أخير)، للحالات الموقّعة بمفتاح العقد                                                                                                                                    |
| `expectations.json`     | إعداد المتحقق (`kid`، `public_key`، `host`، `deployment_id`) والنتيجة المتوقعة لكل حالة                                                                                                                      |

## صيغة `expectations.json`

كل عنصر في `cases`:

- `name`، `description`، `envelope` (مسار نسبي).
- **إما** `status_at`: قائمة `{at, status}` — الظرف يُقبل، و`LicensePayload::statusAt(at)` يساوي `status`
  (`provisioned`، `active`، `grace`، `wind_down`، `expired`).
- **أو** `problem`: قيمة `LicenseProblem` المتوقعة.
- `trusted` (اختياري): اسم حالة أخرى تمثل الترخيص الموثوق المركَّب قبله. `superseded` لا يقرره
  `LicenseVerifier` بل فحص الحالة المخزنة (`LicenseInstaller`/`CurrentLicense`): `issuedAt < trusted.issuedAt`.

## كيف تتحقق المنشأة (الدفعة T2)

1. انسخ المجلد إلى `src/tests/Fixtures/license-contract/v1/`.
2. ابنِ `new LicenseVerifier(new LicenseKeyring([kid => base64_decode(public_key)]), host, deployment_id)` بالحقن
   المباشر، لا عبر البيئة.
3. لكل حالة: `verify(file_get_contents(envelope))`، ثم قارن المشكلة أو الحالات، وطبّق `trusted` كما سبق.
4. اختياري وقوي: `LicenseEnvelope::sign(json_decode(payload), kid, secret_key)` يساوي ملف الظرف بايتاً ببايت.
