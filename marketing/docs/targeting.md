# دليل الاستهداف والإعلانات لصفحات القطاعات (OpsOne)

هذا الدليل يجمع لكل صفحة من صفحات القطاعات التسع: كلمات البحث، ومجموعات Google Ads بعناوينها وأوصافها، والكلمات السلبية، والجماهير على Meta وLinkedIn وSnapchat، وروابط UTM جاهزة، والمدن المستهدفة.
المصدر لكل قطاع: `solutions/<slug>/data.json` (الحقول `seo.*` و`hero` و`faq`) و`solutions/<slug>/RESEARCH.md` (المصطلحات، وما يجوز ادعاؤه وما لا يجوز).

## كيف تستخدم هذا الدليل

1. **الأرقام:** لا يحتوي الدليل أي رقم عن حجم البحث أو تكلفة النقرة أو الميزانية أو حجم الجمهور. كل خانة من هذا النوع مكتوب فيها «يُتحقق منه في Keyword Planner» (أو في أداة تقدير الجمهور في المنصة نفسها). لا تُضف أرقامًا إلى الدليل من غير مصدر.
2. **حدود الأحرف:** كل عنوان ≤ 30 حرفًا وكل وصف ≤ 90 حرفًا. عمود «عدد الأحرف» محسوب بسكربت (عدد المحارف بـ `[...str].length`)، لا يدويًا. إذا عدّلت نصًا فأعد تشغيل الفحص قبل النشر.
3. **ما يجوز قوله:** كل عنوان ووصف هنا مأخوذ من جدول «كل ادعاء في الصفحة ← قدرة حقيقية» في `RESEARCH.md` للقطاع. لا تُضف إلى أي إعلان:
   - إدارة الضمان، ولا أن الاستفسارات تصل من واتساب تلقائيًا.
   - كلمات «ثوري» أو «سلس» أو «الحل المتكامل»، ولا وصف النظام بأنه «عربي».
   - «ورشة/ورش»؛ نكتب دائمًا «مصنع/مصانع».
   - أسعار الاشتراك أو عروض الخصم أو أرقام عملاء أو شهادات عملاء.
4. **الصور في الإعلانات:** صور المنتج المنفّذ من الصفحة نفسها (مطبخ، غرفة ملابس، واجهة، مجلس…) أو لقطات شاشة النظام. لا تظهر نساء في أي صورة إعلانية.
5. **الكلمات الإنجليزية:** الصفحات عربية فقط الآن؛ الكلمات الإنجليزية الواردة في `seo.keywords` مذكورة هنا للمرجع وللاختبار المحدود بمطابقة العبارة، وتُراقَب في تقرير عبارات البحث.

## اصطلاحات التسمية

| العنصر | الصيغة | مثال |
|---|---|---|
| الحملة في Google Ads | `<slug_us>_search` (نفس قيمة `utm_campaign`) | `kitchens_search` |
| المجموعة الإعلانية | `ag_<topic>` (نفس قيمة `utm_content`) | `ag_showroom` |
| حملة Meta | `<slug_us>_meta` | `custom_furniture_meta` |
| حملة LinkedIn | `<slug_us>_linkedin` | `villa_fit_out_linkedin` |
| حملة Snapchat | `<slug_us>_snap` | `outdoor_spaces_snap` |
| مشاركة واتساب/عضوية | `<slug_us>_share` | `curtains_blinds_share` |

`<slug_us>` هو مسار الصفحة بعد تحويل الشرطة `-` إلى شرطة سفلية `_`. كل القيم بأحرف صغيرة وشرطات سفلية فقط.

## اصطلاح UTM

- الرابط الأساسي: `https://www.opsone.example/solutions/<slug>/`
- Google (بحث): `?utm_source=google&utm_medium=cpc&utm_campaign=<slug_us>_search&utm_content=<adgroup>&utm_term={keyword}`
  - `{keyword}` معامل ValueTrack تملؤه Google بالكلمة المطابقة؛ يُكتب كما هو. يُفضَّل وضع الجزء بعد `?` في «Final URL suffix» على مستوى المجموعة.
- Meta: `utm_source=meta&utm_medium=paid_social`
- LinkedIn: `utm_source=linkedin&utm_medium=paid_social`
- Snapchat: `utm_source=snapchat&utm_medium=paid_social`
- واتساب ومشاركة الفريق: `utm_source=whatsapp&utm_medium=organic_share`
- `utm_content` في القنوات الاجتماعية يصف الإعلان: `hero_static` (صورة الصفحة الرئيسية)، `journey_carousel` (رحلة الطلب)، `sponsored_post`، `story_ad`، `sales_team` (رابط يرسله فريق المبيعات).

## الكلمات السلبية المشتركة (تُضاف لكل الحملات قائمةً مشتركة)

مطابقة واسعة سلبية (broad negative) ما لم يُذكر غير ذلك:

- الباحثون عن عمل: وظائف، وظيفة، توظيف، مطلوب، رواتب، راتب، تدريب تعاوني، سيرة ذاتية
- المجاني والمقرصن: مجاني، مجانا، مجانًا، كراك، crack، تحميل، تنزيل، download، free، apk، مفعل
- التعلم: دورة، دورات، كورس، تعليم، تعلم، شرح، طريقة، يوتيوب، pdf، بحث تخرج، مشروع تخرج
- المستعمل والمبوّب: مستعمل، مستعملة، حراج، للبيع
- برامج المحاسبة (نية مختلفة؛ OpsOne ليس برنامج محاسبة): "برنامج محاسبة" بمطابقة العبارة، "فاتورة إلكترونية زاتكا" بمطابقة العبارة

**ملاحظة مهمة:** لا تضف «أسعار» أو «عرض سعر» أو «سعر» سلبيةً واسعة على مستوى الحساب، لأن بعض الكلمات المستهدفة نفسها تحتويها (مثل «برنامج عروض أسعار الألمنيوم» و«برنامج عرض سعر ستائر بالمتر»). كلمات الأسعار السلبية مكتوبة في كل قطاع بمطابقة العبارة أو المطابقة التامة.
**المنافسون:** لا يُضافون كلمات سلبية إلا إذا كانت النتيجة غير ذات صلة بوضوح.

---

## 1. معارض ومصانع المطابخ — `kitchens`

- الصفحة: https://www.opsone.example/solutions/kitchens/
- الجمهور (من `seo.audience`): معارض المطابخ ومصانعها في السعودية.
- ما لا يُقال في الإعلانات (من RESEARCH): الضمان، التقاط رسائل واتساب، حقول قياس خاصة بالمطابخ، المحاسبة.

### 1.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج معرض مطابخ | صريح النية (RESEARCH) | يُتحقق منه في Keyword Planner |
| برنامج إدارة مصنع مطابخ | نتائج ذات صلة | يُتحقق منه في Keyword Planner |
| برنامج إدارة معارض المطابخ | عنوان الصفحة | يُتحقق منه في Keyword Planner |
| برنامج مصنع مطابخ | صيغة مختصرة | يُتحقق منه في Keyword Planner |
| نظام إدارة معرض مطابخ | يُقرن بـ«معرض» لأن «نظام إدارة مطابخ» وحدها تختلط بالمطاعم | يُتحقق منه في Keyword Planner |
| kitchen showroom software | للمرجع | يُتحقق منه في Keyword Planner |
| kitchen business management software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج متابعة طلبات المطابخ من القياس إلى التركيب
- برنامج عروض أسعار وعقود لمعرض مطابخ
- نظام مواعيد قياس المطابخ
- برنامج إدارة دفعات عملاء المطابخ
- برنامج أوامر تصنيع لمصنع مطابخ
- برنامج توقيع عقد مطبخ إلكترونيًا
- نظام إدارة مبيعات معرض مطابخ
- برنامج إدارة مندوبي مبيعات المطابخ
- برنامج إدارة مصنع مطابخ خشب
- برنامج متابعة تركيب المطابخ بالصور

### 1.2 Google Ads

#### المجموعة `ag_showroom` — إدارة معرض المطابخ

الكلمات: `[برنامج معرض مطابخ]`، `[برنامج إدارة معارض المطابخ]`، `"نظام إدارة معرض مطابخ"`، `"برنامج مبيعات معرض مطابخ"`، `"kitchen showroom software"`

Final URL: https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_showroom&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة معارض المطابخ | 26 |
| 2 | كل مطبخ من القياس للتسليم | 25 |
| 3 | موعد القياس وتذكير العميل | 25 |
| 4 | عقد يوقعه العميل من جواله | 25 |
| 5 | العربون والدفعات في الطلب | 25 |
| 6 | اعتماد التصميم من الجوال | 24 |
| 7 | صور التركيب وتوقيع العميل | 25 |
| 8 | احجز عرضًا على طلباتك | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | سجّل زائر المعرض، واحجز موعد القياس، وأرسل العرض والعقد، وتابع العربون والدفعات. | 80 |
| 2 | أمر تصنيع للمصنع بموعد متوقع، ثم التركيب بصور قبل وبعد وتوقيع العميل على الاستلام. | 82 |
| 3 | خطط 40/40/20 و50/50 أو حتى 12 دفعة مربوطة بالعقد واعتماد التصميم والتسليم. | 74 |

#### المجموعة `ag_factory` — مصنع المطابخ

الكلمات: `[برنامج إدارة مصنع مطابخ]`، `[برنامج مصنع مطابخ]`، `"نظام إدارة مصنع مطابخ"`، `"برنامج أوامر تصنيع مطابخ"`، `"kitchen business management software"`

