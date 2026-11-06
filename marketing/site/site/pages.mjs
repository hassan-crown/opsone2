// The site's plain pages: privacy, terms, cookies (drafts for legal review), thanks and 404.
// build-site.mjs renders each with the shared header, footer and consent banner. They are noindex: the legal pages until
// they are reviewed and approved (then set `draft: false` here and add them to the sitemap), thanks and 404 always.
import { header, footer, consent, site } from './partials.mjs';

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const DRAFT = `<p class="legal__draft" role="note"><b>مسودة تُراجع قانونيًا.</b> هذا النص مسودة أولى لم يراجعها مستشار قانوني بعد، والقيم بين معقوفين [ ] تُستكمل قبل الإطلاق.</p>`;
const updated = '[تاريخ آخر تحديث]';

export const pages = [
  {
    path: 'privacy/', title: 'سياسة الخصوصية | OpsOne', h1: 'سياسة الخصوصية', draft: true,
    description: 'كيف يجمع موقع OpsOne بياناتك الشخصية ويستخدمها ويحميها، وحقوقك بموجب نظام حماية البيانات الشخصية في المملكة العربية السعودية.',
    body: `${DRAFT}
<p>آخر تحديث: ${updated}</p>
<h2>من نحن</h2>
<p>يشغّل هذا الموقع [الاسم النظامي للجهة المالكة لـ OpsOne]، سجل تجاري رقم [—]، وعنوانها [—] («نحن»). نحن جهة التحكم في البيانات الشخصية التي تُجمع عبر هذا الموقع. للتواصل في شؤون الخصوصية: [بريد الخصوصية].</p>
<h2>البيانات التي نجمعها</h2>
<ul>
<li><b>ما تكتبه في نموذج طلب العرض التوضيحي:</b> الاسم، واسم المنشأة، ورقم الجوال، والمدينة، ونوع ما تبيعه، والوقت المفضل وطريقة العرض إن اخترتهما، والنشاط إن طلبت من صفحة نشاط.</li>
<li><b>بيانات مصدر الزيارة:</b> معاملات الحملة في الرابط (مثل utm_source وgclid وfbclid) والصفحة التي أرسلت منها الطلب، وتُرسل مع الطلب.</li>
<li><b>بيانات القياس والإعلان:</b> فقط إذا وافقت عليها في شريط الخصوصية. انظر <a href="../cookies/">سياسة ملفات تعريف الارتباط</a>.</li>
</ul>
<h2>لماذا نستخدمها</h2>
<ul>
<li>للتواصل معك وترتيب العرض التوضيحي الذي طلبته، وأساس ذلك طلبك وموافقتك.</li>
<li>لمعرفة الحملات والصفحات التي جاءت منها الطلبات وتحسينها، وأساس ذلك موافقتك على القياس والإعلان.</li>
</ul>
<p>لا نبيع بياناتك الشخصية، ولا نستخدمها لغرض لا يتوافق مع ما جُمعت له.</p>
<h2>مع من نشاركها</h2>
<p>نشاركها مع مزودي الخدمة الذين يعملون لصالحنا فقط: استضافة الموقع واستقبال الطلبات [اسم المزود]، وإن وافقت: Google وMeta وTikTok وSnap وLinkedIn لأغراض القياس والإعلان. قد يعالج بعضهم البيانات خارج المملكة، ولا يحدث ذلك إلا وفق ما يسمح به النظام ولوائحه.</p>
<h2>مدة الاحتفاظ</h2>
<p>نحتفظ ببيانات طلب العرض [المدة] من آخر تواصل، ثم نحذفها أو نجعلها غير معرّفة، ما لم يلزمنا نظام بمدة أطول.</p>
<h2>حقوقك</h2>
<p>بموجب نظام حماية البيانات الشخصية لك أن تعرف كيف نعالج بياناتك، وأن تطلب نسخة منها، وأن تصحّحها، وأن تطلب حذفها حين لا تعود لازمة، وأن تسحب موافقتك متى شئت. سحب الموافقة على القياس والإعلان يتم من «إعدادات الخصوصية» أسفل كل صفحة. لبقية الطلبات راسلنا على [بريد الخصوصية]، ونرد خلال المدة التي يحددها النظام. ولك أن تتقدم بشكوى إلى الهيئة السعودية للبيانات والذكاء الاصطناعي (سدايا).</p>
<h2>حماية البيانات</h2>
<p>يصل الموقع عبر اتصال مشفّر (HTTPS)، ولا يطّلع على الطلبات إلا من يحتاجها في فريقنا.</p>
<h2>تغييرات هذه السياسة</h2>
<p>ننشر أي تعديل على هذه الصفحة مع تاريخه.</p>`,
  },
  {
    path: 'terms/', title: 'شروط الاستخدام | OpsOne', h1: 'شروط استخدام الموقع', draft: true,
    description: 'شروط استخدام موقع OpsOne التسويقي.',
    body: `${DRAFT}
<p>آخر تحديث: ${updated}</p>
<h2>نطاق هذه الشروط</h2>
<p>تنظّم هذه الشروط استخدامك لموقع OpsOne التسويقي. أما الاشتراك في نظام OpsOne نفسه فتحكمه اتفاقية الاشتراك والترخيص التي توقّعها المنشأة.</p>
<h2>محتوى الموقع</h2>
<p>يصف الموقع النظام وقدراته. الأمثلة والأرقام والأسماء الظاهرة في لقطات النظام بيانات تجريبية للتوضيح، وليست عملاء حقيقيين. نحرص على دقة ما ننشره، وقد نغيّره دون إشعار مسبق.</p>
<h2>الملكية الفكرية</h2>
<p>العلامة OpsOne والنصوص والتصميم ولقطات النظام مملوكة لـ [الجهة المالكة] أو مرخّصة لها. الصور المأخوذة من مصادر أخرى منسوبة لأصحابها وفق تراخيصها. لا يجوز نسخ المحتوى أو إعادة نشره لغرض تجاري دون إذن مكتوب.</p>
<h2>الاستخدام المقبول</h2>
<p>لا يجوز استخدام الموقع أو نموذج الطلب لإرسال بيانات غير صحيحة أو بيانات شخص آخر دون إذنه، أو لمحاولة تعطيل الموقع أو الوصول إلى ما لا يحق لك.</p>
<h2>المسؤولية</h2>
<p>يُقدَّم الموقع كما هو، بقدر ما يسمح به النظام. لا نتحمل مسؤولية قرار يُبنى على محتوى الموقع وحده دون عرض توضيحي أو عرض سعر مكتوب.</p>
<h2>القانون الواجب التطبيق</h2>
<p>تخضع هذه الشروط لأنظمة المملكة العربية السعودية، وتختص [الجهة القضائية المختصة] بالنظر في أي نزاع.</p>
<h2>التواصل</h2>
<p>[بريد التواصل]</p>`,
  },
  {
    path: 'cookies/', title: 'سياسة ملفات تعريف الارتباط | OpsOne', h1: 'سياسة ملفات تعريف الارتباط', draft: true,
    description: 'ما ملفات تعريف الارتباط وأدوات القياس والإعلان التي يستخدمها موقع OpsOne، ومتى تعمل، وكيف تغيّر اختيارك.',
    body: `${DRAFT}
<p>آخر تحديث: ${updated}</p>
<h2>القاعدة</h2>
<p>لا يحمّل الموقع أي أداة قياس أو إعلان قبل موافقتك، والموقع يعمل كاملًا إن رفضت. تسأل نافذة الخصوصية عن غرضين منفصلين، القياس والإعلانات، وتستطيع تغيير اختيارك متى شئت من «إعدادات الخصوصية» أسفل كل صفحة.</p>
<h2>ما يحفظه الموقع دائمًا (ضروري)</h2>
<ul>
<li><code>opsone-consent-v2</code> في التخزين المحلي للمتصفح: اختيارك في نافذة الخصوصية وتاريخه.</li>
<li><code>ops-utm_*</code> و<code>ops-gclid</code> و<code>ops-fbclid</code> في تخزين الجلسة: مصدر زيارتك، يُحذف عند إغلاق المتصفح، ويُرسل مع طلب العرض إن أرسلته.</li>
</ul>
<h2>القياس (بموافقتك)</h2>
<p>Google Analytics 4 وGoogle Tag Manager: عدد الزيارات والصفحات ومصدر الزيارة والأحداث (مشاهدة صفحة النشاط، عمق التمرير، النقر على طلب العرض أو واتساب أو الاتصال، وإرسال الطلب).</p>
<h2>الإعلانات (بموافقتك)</h2>
<p>Google Ads وMeta Pixel وTikTok Pixel وSnap Pixel وLinkedIn Insight Tag: لمعرفة الإعلانات التي جاء منها الطلب وتحسينها. تضع هذه الأدوات ملفات تعريف ارتباط خاصة بها وفق سياساتها.</p>
<p>المعرّفات المستعملة فعلًا: [قائمة الأدوات المفعّلة عند الإطلاق].</p>
<h2>تغيير اختيارك</h2>
<p>اضغط «إعدادات الخصوصية» أسفل الصفحة. سحب الموافقة يوقف الأدوات من الصفحة التالية، ويمكنك أيضًا حذف ملفات تعريف الارتباط من إعدادات متصفحك.</p>`,
  },
  {
    path: 'thanks/', title: 'شكرًا، وصل طلبك | OpsOne', h1: 'شكرًا، وصل طلبك', noindex: true,
    description: 'وصل طلب العرض التوضيحي.',
    body: `<p class="lead">سنتواصل معك على رقم الجوال الذي كتبته لتحديد موعد العرض التوضيحي.</p>
<p><a class="btn btn--go" href="../">العودة إلى الصفحة الرئيسية</a></p>`,
  },
  {
    path: '404.html', title: 'الصفحة غير موجودة | OpsOne', h1: 'لم نجد هذه الصفحة', noindex: true, absolute: true,
    description: 'الصفحة غير موجودة.',
    body: `<p class="lead">ربما تغيّر الرابط أو كُتب خطأ. هذه صفحات تبدأ منها:</p>
<p class="legal__acts"><a class="btn btn--go" href="/">الصفحة الرئيسية</a> <a class="btn btn--quiet" href="/#solutions">حلول حسب نشاطك</a> <a class="btn btn--quiet" href="/#demo">احجز عرضًا توضيحيًا</a></p>`,
  },
];

