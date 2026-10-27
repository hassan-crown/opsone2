# SelectField

قائمة اختيار أصلية بمظهر الحقول، للاختيار من خمسة خيارات فأكثر.

**يقدّم المستهلك:** `label`، و`options` (`{value, label, disabled?}`) أو عناصر `option`، و`value`/`onChange`، واختياريًا `placeholder` و`hint` و`error` و`required`.

- عنصر `select` الأصلي عمدًا: لوحة مفاتيح وقارئ شاشة وقائمة الجوال الأصلية تعمل دون جهد.
- `placeholder` يضيف خيارًا فارغًا معطّلًا؛ ابدأ بـ `defaultValue=""` ليظهر.
- لأقل من خمسة خيارات تُرى كلها استخدم `RadioGroup`.