Final URL: https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_factory&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة مصنع مطابخ | 23 |
| 2 | أمر التصنيع بموعد متوقع | 23 |
| 3 | مراحل القص والتجميع والفحص | 26 |
| 4 | فحص الجودة بصورة قبل التسليم | 28 |
| 5 | لوحة المصانع وما تأخر | 21 |
| 6 | الاستلام مع المرفوض وسببه | 25 |
| 7 | المقاسات تخرج مع أمر الشراء | 27 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | مراحل التصنيع الداخلي: الخامات والقص والتجميع وفحص الجودة بصورة، لكل مرحلة مسؤول ومدة. | 86 |
| 2 | أمر الشراء للمصنع يحمل المقاسات ومرفقاتها، ولوحة المصانع تعرض أي أمر تأخر. | 74 |
| 3 | تنبيه حين تتأخر مهمة، والعميل يرى مرحلة طلبه في صفحته دون اتصال. | 64 |

#### المجموعة `ag_sales` — العروض والعقود والبيع الميداني

الكلمات: `"برنامج عروض أسعار مطابخ"`، `"برنامج عقود مطابخ"`، `"برنامج مندوب مبيعات مطابخ"`، `"برنامج دفعات عملاء المطابخ"`

Final URL: https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_sales&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | عرض سعر وعقد لكل مطبخ | 21 |
| 2 | توقيع العقد برمز تحقق | 21 |
| 3 | بائعك الميداني يقيس ويرسل | 25 |
| 4 | عمولة البائع على ما يبيعه | 25 |
| 5 | تذكير قبل الدفعة وسند مرقم | 26 |
| 6 | تقرير لكل قناة ولكل بائع | 24 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | البائع يرسل عرض السعر ورابط توقيع العقد من زيارة القياس، والطلب يُنسب له ولقناته. | 81 |
| 2 | العميل يوقّع العقد من جواله برمز تحقق، ويصله تذكير قبل الدفعة وسند مرقّم بعدها. | 79 |
| 3 | حد خصم لكل بائع وما فوقه يعتمده المدير، وتقرير لكل قناة ولكل بائع. | 66 |

### 1.3 الكلمات السلبية

- مطابقة واسعة: مطعم، مطاعم، كافيه، كوفي، مطبخ مركزي، مطابخ سحابية، cloud kitchen، وصفات، طبخ، أدوات مطبخ، أجهزة مطبخ، ايكيا
- مطابقة العبارة: "أسعار مطابخ"، "سعر متر المطابخ"، "مطابخ للبيع"، "برنامج تصميم مطابخ"، "تصميم مطابخ 3d"، "kitchen design software"
- مطابقة تامة: [تفصيل مطابخ]، [مطابخ الرياض]، [مطابخ جدة] (نية مستهلك حسب RESEARCH)

### 1.4 الجماهير

- **Meta:** اهتمامات قريبة من Interior design وKitchen design وFurniture وSmall business وEntrepreneurship (تحقق من الأسماء الحالية في Ads Manager)؛ مسميات وظيفية مثل Owner وGeneral Manager وSales Manager ومدير معرض؛ سلوك Business page admins. جمهور مخصص من زوار `/solutions/kitchens/` (إن كان البكسل مركّبًا) ومن فتحوا نموذج الطلب؛ ثم جمهور مشابه 1% في السعودية من قائمة طلبات العرض حين تتكوّن.
- **LinkedIn:** الصناعات Furniture and Home Furnishings Manufacturing وRetail Furniture and Home Furnishings وWood Product Manufacturing وInterior Design؛ المسميات Owner وFounder وGeneral Manager وShowroom Manager وFactory Manager وSales Manager؛ حجم الشركة 2–10 و11–50 و51–200.
- **Snapchat:** فئات نمط الحياة القريبة من Home Decor وHome Improvement وBusiness/Entrepreneurs؛ الأعمار 25–54؛ موجّه لأصحاب المعارض والمنشآت (الرسالة في الإعلان تخاطب صاحب المعرض لا المستهلك).

### 1.5 روابط UTM

```
google   ag_showroom  https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_showroom&utm_term={keyword}
google   ag_factory   https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_factory&utm_term={keyword}
google   ag_sales     https://www.opsone.example/solutions/kitchens/?utm_source=google&utm_medium=cpc&utm_campaign=kitchens_search&utm_content=ag_sales&utm_term={keyword}
meta                  https://www.opsone.example/solutions/kitchens/?utm_source=meta&utm_medium=paid_social&utm_campaign=kitchens_meta&utm_content=hero_static
linkedin              https://www.opsone.example/solutions/kitchens/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=kitchens_linkedin&utm_content=sponsored_post
snapchat              https://www.opsone.example/solutions/kitchens/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=kitchens_snap&utm_content=story_ad
whatsapp              https://www.opsone.example/solutions/kitchens/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=kitchens_share&utm_content=sales_team
```

### 1.6 المدن المستهدفة

- الرياض: العاصمة وأكبر مدن المملكة.
- جدة
- الدمام والخبر
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط

---

## 2. معارض الخزائن وغرف الملابس والأثاث حسب الطلب — `custom-furniture`

- الصفحة: https://www.opsone.example/solutions/custom-furniture/
- الجمهور: معارض الخزائن وغرف الملابس والأبواب الخشبية والأثاث حسب الطلب والمجالس والتنجيد ومصانعها في السعودية.
- ما لا يُقال: الضمان، التقاط رسائل واتساب، حقول قياس خاصة بالخزائن، «ورش الموبيليا» (نكتب «مصنع أثاث»).

### 2.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة معرض أثاث | لا نتائج برمجية سعودية ظاهرة | يُتحقق منه في Keyword Planner |
| برنامج مصنع أثاث حسب الطلب | الكلمة المستهدفة بدل «ورش الموبيليا» | يُتحقق منه في Keyword Planner |
| برنامج إدارة غرف الملابس والخزائن | `seo.keywords` | يُتحقق منه في Keyword Planner |
| نظام إدارة مصنع خزائن | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج تفصيل مجالس وتنجيد | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج مصنع أبواب خشب | `seo.keywords` | يُتحقق منه في Keyword Planner |
| custom furniture showroom software | للمرجع | يُتحقق منه في Keyword Planner |
| wardrobe and closet business software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج إدارة طلبات الأثاث حسب الطلب
- برنامج عروض أسعار غرف الملابس
- برنامج إدارة معرض مجالس وكنب
- نظام إدارة مصنع دواليب
- برنامج أوامر شراء لمصنع أثاث
- برنامج متابعة تركيب الخزائن
- برنامج إدارة محل تنجيد
- برنامج إدارة مصنع أبواب خشب داخلية
- برنامج تسعير الأثاث بالمتر المربع والطولي
- برنامج مبيعات معرض أثاث مع التوصيل والتركيب

### 2.2 Google Ads

#### المجموعة `ag_wardrobes` — غرف الملابس والخزائن

الكلمات: `[برنامج إدارة غرف الملابس والخزائن]`، `[نظام إدارة مصنع خزائن]`، `"برنامج معرض خزائن"`، `"برنامج عروض أسعار غرف الملابس"`، `"wardrobe and closet business software"`

Final URL: https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_wardrobes&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج معارض غرف الملابس | 24 |
| 2 | كل خزانة من المقاس للتركيب | 26 |
| 3 | الخامة كما اختارها العميل | 25 |
| 4 | اعتماد التصميم من الجوال | 24 |
| 5 | العقد والعربون في الطلب | 23 |
| 6 | تسعير بالمتر المربع أو القطعة | 29 |
| 7 | التركيب بتوقيع العميل | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | زيارة القياس، والخامة كما اختارها العميل محفوظة مع البند، والتصميم يعتمده من جواله. | 83 |
| 2 | أمر المصنع يخرج بالخامات والمقاسات وصور التصميم، ثم التوصيل والتركيب بتوقيع العميل. | 83 |
| 3 | خطط 40/40/20 و50/50 أو حتى 12 دفعة مربوطة بالعقد واعتماد التصميم والتسليم. | 74 |

#### المجموعة `ag_majlis_upholstery` — المجالس والتنجيد

الكلمات: `[برنامج تفصيل مجالس وتنجيد]`، `"برنامج إدارة محل تنجيد"`، `"برنامج معرض مجالس"`، `"برنامج إدارة معرض أثاث"`

Final URL: https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_majlis_upholstery&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج تفصيل مجالس وتنجيد | 25 |
| 2 | القماش محفوظ مع كل بند | 22 |
| 3 | تسعير المجلس بالمتر الطولي | 26 |
| 4 | عرض سعر وعقد من الجوال | 22 |
| 5 | أمر المصنع بالقماش والمقاس | 26 |
| 6 | التوصيل بتوقيع العميل | 21 |
| 7 | احجز عرضًا على طلباتك | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | القماش والخامة خياران من المنتج، يُحفظان مع البند ويخرجان مع أمر الشراء للمصنع. | 79 |
| 2 | عرض سعر ببنود بالمتر الطولي أو بالقطعة، وعقد يوقّعه العميل من جواله مع العربون. | 79 |
| 3 | لكل مرحلة مسؤول ومدة، وتنبيه حين تتأخر مهمة، والعميل يرى مرحلة طلبه في صفحته. | 77 |

#### المجموعة `ag_furniture_factory` — مصنع الأثاث والأبواب

الكلمات: `[برنامج مصنع أثاث حسب الطلب]`، `[برنامج مصنع أبواب خشب]`، `"برنامج إدارة مصنع أثاث"`، `"custom furniture showroom software"`

Final URL: https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_furniture_factory&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج مصنع أثاث حسب الطلب | 26 |
| 2 | برنامج مصنع أبواب خشب | 21 |
| 3 | مراحل القص والتجميع والفحص | 26 |
| 4 | فحص الجودة بصورة إلزامية | 24 |
| 5 | لوحة المصانع وما تأخر | 21 |
| 6 | الاستلام مع المرفوض وسببه | 25 |
| 7 | متجر للقطع الجاهزة | 18 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | مراحل التصنيع الداخلي: تجهيز المواد والقص والتجميع وفحص الجودة بصورة، لكل مرحلة مدة. | 84 |
| 2 | أمر شراء للمصنع بموعد متوقع، والاستلام يسجّل المرفوض وسببه، ولوحة تعرض ما تأخر. | 79 |
| 3 | القطع الجاهزة بمتغيراتها تُباع في المتجر من المخزون نفسه بدفع إلكتروني. | 71 |