// one page: the 404 uses root-absolute paths (it is served at any address); the others sit one folder deep
export function render(p) {
  const root = p.absolute ? '/' : '../';
  const url = p.absolute ? null : `${site.origin}${p.path}`;
  const robots = p.draft || p.noindex ? 'noindex, follow' : 'index, follow';
  return `<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${esc(p.title)}</title>
<meta name="description" content="${esc(p.description)}">
<meta name="robots" content="${robots}">
${url ? `<link rel="canonical" href="${url}">\n` : ''}<meta property="og:type" content="website">
<meta property="og:site_name" content="OpsOne">
<meta property="og:locale" content="ar_SA">
<meta property="og:title" content="${esc(p.title)}">
<meta property="og:description" content="${esc(p.description)}">
<meta property="og:image" content="${site.origin}assets/og-image.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#FFFFFF">
<link rel="icon" href="${root}assets/brand/opsone-mark.svg" type="image/svg+xml">
<link rel="icon" href="${root}assets/brand/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="${root}assets/brand/apple-touch-icon.png" sizes="180x180">
<link rel="manifest" href="${root}site.webmanifest">
<link rel="preload" href="${root}assets/fonts/IBMPlexSansArabic-Bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="${root}assets/fonts/IBMPlexSansArabic-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="${root}styles.min.css">
<script>document.documentElement.classList.add('js')</script>
</head>
<body>
${header({ root, demo: `${root}#demo` })}

<main id="main">
<article class="legal wrap">
<h1 class="head">${esc(p.h1)}</h1>
${p.body.replaceAll('href="../', `href="${root}`)}
</article>
</main>

${footer({ root, demo: `${root}#demo` })}

${consent({ root })}
<script src="${root}config.js"></script>
<script src="${root}tags.js" defer></script>
<script src="${root}site.js" defer></script>
</body>
</html>
`.split('href="#i-').join(`href="${root}assets/icons.svg#i-`);
}