### 2.3 الكلمات السلبية

- مطابقة واسعة: ايكيا، نقل عفش، عفش، سكتش اب، 3d، اوتوكاد
- مطابقة العبارة: "أثاث للبيع"، "أسعار غرف نوم"، "أسعار غرف ملابس"، "سعر متر الخزائن"، "برنامج تصميم غرف ملابس"، "برنامج تصميم أثاث"، "طريقة تنجيد"، "تنجيد كنب بنفسك"
- مطابقة تامة: [تفصيل دواليب]، [تفصيل غرف ملابس]، [تفصيل خزائن ملابس]، [دريسنج روم] (نية مستهلك حسب RESEARCH)

### 2.4 الجماهير

- **Meta:** اهتمامات قريبة من Furniture وInterior design وWoodworking وUpholstery وSmall business؛ مسميات Owner وGeneral Manager وFactory Manager ومدير معرض أثاث؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة ومن فتحوا النموذج، ثم جمهور مشابه 1% في السعودية من قائمة طلبات العرض.
- **LinkedIn:** الصناعات Furniture and Home Furnishings Manufacturing وRetail Furniture and Home Furnishings وWood Product Manufacturing وInterior Design؛ المسميات Owner وFounder وGeneral Manager وFactory Manager وProduction Manager وShowroom Manager؛ حجم الشركة 2–10 و11–50 و51–200.
- **Snapchat:** فئات قريبة من Home Decor وHome Improvement وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة موجهة لصاحب المعرض أو المصنع.

### 2.5 روابط UTM

```
google   ag_wardrobes          https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_wardrobes&utm_term={keyword}
google   ag_majlis_upholstery  https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_majlis_upholstery&utm_term={keyword}
google   ag_furniture_factory  https://www.opsone.example/solutions/custom-furniture/?utm_source=google&utm_medium=cpc&utm_campaign=custom_furniture_search&utm_content=ag_furniture_factory&utm_term={keyword}
meta                           https://www.opsone.example/solutions/custom-furniture/?utm_source=meta&utm_medium=paid_social&utm_campaign=custom_furniture_meta&utm_content=hero_static
linkedin                       https://www.opsone.example/solutions/custom-furniture/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=custom_furniture_linkedin&utm_content=sponsored_post
snapchat                       https://www.opsone.example/solutions/custom-furniture/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=custom_furniture_snap&utm_content=story_ad
whatsapp                       https://www.opsone.example/solutions/custom-furniture/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=custom_furniture_share&utm_content=sales_team
```

### 2.6 المدن المستهدفة

- الرياض: العاصمة وأكبر مدن المملكة.
- جدة
- الدمام والخبر: ظهرت فيهما صفحات مصانع ومحلات تفصيل الكنب والمجالس في بحث الصفحة.
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- حائل

---

## 3. شركات ومصانع الألمنيوم والزجاج وUPVC — `aluminium-glass`

- الصفحة: https://www.opsone.example/solutions/aluminium-glass/
- الجمهور: شركات ومصانع الألمنيوم ونوافذ وأبواب UPVC والزجاج والمرايا وكبائن الاستحمام والواجهات الزجاجية في السعودية.
- ما لا يُقال: الضمان (ومنه «ضمان عدم التهريب»)، قوائم القص وتحسين الهدر ورسم القطاعات، حقول عرض/ارتفاع مقترنة، التقاط رسائل واتساب، المحاسبة.

### 3.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة مصنع ألمنيوم | نتائج ذات صلة | يُتحقق منه في Keyword Planner |
| برنامج شركة ألمنيوم وزجاج | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج عروض أسعار الألمنيوم | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج إدارة مصنع UPVC | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج إدارة الزجاج والسكوريت | `seo.keywords` | يُتحقق منه في Keyword Planner |
| نظام إدارة شركة ألمنيوم | صيغة سعودية شائعة | يُتحقق منه في Keyword Planner |
| aluminium fabricator software | للمرجع | يُتحقق منه في Keyword Planner |
| window and door business software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج إدارة طلبات نوافذ وأبواب الألمنيوم
- برنامج تسجيل قياس الفتحات قبل القص
- برنامج عرض سعر ألمنيوم بالمتر المربع
- برنامج إدارة شركة واجهات زجاجية
- برنامج إدارة محل مرايا وكبائن شاور
- نظام متابعة تركيب نوافذ UPVC
- برنامج عقود وعربون شركات الألمنيوم
- برنامج أوامر تصنيع لمصنع ألمنيوم
- برنامج مبيعات ميدانية لشركات الألمنيوم
- برنامج إدارة مصنع شبابيك دبل جلاس

### 3.2 Google Ads

#### المجموعة `ag_aluminium_factory` — مصنع وشركة الألمنيوم

الكلمات: `[برنامج إدارة مصنع ألمنيوم]`، `[برنامج شركة ألمنيوم وزجاج]`، `"نظام إدارة شركة ألمنيوم"`، `"برنامج عروض أسعار الألمنيوم"`، `"aluminium fabricator software"`

Final URL: https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_aluminium_factory&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة مصنع ألمنيوم | 25 |
| 2 | قياس نهائي لكل فتحة قبل القص | 28 |
| 3 | عرض سعر بالمتر المربع | 21 |
| 4 | العقد والعربون قبل التصنيع | 26 |
| 5 | أمر القص للمصنع بموعد متوقع | 27 |
| 6 | صور التركيب وتوقيع العميل | 25 |
| 7 | حد خصم لكل مندوب | 16 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | مسار الألمنيوم الجاهز لا يتخطى زيارة القياس، وكل مقاس محفوظ بموقعه ووحدته ومن قاسه. | 83 |
| 2 | عرض سعر بكمية عشرية ووحدة حرة، وعقد يوقّعه العميل إلكترونيًا مع العربون. | 72 |
| 3 | ملاحظات التركيب مثل القطعة الناقصة أو التلف تمنع إغلاق الطلب حتى تُعالج. | 72 |

#### المجموعة `ag_upvc_windows` — نوافذ وأبواب UPVC

الكلمات: `[برنامج إدارة مصنع UPVC]`، `"برنامج نوافذ UPVC"`، `"برنامج شركة نوافذ وأبواب"`، `"window and door business software"`

Final URL: https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_upvc_windows&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة مصنع UPVC | 22 |
| 2 | نوافذ وأبواب UPVC في طلب واحد | 29 |
| 3 | مقاس كل نافذة بموقعها | 21 |
| 4 | العميل يرى قيد التصنيع | 22 |
| 5 | تذكير قبل القياس والتركيب | 25 |
| 6 | دفعات مربوطة بالعقد والتسليم | 28 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | كل نافذة وباب بمقاسه وموقعه في الطلب، تقديري أو نهائي، والمرفقات تخرج مع أمر الشراء. | 84 |
| 2 | تذكير العميل قبل موعد القياس والتركيب، وتنبيه حين يتعارض موعدان لنفس الفريق. | 76 |
| 3 | خطط 50/50 و40/40/20 أو حتى 12 دفعة مربوطة بالعقد وبدء مرحلة واكتمال التسليم. | 76 |

#### المجموعة `ag_glass_mirrors` — الزجاج والمرايا والواجهات

الكلمات: `[برنامج إدارة الزجاج والسكوريت]`، `"برنامج شركة زجاج"`، `"برنامج إدارة شركة واجهات زجاجية"`، `"برنامج محل مرايا"`

Final URL: https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_glass_mirrors&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج شركات الزجاج والمرايا | 28 |
| 2 | كبائن الاستحمام والسكوريت | 25 |
| 3 | واجهات زجاجية حتى التسليم | 25 |
| 4 | تسعير بالمتر المربع أو القطعة | 29 |
| 5 | عقد يوقعه العميل من جواله | 25 |
| 6 | مندوبك الميداني يقيس ويرسل | 26 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | للزجاج والمرايا وكبائن الاستحمام والواجهات: قياس، وعرض سعر، وعقد وعربون، ثم أمر للمصنع. | 87 |
| 2 | المندوب يرى فرصه فقط، يقيس ويصوّر ويرسل العرض ورابط التوقيع، والطلب يُنسب له. | 77 |
| 3 | صور قبل التركيب وبعده، وتوقيع العميل على الجهاز أو من صفحته عند الاستلام. | 73 |

### 3.3 الكلمات السلبية

- مطابقة واسعة: سهم، أسهم، بورصة، تداول، خردة، سكراب، علب، رقائق، قصدير، معادن
- مطابقة العبارة: "سعر متر الألمنيوم"، "أسعار الألمنيوم اليوم"، "سعر طن الألمنيوم"، "سعر متر الزجاج"، "برنامج تقطيع الألمنيوم"، "قوائم القص"، "تحسين الهدر"، "رسم قطاعات"، "ورق ألمنيوم"
- مطابقة تامة: [تفصيل شبابيك ألمنيوم]، [شبابيك دبل جلاس]، [كبائن شاور سكوريت] (نية مستهلك حسب RESEARCH)
- لا تضف «أسعار» واسعةً هنا: «برنامج عروض أسعار الألمنيوم» كلمة مستهدفة.

### 3.4 الجماهير

- **Meta:** اهتمامات قريبة من Construction وWindows and doors وGlass وArchitecture وSmall business؛ مسميات Owner وGeneral Manager وFactory Manager وProject Manager؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية من قائمة طلبات العرض.
- **LinkedIn:** الصناعات Glass, Ceramics and Concrete Manufacturing وFabricated Metal Products وConstruction وBuilding Construction وArchitecture and Planning؛ المسميات Owner وGeneral Manager وFactory Manager وProduction Manager وSales Manager وEstimator؛ حجم الشركة 2–10 و11–50 و51–200 و201–500.
- **Snapchat:** فئات قريبة من Home Improvement وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة موجهة لصاحب الشركة أو المصنع.

### 3.5 روابط UTM

```
google   ag_aluminium_factory  https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_aluminium_factory&utm_term={keyword}
google   ag_upvc_windows       https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_upvc_windows&utm_term={keyword}
google   ag_glass_mirrors      https://www.opsone.example/solutions/aluminium-glass/?utm_source=google&utm_medium=cpc&utm_campaign=aluminium_glass_search&utm_content=ag_glass_mirrors&utm_term={keyword}
meta                           https://www.opsone.example/solutions/aluminium-glass/?utm_source=meta&utm_medium=paid_social&utm_campaign=aluminium_glass_meta&utm_content=hero_static
linkedin                       https://www.opsone.example/solutions/aluminium-glass/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=aluminium_glass_linkedin&utm_content=sponsored_post
snapchat                       https://www.opsone.example/solutions/aluminium-glass/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=aluminium_glass_snap&utm_content=story_ad
whatsapp                       https://www.opsone.example/solutions/aluminium-glass/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=aluminium_glass_share&utm_content=sales_team
```

### 3.6 المدن المستهدفة

- الرياض: ظهرت فيها وظائف مديري مصانع ألمنيوم في بحث الصفحة.
- جدة
- الدمام والخبر والجبيل: المنطقة الشرقية مركز صناعي.
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك
- جازان

---

## 4. محلات ومعارض الستائر والبلايند — `curtains-blinds`

- الصفحة: https://www.opsone.example/solutions/curtains-blinds/
- الجمهور: محلات ومعارض الستائر والبلايند والمفروشات حسب المقاس في السعودية.
- ما لا يُقال: الضمان، حساب القماش من المقاس، تشغيل الموتور أو ربطه بالبيت الذكي (الموتور بند في العرض فقط)، قالب مسار خاص بالستائر، التقاط رسائل واتساب.

### 4.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج محل ستائر | نية صريحة ومنافسة ضعيفة (RESEARCH) | يُتحقق منه في Keyword Planner |
| برنامج إدارة محلات الستائر | العنوان | يُتحقق منه في Keyword Planner |
| برنامج مصنع ستائر | نية صريحة | يُتحقق منه في Keyword Planner |
| نظام إدارة محل ستائر | صيغة سعودية شائعة | يُتحقق منه في Keyword Planner |
| برنامج معرض ستائر وبلايند | الكلمات المستهدفة | يُتحقق منه في Keyword Planner |
| برنامج عرض سعر ستائر بالمتر | من «عرض سعر ستائر بالمتر» | يُتحقق منه في Keyword Planner |
| curtain showroom software | للمرجع | يُتحقق منه في Keyword Planner |
| blinds business software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج تسجيل مقاسات النوافذ للستائر
- برنامج مواعيد قياس وتركيب الستائر
- برنامج إدارة طلبات تفصيل ستائر حسب المقاس
- برنامج عربون ودفعات محل ستائر
- نظام أوامر تفصيل لمصنع ستائر
- برنامج إدارة محل مفروشات حسب المقاس
- برنامج متجر إلكتروني للستائر الجاهزة والرول
- برنامج مندوب قياس ستائر
- برنامج إدارة فريق تركيب الستائر
- برنامج مخزون أقمشة الستائر

ملاحظة: «تفصيل ستائر حسب المقاس» في `seo.keywords` كلمة للصفحة (SEO)، لكنها نية مستهلك في الإعلانات؛ تُضاف سلبيةً تامة في Google Ads.

### 4.2 Google Ads

#### المجموعة `ag_curtain_shop` — محل ومعرض الستائر

الكلمات: `[برنامج محل ستائر]`، `[برنامج إدارة محلات الستائر]`، `"نظام إدارة محل ستائر"`، `"برنامج معرض ستائر"`، `"برنامج عرض سعر ستائر"`، `"curtain showroom software"`

Final URL: https://www.opsone.example/solutions/curtains-blinds/?utm_source=google&utm_medium=cpc&utm_campaign=curtains_blinds_search&utm_content=ag_curtain_shop&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة محل ستائر | 22 |
| 2 | كل نافذة من العينة للتركيب | 26 |
| 3 | مقاس كل نافذة محفوظ بالطلب | 26 |
| 4 | عرض سعر الستائر بالمتر | 22 |
| 5 | العربون والدفعات في الطلب | 25 |
| 6 | موعد قياس وتذكير العميل | 23 |
| 7 | تركيب بصور وتوقيع العميل | 24 |
| 8 | احجز عرضًا على طلباتك | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | موعد القياس في بيت العميل، ومقاس كل نافذة بالغرفة والوحدة، تقديري أو نهائي، ومن قاسه. | 85 |
| 2 | عرض سعر بالمتر الطولي أو المربع أو بالقطعة، بخيارات القماش والبطانة والموتور. | 77 |
| 3 | عقد يوقّعه العميل من جواله، وخطط 50/50 أو 40/40/20 أو خطة تكتبها بنفسك. | 71 |
| 4 | صور قبل التركيب وبعده، وملاحظات تمنع إغلاق الطلب، وتوقيع العميل على الاستلام. | 77 |

#### المجموعة `ag_curtain_factory` — مصنع الستائر والبلايند

الكلمات: `[برنامج مصنع ستائر]`، `"برنامج إدارة مصنع ستائر"`، `"برنامج مصنع بلايند"`، `"blinds business software"`

Final URL: https://www.opsone.example/solutions/curtains-blinds/?utm_source=google&utm_medium=cpc&utm_campaign=curtains_blinds_search&utm_content=ag_curtain_factory&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج مصنع ستائر | 17 |
| 2 | أمر التفصيل للمصنع | 18 |
| 3 | الأقمشة بوحدتها ومورّدها | 24 |
| 4 | حجز القماش من المخزون للطلب | 27 |
| 5 | الاستلام مع المرفوض وسببه | 25 |
| 6 | مسؤول ومدة لكل مرحلة | 20 |
| 7 | متجر للرول والسكك الجاهزة | 25 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | أمر تفصيل للمصنع أو المورّد بموعد متوقع، ولوحة تعرض ما تأخر، والاستلام يسجّل المرفوض. | 85 |
| 2 | الأقمشة خامات بوحدتها ومورّدها، تُحجز من المخزون للطلب وتُصرف يدويًا. | 69 |
| 3 | المتجر يبيع الستائر الجاهزة والرول والسكك من المخزون نفسه بدفع إلكتروني وروابط سلة. | 83 |

### 4.3 الكلمات السلبية

- مطابقة واسعة: ايكيا، خياطة، ستائر سيارات، ستارة حمام، ستائر مسرح، حاسبة
- مطابقة العبارة: "أسعار ستائر"، "سعر متر القماش"، "سعر متر الستائر"، "ستائر للبيع"، "ستائر مستعملة"، "طريقة تفصيل ستائر"، "تفصيل ستائر بنفسك"، "حساب قماش الستارة"، "قماش ستائر جملة"
- مطابقة تامة: [تفصيل ستائر]، [تفصيل وتركيب ستائر]، [تفصيل ستائر حسب المقاس]، [محلات ستائر]، [ستائر حسب المقاس]
- لا تضف «عرض سعر» سلبية: «برنامج عرض سعر ستائر بالمتر» كلمة مستهدفة.

### 4.4 الجماهير

- **Meta:** اهتمامات قريبة من Curtain وWindow blind وInterior design وHome textiles وSmall business؛ مسميات Owner وShop Manager ومدير محل؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة ومن فتحوا النموذج، ثم جمهور مشابه 1% في السعودية.
- **LinkedIn:** الصناعات Textile Manufacturing وRetail Furniture and Home Furnishings وInterior Design وRetail؛ المسميات Owner وFounder وGeneral Manager وStore Manager وSales Manager؛ حجم الشركة 2–10 و11–50. (جمهور LinkedIn لهذا القطاع قد يكون ضيقًا؛ حجمه يُتحقق منه في Campaign Manager).
- **Snapchat:** فئات قريبة من Home Decor وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة لصاحب المحل.

### 4.5 روابط UTM

```
google   ag_curtain_shop     https://www.opsone.example/solutions/curtains-blinds/?utm_source=google&utm_medium=cpc&utm_campaign=curtains_blinds_search&utm_content=ag_curtain_shop&utm_term={keyword}
google   ag_curtain_factory  https://www.opsone.example/solutions/curtains-blinds/?utm_source=google&utm_medium=cpc&utm_campaign=curtains_blinds_search&utm_content=ag_curtain_factory&utm_term={keyword}
meta                         https://www.opsone.example/solutions/curtains-blinds/?utm_source=meta&utm_medium=paid_social&utm_campaign=curtains_blinds_meta&utm_content=hero_static
linkedin                     https://www.opsone.example/solutions/curtains-blinds/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=curtains_blinds_linkedin&utm_content=sponsored_post
snapchat                     https://www.opsone.example/solutions/curtains-blinds/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=curtains_blinds_snap&utm_content=story_ad
whatsapp                     https://www.opsone.example/solutions/curtains-blinds/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=curtains_blinds_share&utm_content=sales_team
```

### 4.6 المدن المستهدفة

- الرياض: ظهرت فيها عناوين محلات «تفصيل وتركيب ستائر» وإعلانات «حسب المقاس» في بحث الصفحة.
- الدمام والخبر: ظهرت في الخبر عناوين محلات ستائر في بحث الصفحة.
- جدة
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك
- حائل

---

## 5. شركات التشطيب وتجهيز الفلل والمكاتب — `villa-fit-out`

- الصفحة: https://www.opsone.example/solutions/villa-fit-out/
- الجمهور: شركات تصميم وتشطيب الفلل والشقق وتجهيز المكاتب والمحلات في السعودية.
- ما لا يُقال: «قالب تشطيب جاهز» (الإعداد يبدأ من المسار الافتراضي ويُسمّى في المحرر)، المستخلصات ونسب الإنجاز، «أوامر التغيير» بهذا الاسم، مخطط Gantt، الضمان، التقاط رسائل واتساب.

### 5.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة شركات التشطيب | نية مشترٍ ومنافسة ضعيفة (RESEARCH) | يُتحقق منه في Keyword Planner |
| نظام إدارة مشاريع التشطيب | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج شركة تشطيبات | من «شركة تشطيبات» | يُتحقق منه في Keyword Planner |
| برنامج إدارة شركات تجهيز المكاتب | من «تجهيز مكاتب ومحلات» | يُتحقق منه في Keyword Planner |
| برنامج إدارة مشاريع الديكور والتشطيب | من «تشطيبات وديكور» | يُتحقق منه في Keyword Planner |
| برنامج شركة تصميم وتنفيذ | من صيغة الإعلانات «تصميم وتنفيذ» | يُتحقق منه في Keyword Planner |
| fit-out company management software | للمرجع | يُتحقق منه في Keyword Planner |
| interior fit-out CRM | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج عرض سعر تشطيب بجدول الكميات
- برنامج دفعات مشاريع التشطيب حسب المراحل
- نظام إدارة مشاريع تشطيب فلل
- برنامج إدارة مشاريع تشطيب شقق
- برنامج أوامر شراء الموردين لشركة تشطيب
- برنامج ملاحظات الاستلام وتوقيع العميل
- نظام إدارة شركات تجهيز المحلات التجارية
- برنامج اعتماد التصميم الداخلي من العميل
- برنامج عقود إلكترونية لشركات التشطيب
- برنامج إدارة مشاريع فت أوت

ملاحظة: «تشطيب فلل» و«تشطيب شقق» في `seo.keywords` للصفحة، لكنها نية مستهلك في الإعلانات؛ لا تُستهدف إلا مقرونة بـ«برنامج/نظام إدارة».

### 5.2 Google Ads

#### المجموعة `ag_fitout_company` — شركات التشطيب

الكلمات: `[برنامج إدارة شركات التشطيب]`، `[نظام إدارة مشاريع التشطيب]`، `"برنامج شركة تشطيبات"`، `"برنامج إدارة مشاريع تشطيب"`، `"fit-out company management software"`

Final URL: https://www.opsone.example/solutions/villa-fit-out/?utm_source=google&utm_medium=cpc&utm_campaign=villa_fit_out_search&utm_content=ag_fitout_company&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج إدارة شركات التشطيب | 26 |
| 2 | كل مشروع من المعاينة للاستلام | 29 |
| 3 | اعتماد التصميم من الجوال | 24 |
| 4 | عرض سعر بجدول الكميات | 21 |
| 5 | دفعات مربوطة بمراحل التنفيذ | 27 |
| 6 | أوامر الموردين بموعد متوقع | 26 |
| 7 | الاستلام بالصور وتوقيع العميل | 29 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | المعاينة بمقاساتها وصور الموقع، والتصميم بنسخه يعتمده العميل، ثم العرض والعقد الموقّع. | 86 |
| 2 | خطة حتى 12 دفعة مربوطة بالعقد أو ببدء مرحلة بعينها أو باكتمال التسليم. | 70 |
| 3 | ملاحظات الاستلام تبقى مفتوحة وتمنع إغلاق المشروع حتى تُعالج، بصور قبل وبعد. | 75 |

#### المجموعة `ag_offices_shops` — تجهيز المكاتب والمحلات

الكلمات: `[برنامج إدارة شركات تجهيز المكاتب]`، `"برنامج تجهيز محلات تجارية"`، `"برنامج شركة تصميم وتنفيذ"`، `"interior fit-out CRM"`

Final URL: https://www.opsone.example/solutions/villa-fit-out/?utm_source=google&utm_medium=cpc&utm_campaign=villa_fit_out_search&utm_content=ag_offices_shops&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج شركات تجهيز المكاتب | 26 |
| 2 | تجهيز المحلات من المعاينة | 25 |
| 3 | أمر شراء لكل مورّد ومصنع | 24 |
| 4 | تنبيه حين تتأخر مهمة | 20 |
| 5 | العميل يرى مرحلة مشروعه | 23 |
| 6 | تعديل بسعر جديد يعتمده العميل | 29 |
| 7 | احجز عرضًا على مشاريعك | 22 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | أمر شراء لكل مورّد أو مصنع مربوط بمرحلته، والكمية المرفوضة تبقي الإجراء مفتوحًا. | 80 |
| 2 | مراحل بمسؤول ومدة تسمّيها كما تعمل، وتنبيه حين تتأخر مهمة عن موعدها. | 68 |
| 3 | تغيير بعد الاتفاق يصدر له تعديل بسعر جديد يعتمده العميل. | 56 |

### 5.3 الكلمات السلبية

- مطابقة واسعة: إيجار، للإيجار، عقار، عقارات، اوتوكاد، ريفيت، سكتش اب، 3d max، بريمافيرا، primavera
- مطابقة العبارة: "شقق للبيع"، "فلل للبيع"، "أسعار تشطيب"، "تكلفة تشطيب"، "سعر متر التشطيب"، "تشطيب رخيص"، "برنامج حساب الكميات"، "برنامج مستخلصات"، "مخطط جانت"، "صور تشطيب"
- مطابقة تامة: [تشطيب فلل]، [تشطيب شقق]، [شركة تشطيبات]، [تجهيز مكاتب] (نية مستهلك أو باحث عن مقاول)

### 5.4 الجماهير

- **Meta:** اهتمامات قريبة من Interior design وConstruction وArchitecture وHome renovation وSmall business؛ مسميات Owner وGeneral Manager وProject Manager ومهندس مشروع؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية من قائمة طلبات العرض.
- **LinkedIn:** الصناعات Construction وBuilding Construction وArchitecture and Planning وInterior Design وBuilding Finishing Contractors؛ المسميات Owner وFounder وManaging Director وProject Manager وFit-out Manager وProcurement Manager؛ حجم الشركة 11–50 و51–200 و201–500.
- **Snapchat:** فئات قريبة من Home Improvement وHome Decor وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة لصاحب شركة التشطيب.

### 5.5 روابط UTM

```
google   ag_fitout_company  https://www.opsone.example/solutions/villa-fit-out/?utm_source=google&utm_medium=cpc&utm_campaign=villa_fit_out_search&utm_content=ag_fitout_company&utm_term={keyword}
google   ag_offices_shops   https://www.opsone.example/solutions/villa-fit-out/?utm_source=google&utm_medium=cpc&utm_campaign=villa_fit_out_search&utm_content=ag_offices_shops&utm_term={keyword}
meta                        https://www.opsone.example/solutions/villa-fit-out/?utm_source=meta&utm_medium=paid_social&utm_campaign=villa_fit_out_meta&utm_content=hero_static
linkedin                    https://www.opsone.example/solutions/villa-fit-out/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=villa_fit_out_linkedin&utm_content=sponsored_post
snapchat                    https://www.opsone.example/solutions/villa-fit-out/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=villa_fit_out_snap&utm_content=story_ad
whatsapp                    https://www.opsone.example/solutions/villa-fit-out/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=villa_fit_out_share&utm_content=sales_team
```

### 5.6 المدن المستهدفة

- الرياض: العاصمة، وظهرت فيها إعلانات وشركات تجهيز المكاتب والمحلات في بحث الصفحة.
- جدة
- الدمام والخبر
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك

---

## 6. معارض الأرضيات والرخام والجبس بورد — `flooring-walls-ceilings`

- الصفحة: https://www.opsone.example/solutions/flooring-walls-ceilings/
- الجمهور: معارض الأرضيات والرخام والباركيه ومقاولو الجبس بورد وورق الجدران في السعودية.
- ما لا يُقال: حساب الهدر أو التقريب إلى كرتون تلقائيًا، رقم التشغيلة (shade/lot)، إعارة العينات، «مسار أرضيات جاهز»، الضمان، أي نسبة سوقية.

### 6.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة معرض سيراميك ورخام | لا نتائج برمجية سعودية (RESEARCH) | يُتحقق منه في Keyword Planner |
| برنامج معرض أرضيات وباركيه | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج مقاول جبس بورد | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج إدارة ورق الجدران | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج معرض بلاط وبورسلين | صيغة قريبة | يُتحقق منه في Keyword Planner |
| برنامج توريد وتركيب بالمتر المربع | من «توريد وتركيب بالمتر المربع» | يُتحقق منه في Keyword Planner |
| flooring showroom software | للمرجع | يُتحقق منه في Keyword Planner |
| tile showroom software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج عرض سعر توريد وتركيب بالمتر المربع
- برنامج عرض سعر بالكرتون واللفة
- برنامج أوامر شراء لموردي السيراميك
- برنامج مخزون معرض سيراميك ورخام
- برنامج إدارة تركيب الأرضيات على مراحل
- برنامج إدارة شركة جبس بورد وأسقف
- برنامج حجز الكمية لطلب العميل في معرض بلاط
- برنامج سندات تسليم لمعرض رخام
- برنامج مبيعات معرض باركيه
- برنامج إدارة محل ورق جدران

ملاحظة: «flooring estimating software» في `seo.keywords` للمرجع فقط؛ لا تُستهدف في الإعلانات لأن النظام لا يحسب الكميات أو الهدر.

### 6.2 Google Ads

#### المجموعة `ag_tile_marble_showroom` — معرض السيراميك والرخام والباركيه

الكلمات: `[برنامج إدارة معرض سيراميك ورخام]`، `[برنامج معرض أرضيات وباركيه]`، `"برنامج معرض بلاط"`، `"برنامج معرض رخام"`، `"tile showroom software"`

Final URL: https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=google&utm_medium=cpc&utm_campaign=flooring_walls_ceilings_search&utm_content=ag_tile_marble_showroom&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج معرض سيراميك ورخام | 25 |
| 2 | كل متر من المعاينة للتركيب | 26 |
| 3 | عرض سعر بالكرتون واللفة | 23 |
| 4 | حجز الكمية كاملة للطلب | 22 |
| 5 | أمر شراء للمورّد بموعد متوقع | 28 |
| 6 | تسليم على أكثر من سند مرقم | 26 |
| 7 | الاستلام مع المرفوض وسببه | 25 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | بنود العرض بالمتر المربع والكرتون واللفة والمتر الطولي، والبائع يكتب الكمية بنفسه. | 82 |
| 2 | حجز الكمية من المخزون لطلب العميل، وما ليس عندك يُطلب من المورّد بموعد متوقع. | 77 |
| 3 | تسليم البنود على أكثر من سند مرقّم، والتركيب على مراحل بصور وتوقيع العميل. | 74 |

#### المجموعة `ag_gypsum_wallpaper` — الجبس بورد وورق الجدران

الكلمات: `[برنامج مقاول جبس بورد]`، `[برنامج إدارة ورق الجدران]`، `"برنامج شركة جبس بورد"`، `"برنامج محل ورق جدران"`

Final URL: https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=google&utm_medium=cpc&utm_campaign=flooring_walls_ceilings_search&utm_content=ag_gypsum_wallpaper&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج مقاول جبس بورد | 21 |
| 2 | برنامج محل ورق جدران | 20 |
| 3 | القياس بالمتر المربع | 20 |
| 4 | موعد لكل مرحلة تركيب | 20 |
| 5 | دفعة مربوطة ببدء المرحلة | 24 |
| 6 | تنبيه تعارض موعدي الفريق | 24 |
| 7 | فرق التركيب لا ترى المبالغ | 26 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | مقاسات المعاينة بالمتر المربع، تقديرية أو نهائية، مع من قاسها ومتى. | 67 |
| 2 | لكل مرحلة تركيب موعدها، وتنبيه حين يتعارض موعدان لنفس الفريق، وتذكير العميل قبله. | 81 |
| 3 | خطة حتى 12 دفعة مربوطة بالعقد أو ببدء مرحلة بعينها أو باكتمال التسليم. | 70 |

### 6.3 الكلمات السلبية

- مطابقة واسعة: معلم، حاسبة، ديكورات، أشكال، بنفسك
- مطابقة العبارة: "سعر متر الباركيه"، "سعر متر البلاط"، "سعر متر الرخام"، "أسعار السيراميك"، "أسعار الجبس بورد"، "حساب كمية البلاط"، "حساب الهدر"، "برنامج حساب كميات البلاط"، "تصاميم جبس"، "صور جبس"، "طريقة تركيب ورق جدران"
- مطابقة تامة: [معلم بلاط]، [معلم جبس بورد]، [سعر متر الباركيه مع التركيب] (نية مستهلك أو أفراد حسب RESEARCH)

### 6.4 الجماهير

- **Meta:** اهتمامات قريبة من Flooring وCeramic tile وMarble وInterior design وConstruction وSmall business؛ مسميات Owner وShowroom Manager وGeneral Manager؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية.
- **LinkedIn:** الصناعات Wholesale Building Materials وGlass, Ceramics and Concrete Manufacturing وBuilding Finishing Contractors وConstruction وRetail؛ المسميات Owner وGeneral Manager وShowroom Manager وSales Manager وProcurement Manager؛ حجم الشركة 2–10 و11–50 و51–200.
- **Snapchat:** فئات قريبة من Home Improvement وHome Decor وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة لصاحب المعرض أو المقاول.

### 6.5 روابط UTM

```
google   ag_tile_marble_showroom  https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=google&utm_medium=cpc&utm_campaign=flooring_walls_ceilings_search&utm_content=ag_tile_marble_showroom&utm_term={keyword}
google   ag_gypsum_wallpaper      https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=google&utm_medium=cpc&utm_campaign=flooring_walls_ceilings_search&utm_content=ag_gypsum_wallpaper&utm_term={keyword}
meta                              https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=meta&utm_medium=paid_social&utm_campaign=flooring_walls_ceilings_meta&utm_content=hero_static
linkedin                          https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=flooring_walls_ceilings_linkedin&utm_content=sponsored_post
snapchat                          https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=flooring_walls_ceilings_snap&utm_content=story_ad
whatsapp                          https://www.opsone.example/solutions/flooring-walls-ceilings/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=flooring_walls_ceilings_share&utm_content=sales_team
```

### 6.6 المدن المستهدفة

- الرياض
- جدة
- الدمام والخبر
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك
- حائل
- جازان

---

## 7. شركات المنازل الذكية والإنارة والكاميرات — `smart-home-lighting-security`

- الصفحة: https://www.opsone.example/solutions/smart-home-lighting-security/
- الجمهور: شركات المنازل الذكية والإنارة وكاميرات المراقبة والأنظمة الأمنية في السعودية.
- ما لا يُقال: سجل الضمان وعقود الصيانة الدورية، الأرقام التسلسلية للأجهزة، حفظ كلمات مرور الأنظمة، كتالوج أجهزة بمخطط تفاعلي، «قالب جاهز للقطاع»، التقاط رسائل واتساب.

### 7.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة شركات المنازل الذكية | نية واضحة وبحث ضعيف (RESEARCH) | يُتحقق منه في Keyword Planner |
| برنامج شركة كاميرات مراقبة | `seo.keywords`؛ يحتاج سلبيات قوية (انظر 7.3) | يُتحقق منه في Keyword Planner |
| نظام إدارة مشاريع الأنظمة الأمنية | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج شركات الإنارة الذكية | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج إدارة شركة سمارت هوم | من «سمارت هوم» | يُتحقق منه في Keyword Planner |
| برنامج مؤسسة تركيب كاميرات مراقبة | من «تركيب كاميرات مراقبة» | يُتحقق منه في Keyword Planner |
| smart home integrator software | للمرجع | يُتحقق منه في Keyword Planner |
| security installer CRM | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج عرض سعر المنزل الذكي بجدول الأجهزة
- برنامج إدارة تمديدات المنزل الذكي قبل التشطيب
- برنامج متابعة تركيب كاميرات المراقبة
- برنامج عقود وعربون شركات الأنظمة الأمنية
- برنامج أوامر شراء من موزعي الكاميرات
- برنامج إدارة فنيي تركيب الإنارة الذكية
- برنامج مبيعات ميدانية لشركات الكاميرات
- برنامج إدارة شركة انتركم وأقفال ذكية
- نظام إدارة مشاريع الإنارة والتحكم الذكي
- برنامج إدارة مشاريع البيت الذكي للفلل

### 7.2 Google Ads

#### المجموعة `ag_smart_home` — المنازل الذكية والإنارة

الكلمات: `[برنامج إدارة شركات المنازل الذكية]`، `[برنامج شركات الإنارة الذكية]`، `"برنامج إدارة شركة سمارت هوم"`، `"برنامج شركة بيت ذكي"`، `"smart home integrator software"`

Final URL: https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=google&utm_medium=cpc&utm_campaign=smart_home_lighting_security_search&utm_content=ag_smart_home&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج شركات المنازل الذكية | 27 |
| 2 | من المعاينة إلى البرمجة | 23 |
| 3 | جدول الأجهزة في عرض السعر | 25 |
| 4 | التمديد قبل التشطيب بصور | 24 |
| 5 | مخطط الأجهزة يعتمده العميل | 26 |
| 6 | دفعة عند بدء التمديد | 20 |
| 7 | التسليم بتوقيع العميل | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | جدول الأجهزة بنود عرض السعر بكمياتها، ومخطط التوزيع بنسخ يعتمدها العميل من جواله. | 81 |
| 2 | مهمة التمديد ومهمة البرمجة لا تكتملان إلا بمرفق: صور التمديد وملف المشاهد. | 74 |
| 3 | خطة دفعات مربوطة ببدء مرحلة مثل التمديد، وتذكير قبل الدفعة وسند مرقّم بعدها. | 76 |

#### المجموعة `ag_cctv_security` — الكاميرات والأنظمة الأمنية

الكلمات: `[برنامج شركة كاميرات مراقبة]`، `[نظام إدارة مشاريع الأنظمة الأمنية]`، `"برنامج مؤسسة تركيب كاميرات"`، `"security installer CRM"`

Final URL: https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=google&utm_medium=cpc&utm_campaign=smart_home_lighting_security_search&utm_content=ag_cctv_security&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج شركة كاميرات مراقبة | 26 |
| 2 | الأنظمة الأمنية من المعاينة | 27 |
| 3 | حجز الأجهزة من المخزون | 22 |
| 4 | أمر شراء للموزع بموعد متوقع | 27 |
| 5 | صور قبل التركيب وبعده | 21 |
| 6 | متجر للكاميرات الجاهزة | 22 |
| 7 | احجز عرضًا على طلباتك | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | الأجهزة الموجودة في المخزون تُحجز للطلب عند تأكيده، وما ليس عندك أمر شراء للموزّع. | 82 |
| 2 | صور قبل التركيب وبعده، وملاحظات تمنع إغلاق الطلب، وتوقيع العميل على الاستلام. | 77 |
| 3 | المتجر يبيع الكاميرات واللمبات الذكية الجاهزة من المخزون نفسه بدفع إلكتروني. | 76 |

### 7.3 الكلمات السلبية

- مطابقة واسعة: للكمبيوتر، للجوال، للايفون، للاندرويد، مشاهدة، ساهر، حراسات، حارس، سيبراني، اليكسا، alexa، dvr، nvr، باسورد
- مطابقة العبارة: "برنامج مشاهدة الكاميرات"، "تطبيق كاميرات المراقبة"، "ربط الكاميرات بالجوال"، "تشغيل الكاميرات"، "أسعار كاميرات المراقبة"، "كاميرا مراقبة للبيع"، "أسعار الإنارة"، "سمارت لايف"، "جوجل هوم"، "منزل ذكي بنفسك"، "شركة حراسات أمنية"، "الأمن السيبراني"
- مطابقة تامة: [كاميرات مراقبة]، [تركيب كاميرات مراقبة]، [سمارت هوم] (نية مستهلك)

### 7.4 الجماهير

- **Meta:** اهتمامات قريبة من Home automation وSmart home وClosed-circuit television وSecurity وLighting وSmall business؛ مسميات Owner وGeneral Manager وProject Manager وTechnical Manager؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية.
- **LinkedIn:** الصناعات Security Systems Services وElectrical Equipment Manufacturing وAppliances, Electrical, and Electronics Manufacturing وIT Services and IT Consulting وConstruction؛ المسميات Owner وFounder وGeneral Manager وProject Manager وSystems Integrator وTechnical Manager؛ حجم الشركة 2–10 و11–50 و51–200.
- **Snapchat:** فئات قريبة من Tech/Gadgets وHome Improvement وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة لصاحب الشركة لا للمستهلك.

### 7.5 روابط UTM

```
google   ag_smart_home     https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=google&utm_medium=cpc&utm_campaign=smart_home_lighting_security_search&utm_content=ag_smart_home&utm_term={keyword}
google   ag_cctv_security  https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=google&utm_medium=cpc&utm_campaign=smart_home_lighting_security_search&utm_content=ag_cctv_security&utm_term={keyword}
meta                       https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=meta&utm_medium=paid_social&utm_campaign=smart_home_lighting_security_meta&utm_content=hero_static
linkedin                   https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=smart_home_lighting_security_linkedin&utm_content=sponsored_post
snapchat                   https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=smart_home_lighting_security_snap&utm_content=story_ad
whatsapp                   https://www.opsone.example/solutions/smart-home-lighting-security/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=smart_home_lighting_security_share&utm_content=sales_team
```

### 7.6 المدن المستهدفة

- الرياض
- جدة
- الدمام والخبر
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك

---

## 8. مؤسسات المظلات والسواتر وتنسيق الحدائق — `outdoor-spaces`

- الصفحة: https://www.opsone.example/solutions/outdoor-spaces/
- الجمهور: مؤسسات المظلات والسواتر والبرجولات وتنسيق الحدائق والمسابح في السعودية.
- ما لا يُقال: «إدارة الضمان» (شروط الضمان تُكتب في بنود العقد فقط)، مهمة إصلاح من التذكرة، حقول قياس خاصة (منسوب، ميول)، القياس بالأقمار الصناعية، التصميم ثلاثي الأبعاد داخل النظام، التصاريح البلدية، التقاط رسائل واتساب.

### 8.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة مؤسسة مظلات وسواتر | نية صاحب نشاط (RESEARCH) | يُتحقق منه في Keyword Planner |
| برنامج إدارة شركة تنسيق حدائق | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج مؤسسة برجولات | من «برجولات» | يُتحقق منه في Keyword Planner |
| برنامج إدارة شركة مسابح | من «تصميم وتنفيذ مسابح» | يُتحقق منه في Keyword Planner |
| نظام إدارة مؤسسة مظلات | صيغة سعودية شائعة | يُتحقق منه في Keyword Planner |
| برنامج شركة لاندسكيب | من «لاندسكيب» | يُتحقق منه في Keyword Planner |
| pergola installation CRM | للمرجع | يُتحقق منه في Keyword Planner |
| landscaping business software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج عرض سعر مظلات وسواتر
- برنامج متابعة تركيب مظلات السيارات
- برنامج إدارة مشاريع تصميم وتنفيذ مسابح
- برنامج إدارة طلبات الجلسات الخارجية تفصيل
- برنامج زيارة موقع ورفع مقاسات للمظلات
- برنامج عقود وعربون مؤسسات المظلات
- برنامج مندوب مبيعات مظلات وسواتر
- برنامج إدارة مصنع برجولات حديد
- برنامج إدارة شركة تنسيق حدائق وعشب صناعي
- نظام إدارة مؤسسة سواتر حديد

ملاحظة: «مظلات وسواتر» و«برجولات» و«تصميم وتنفيذ مسابح» و«جلسات خارجية تفصيل» في `seo.keywords` للصفحة، لكنها نية مستهلك في الإعلانات؛ لا تُستهدف إلا مقرونة بـ«برنامج/نظام إدارة».

### 8.2 Google Ads

#### المجموعة `ag_shades_screens` — المظلات والسواتر والبرجولات

الكلمات: `[برنامج إدارة مؤسسة مظلات وسواتر]`، `[نظام إدارة مؤسسة مظلات]`، `"برنامج مؤسسة برجولات"`، `"برنامج عرض سعر مظلات"`، `"pergola installation CRM"`

Final URL: https://www.opsone.example/solutions/outdoor-spaces/?utm_source=google&utm_medium=cpc&utm_campaign=outdoor_spaces_search&utm_content=ag_shades_screens&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج مؤسسة مظلات وسواتر | 25 |
| 2 | من الرفع إلى التسليم | 20 |
| 3 | مقاسات الرفع بموقعها ووحدتها | 28 |
| 4 | المخطط يعتمده العميل من جواله | 29 |
| 5 | العقد والعربون قبل التصنيع | 26 |
| 6 | التركيب بالصور وتوقيع الاستلام | 30 |
| 7 | تذكرة العميل مربوطة بطلبه | 25 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | زيارة الموقع والرفع: كل مقاس بموقعه وقيمته ووحدته، وصور الموقع مرفقة بالطلب. | 76 |
| 2 | مسار تصنيع داخلي جاهز بخمس مراحل ومدد تعدّلها، وتضيف مرحلة مثل تجهيز الموقع والقواعد. | 85 |
| 3 | بعد التسليم يفتح العميل تذكرة من صفحته مربوطة بطلبه ومعها صور. | 62 |

#### المجموعة `ag_landscape_pools` — تنسيق الحدائق والمسابح والجلسات

الكلمات: `[برنامج إدارة شركة تنسيق حدائق]`، `"برنامج شركة لاندسكيب"`، `"برنامج إدارة شركة مسابح"`، `"landscaping business software"`

Final URL: https://www.opsone.example/solutions/outdoor-spaces/?utm_source=google&utm_medium=cpc&utm_campaign=outdoor_spaces_search&utm_content=ag_landscape_pools&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج شركة تنسيق حدائق | 23 |
| 2 | المسابح من الرفع للتسليم | 24 |
| 3 | الجلسات الخارجية تفصيل | 22 |
| 4 | نسخ المخطط واعتمادها | 20 |
| 5 | حدائق ومسابح في طلب واحد | 24 |
| 6 | البائع الميداني يقيس ويرسل | 26 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | حديقة ومسبح وجلسة في طلب واحد: الرفع، والمخطط الذي يعتمده العميل، والعقد والعربون. | 82 |
| 2 | خطط 40/40/20 و50/50 أو حتى 12 دفعة مربوطة بالأحداث، وتذكير قبل الدفعة وسند مرقّم. | 81 |
| 3 | البائع يرى فرصه فقط، يقيس ويرسل العرض ورابط العقد، والطلب يُنسب لقناته وله. | 75 |

### 8.3 الكلمات السلبية

- مطابقة واسعة: شمسية، خيمة، خيام، مطاطي، مشتل، بذور، سماد، منتزه، حديقة الحيوان، تصريح
- مطابقة العبارة: "أسعار مظلات"، "سعر متر المظلات"، "سعر متر السواتر"، "مظلات للبيع"، "مظلات مستعملة"، "مسبح أطفال"، "نباتات للبيع"، "تصاميم حدائق صور"، "حدائق عامة"
- مطابقة تامة: [مظلات وسواتر]، [برجولات]، [تنسيق حدائق]، [مظلات سيارات]، [سواتر حديد] (نية مستهلك حسب RESEARCH)

### 8.4 الجماهير

- **Meta:** اهتمامات قريبة من Landscaping وGardening وSwimming pool وOutdoor furniture وConstruction وSmall business؛ مسميات Owner وGeneral Manager وProject Manager؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية.
- **LinkedIn:** الصناعات Landscaping Services وConstruction وFabricated Metal Products وArchitecture and Planning؛ المسميات Owner وFounder وGeneral Manager وProject Manager وSite Manager؛ حجم الشركة 2–10 و11–50 و51–200. (حجم الجمهور يُتحقق منه في Campaign Manager).
- **Snapchat:** فئات قريبة من Home Improvement وOutdoor/Gardening وBusiness/Entrepreneurs؛ الأعمار 25–54؛ رسالة لصاحب المؤسسة.

### 8.5 روابط UTM

```
google   ag_shades_screens   https://www.opsone.example/solutions/outdoor-spaces/?utm_source=google&utm_medium=cpc&utm_campaign=outdoor_spaces_search&utm_content=ag_shades_screens&utm_term={keyword}
google   ag_landscape_pools  https://www.opsone.example/solutions/outdoor-spaces/?utm_source=google&utm_medium=cpc&utm_campaign=outdoor_spaces_search&utm_content=ag_landscape_pools&utm_term={keyword}
meta                         https://www.opsone.example/solutions/outdoor-spaces/?utm_source=meta&utm_medium=paid_social&utm_campaign=outdoor_spaces_meta&utm_content=hero_static
linkedin                     https://www.opsone.example/solutions/outdoor-spaces/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=outdoor_spaces_linkedin&utm_content=sponsored_post
snapchat                     https://www.opsone.example/solutions/outdoor-spaces/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=outdoor_spaces_snap&utm_content=story_ad
whatsapp                     https://www.opsone.example/solutions/outdoor-spaces/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=outdoor_spaces_share&utm_content=sales_team
```

### 8.6 المدن المستهدفة

- الرياض
- جدة
- الدمام والخبر
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- حائل
- تبوك

---

## 9. مصانع اللوحات الإعلانية وأجنحة المعارض — `signage-exhibitions-events`

- الصفحة: https://www.opsone.example/solutions/signage-exhibitions-events/
- الجمهور: مصانع اللوحات الإعلانية والواجهات وأجنحة المعارض وتجهيز الفعاليات في السعودية.
- ما لا يُقال: تاريخ الفعالية حقلًا والجدولة العكسية منه، الفك نوعًا مستقلًا من المواعيد (هو مرحلة تضيفها)، تأجير المعدات واستردادها، التقديم على التصاريح من داخل النظام، جهات أو مدد أو رسوم تصاريح، «تنظيم الفعاليات» (نقول «تجهيز الفعاليات»).

### 9.1 كلمات البحث

**أساسية**

| الكلمة | ملاحظة | حجم البحث والمنافسة |
|---|---|---|
| برنامج إدارة مصنع لوحات إعلانية | صريح النية (RESEARCH) | يُتحقق منه في Keyword Planner |
| نظام لشركات الدعاية والإعلان | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج تجهيز أجنحة المعارض | `seo.keywords` | يُتحقق منه في Keyword Planner |
| برنامج شركة تصميم وتنفيذ أجنحة المعارض | من «تصميم وتنفيذ أجنحة المعارض» | يُتحقق منه في Keyword Planner |
| برنامج مصنع لوحات محلات | من «لوحات محلات وحروف بارزة» | يُتحقق منه في Keyword Planner |
| برنامج شركات تجهيز الفعاليات | من «تجهيز الفعاليات» | يُتحقق منه في Keyword Planner |
| sign shop management software | للمرجع | يُتحقق منه في Keyword Planner |
| exhibition stand contractor software | للمرجع | يُتحقق منه في Keyword Planner |

**طويلة الذيل**

- برنامج اعتماد البروفة من العميل
- برنامج عرض سعر لوحات وحروف بارزة
- برنامج إدارة مواعيد تركيب وفك الأجنحة
- برنامج أوامر شراء للمطابع وموردي الإضاءة
- برنامج إدارة مصنع كلادينج واجهات
- برنامج متابعة طلبات البوثات
- برنامج عقود وعربون شركات الدعاية والإعلان
- برنامج متجر رول أب وستاندات
- برنامج مندوب مبيعات لوحات إعلانية
- نظام إدارة مصنع لوحات مضيئة

### 9.2 Google Ads

#### المجموعة `ag_sign_factory` — مصنع اللوحات والواجهات

الكلمات: `[برنامج إدارة مصنع لوحات إعلانية]`، `[نظام لشركات الدعاية والإعلان]`، `"برنامج مصنع لوحات"`، `"برنامج شركة دعاية وإعلان"`، `"sign shop management software"`

Final URL: https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=google&utm_medium=cpc&utm_campaign=signage_exhibitions_events_search&utm_content=ag_sign_factory&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج مصنع لوحات إعلانية | 25 |
| 2 | اعتماد البروفة من الجوال | 24 |
| 3 | عرض سعر لوحات وحروف بارزة | 25 |
| 4 | العقد والعربون قبل التصنيع | 26 |
| 5 | التركيب بصور وتوقيع العميل | 26 |
| 6 | أمر شراء للطباعة والإضاءة | 25 |
| 7 | فحص الجودة بصورة قبل التركيب | 28 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | البروفة بنسخها يعتمدها العميل أو يطلب تعديلها من جواله، ثم العرض والعقد الموقّع. | 80 |
| 2 | أمر شراء لمورّد الطباعة أو الإضاءة أو الشاشات بموعد متوقع، ولوحة تعرض ما تأخر. | 78 |
| 3 | مراحل المصنع الداخلي بمدد ومسؤولين، وفحص الجودة بصورة قبل التركيب. | 66 |

#### المجموعة `ag_exhibition_stands` — أجنحة المعارض وتجهيز الفعاليات

الكلمات: `[برنامج تجهيز أجنحة المعارض]`، `"برنامج شركة أجنحة معارض"`، `"برنامج شركات تجهيز الفعاليات"`، `"exhibition stand contractor software"`

Final URL: https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=google&utm_medium=cpc&utm_campaign=signage_exhibitions_events_search&utm_content=ag_exhibition_stands&utm_term={keyword}

| # | العنوان (≤ 30) | عدد الأحرف |
|---|---|---|
| 1 | برنامج تجهيز أجنحة المعارض | 26 |
| 2 | من الموجز إلى الفك | 18 |
| 3 | موعد التركيب في التقويم | 23 |
| 4 | مرحلة الفك والاستلام تضيفها | 27 |
| 5 | تصريح المنظم مستند في الطلب | 27 |
| 6 | تنبيه حين تتأخر مهمة | 20 |
| 7 | احجز عرضًا على طلباتك | 21 |

| # | الوصف (≤ 90) | عدد الأحرف |
|---|---|---|
| 1 | مواعيد القياس والتركيب في التقويم، وتنبيه حين يتعارض موعدان لنفس الفريق. | 72 |
| 2 | تضيف مرحلة الفك والاستلام وتسمّي المراحل كما تعمل، وتجرّب التعديل في مسودة. | 75 |
| 3 | اعتماد المنظم أو تصريح البلدية يُرفع مستندًا مطلوبًا في الطلب باسم تختاره. | 74 |
| 4 | المتجر يبيع الجاهز مثل الرول أب والستاندات بدفع إلكتروني وروابط سلة. | 68 |

### 9.3 الكلمات السلبية

- مطابقة واسعة: تذاكر، حجز، فوتوشوب، كانفا، canva، illustrator، لوجو، شعار، زيتية، فنية، رسم، سيارة، سيارات، أبشر، مفاتيح
- مطابقة العبارة: "فعاليات اليوم"، "فعاليات الرياض"، "معرض الكتاب"، "معرض توظيف"، "برنامج تصميم إعلانات"، "برنامج تصميم لوحات"، "لوحات مميزة"، "لوحة تحكم"، "أسعار اللوحات الإعلانية"، "تنظيم حفلات"، "قاعات أفراح"
- مطابقة تامة: [لوحات إعلانية]، [لوحات محلات]، [حروف بارزة]، [تنظيم الفعاليات] (نية مستهلك أو منظم فعاليات لا مصنّع حسب RESEARCH)
- تنبيه: «حجز» سلبية واسعة تمنع أي عبارة فيها «حجز»؛ لا توجد كلمة مستهدفة هنا تحتويها.

### 9.4 الجماهير

- **Meta:** اهتمامات قريبة من Advertising وSignage وTrade show وEvent management وPrinting وSmall business؛ مسميات Owner وGeneral Manager وProduction Manager وProject Manager؛ سلوك Business page admins. جمهور مخصص من زوار الصفحة، ثم جمهور مشابه 1% في السعودية.
- **LinkedIn:** الصناعات Advertising Services وPrinting Services وEvents Services وMarketing Services وFabricated Metal Products؛ المسميات Owner وFounder وGeneral Manager وProduction Manager وProject Manager وAccount Manager؛ حجم الشركة 2–10 و11–50 و51–200 و201–500.
- **Snapchat:** فئات قريبة من Business/Entrepreneurs وMarketing وEvents؛ الأعمار 25–54؛ رسالة لصاحب المصنع أو الشركة.

### 9.5 روابط UTM

```
google   ag_sign_factory       https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=google&utm_medium=cpc&utm_campaign=signage_exhibitions_events_search&utm_content=ag_sign_factory&utm_term={keyword}
google   ag_exhibition_stands  https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=google&utm_medium=cpc&utm_campaign=signage_exhibitions_events_search&utm_content=ag_exhibition_stands&utm_term={keyword}
meta                           https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=meta&utm_medium=paid_social&utm_campaign=signage_exhibitions_events_meta&utm_content=hero_static
linkedin                       https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=linkedin&utm_medium=paid_social&utm_campaign=signage_exhibitions_events_linkedin&utm_content=sponsored_post
snapchat                       https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=snapchat&utm_medium=paid_social&utm_campaign=signage_exhibitions_events_snap&utm_content=story_ad
whatsapp                       https://www.opsone.example/solutions/signage-exhibitions-events/?utm_source=whatsapp&utm_medium=organic_share&utm_campaign=signage_exhibitions_events_share&utm_content=sales_team
```

### 9.6 المدن المستهدفة

- الرياض: فيها مراكز معارض كبرى، وظهرت فيها إعلانات مصانع تجهيز الأجنحة في بحث الصفحة.
- جدة: ظهرت فيها إعلانات مصانع تجهيز الأجنحة في بحث الصفحة.
- الدمام والخبر والظهران
- مكة المكرمة
- المدينة المنورة
- القصيم (بريدة)
- أبها وخميس مشيط
- تبوك

---

## فحص حدود الأحرف

قيم عمود «عدد الأحرف» في كل الجداول أعلاه يكتبها سكربت Node يقرأ هذا الملف، ويحسب طول كل عنوان ووصف بـ `[...str].length`، ويفشل إذا تجاوز عنوان 30 أو وصف 90، أو إذا ظهرت في نص إعلان كلمة ممنوعة (ثوري، سلس، الحل المتكامل، عربي، ورشة، ورش، ضمان، واتساب). أعد تشغيله بعد أي تعديل.
