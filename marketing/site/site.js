/* OpsOne site: what every page shares. Loaded after config.js and tags.js on the home page and the sector pages.
   - the header's «الحلول» menu and phone drawer: they are <details>, so they work without JavaScript; this adds one menu
     open at a time, Escape (focus returns to the summary), closing on a click or a Tab outside;
   - the contact buttons (WhatsApp, call) appear only once their numbers are set in config.js;
   - the footer credit links out only once its URL is set;
   - the demo request form. */
(() => {
  // the page's full stylesheet loads without blocking the first paint (media="print"); switch it on now
  document.querySelectorAll('link[data-async-css]').forEach((l) => { l.media = 'all'; });
  const cfg = window.OPSONE_LANDING || {};
  const sector = ((window.OPSONE_PAGE || {}).sector || '').trim();

  /* ---------- header menus ---------- */
  {
    const menus = [...document.querySelectorAll('details[data-menu]')];
    const shut = (m, focus) => { if (!m.open) return; m.open = false; if (focus) m.querySelector('summary').focus(); };
    menus.forEach((m) => {
      m.addEventListener('toggle', () => { if (m.open) menus.forEach((o) => { if (o !== m) shut(o); }); });
      // a link inside the menu goes somewhere: on this page (a #hash) the menu would otherwise stay open over the content
      m.addEventListener('click', (e) => { if (e.target.closest('a')) shut(m); });
      // Tab out of an open panel closes it, so it never covers the element that now has focus
      m.addEventListener('focusout', (e) => { if (e.relatedTarget && !m.contains(e.relatedTarget)) shut(m); });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const open = menus.find((m) => m.open && m.contains(document.activeElement)) || menus.find((m) => m.open);
      if (open) { e.preventDefault(); shut(open, true); }
    });
    // pointerdown, not click: iOS Safari sends no click for taps on non-interactive content
    document.addEventListener('pointerdown', (e) => { menus.forEach((m) => { if (m.open && !m.contains(e.target)) shut(m); }); });
    // the drawer belongs to narrow screens: close it if the window grows past it
    const wide = matchMedia('(min-width: 1101px)');
    const onWide = () => { if (wide.matches) menus.filter((m) => m.classList.contains('drawer')).forEach((m) => shut(m)); };
    if (wide.addEventListener) wide.addEventListener('change', onWide);
  }

  /* ---------- contact: WhatsApp and call, from config.js; hidden until set ---------- */
  const digits = (v) => String(v || '').replace(/[^\d]/g, '');
  const wa = digits(cfg.whatsapp), tel = digits(cfg.phone);
  const waText = (cfg.whatsappText || 'مرحبًا، أريد معرفة المزيد عن OpsOne') + (sector ? ` (${document.title.split('|')[0].trim()})` : '');
  document.querySelectorAll('[data-contact="whatsapp"]').forEach((a) => { if (wa.length >= 9) { a.href = `https://wa.me/${wa}?text=${encodeURIComponent(waText)}`; a.hidden = false; } });
  document.querySelectorAll('[data-contact="call"]').forEach((a) => {
    if (tel.length >= 9) { a.href = `tel:+${tel}`; a.hidden = false; const n = a.querySelector('[data-contact-number]'); if (n) n.textContent = `+${tel}`; }
  });
  document.querySelectorAll('[data-contact-group]').forEach((g) => { g.hidden = !g.querySelector('[data-contact]:not([hidden])'); });

  /* ---------- «الباقات» (home page): shown only when config.pricing.enabled; an amount appears only where config sets one,
     otherwise the plan keeps «اطلب عرض سعر» ---------- */
  const pricing = cfg.pricing || {};
  const plansSection = document.querySelector('[data-pricing]');
  if (plansSection && pricing.enabled === true) {
    plansSection.hidden = false;
    plansSection.querySelectorAll('[data-plan]').forEach((plan) => {
      const amount = String((pricing.amounts || {})[plan.dataset.plan] || '').trim();
      if (!/^[\d,.]+$/.test(amount)) return;
      const price = plan.querySelector('[data-plan-price]');
      const cta = price.querySelector('a');
      price.innerHTML = `<b class="nums">${amount}</b> <span>ر.س شهريًا، بالاشتراك السنوي</span>`;
      price.after(cta);
      cta.classList.add('plan__cta');
    });
  }

  /* ---------- footer credit: the developer's name links out only once its URL is set in config.js ---------- */
  const devUrl = (cfg.developerUrl || '').trim();
  const dev = document.querySelector('[data-developer]');
  if (dev && /^https:\/\//.test(devUrl)) {
    const link = document.createElement('a');
    link.href = devUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = dev.textContent;
    dev.replaceWith(link);
  }

  /* ---------- demo request form: one script for every page (the sector pages add a hidden «sector» field) ----------
     Success replaces the form with a thank-you state (focus moves to it) and reports the lead; with config.thanksUrl set,
     the visitor is sent to that page instead (for platforms that count a conversion by URL). */
  const form = document.querySelector('[data-demo-form]');
  if (form) {
    const status = form.querySelector('[data-status]');
    const submit = form.querySelector('[type="submit"]');
    const label = submit.textContent;
    const latin = (s) => s.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
    const phoneOk = (v) => /^(?:\+?966|0)?5\d{8}$/.test(latin(v).replace(/[\s-]/g, ''));
    const rules = {
      name: (v) => (v.trim().length >= 2 ? '' : 'اكتب اسمك.'),
      company: (v) => (v.trim().length >= 2 ? '' : 'اكتب اسم المنشأة.'),
      phone: (v) => (!v.trim() ? 'اكتب رقم الجوال.' : phoneOk(v) ? '' : 'اكتب رقم جوال سعوديًا صحيحًا، مثل 0551234567.'),
      trade: (v) => (v ? '' : 'اختر ما تبيعه.'),
    };
    const check = (field) => {
      const rule = rules[field.name];
      if (!rule) return true;
      const msg = rule(field.value);
      const err = document.getElementById(`${field.id}-err`);
      field.setAttribute('aria-invalid', String(Boolean(msg)));
      const described = [field.id === 'f-phone' ? 'f-phone-hint' : '', msg ? err.id : ''].filter(Boolean).join(' ');
      if (described) field.setAttribute('aria-describedby', described); else field.removeAttribute('aria-describedby');
      err.textContent = msg;
      err.hidden = !msg;
      return !msg;
    };
    form.querySelectorAll('input, select').forEach((f) => f.addEventListener('blur', () => { if (f.value || f.getAttribute('aria-invalid')) check(f); }));
    form.querySelectorAll('input, select').forEach((f) => f.addEventListener('input', () => { if (f.getAttribute('aria-invalid') === 'true') check(f); }));

    // campaign parameters: kept for this visit and sent with the request
    const CAMPAIGN = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
    const params = new URLSearchParams(location.search);
    CAMPAIGN.forEach((k) => {
      let v = params.get(k) || '';
      try { if (v) sessionStorage.setItem('ops-' + k, v); else v = sessionStorage.getItem('ops-' + k) || ''; } catch { /* private mode */ }
      const field = form.querySelector(`input[name="${k}"]`);
      if (field) field.value = v.slice(0, 200);
    });
    const track = () => { if (window.opsTrack) window.opsTrack('lead'); };

    const say = (html) => { status.innerHTML = html; status.hidden = false; };
    // the thank-you state: the form's fields give way to a confirmation the visitor can read and act on
    const thanks = (phone) => {
      const done = document.createElement('div');
      done.className = 'coupon__done';
      done.tabIndex = -1;
      done.setAttribute('role', 'status');
      done.innerHTML = `<h3>شكرًا، وصل طلبك</h3><p>سنتواصل معك على <span dir="ltr">${esc(phone)}</span> لتحديد موعد العرض التجريبي.</p>`;
      form.replaceWith(done);
      done.focus();
    };
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = [...form.querySelectorAll('input, select')];
      const bad = fields.filter((f) => !check(f));
      if (bad.length) { bad[0].focus(); return; }
      const data = Object.fromEntries(new FormData(form));
      data.phone = latin(data.phone).replace(/[\s-]/g, '');

      if (cfg.demoEndpoint) {
        submit.disabled = true;
        submit.textContent = 'جارٍ الإرسال…';
        status.hidden = true;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 15000);
        try {
          const res = await fetch(cfg.demoEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ ...data, page: location.href, sentAt: new Date().toISOString() }),
            signal: ctrl.signal,
          });
          if (!res.ok) throw new Error(String(res.status));
          track();
          if (/^(https:\/\/|\/|\.\.?\/)/.test(cfg.thanksUrl || '')) { setTimeout(() => { location.href = cfg.thanksUrl; }, 300); return; }
          thanks(data.phone);
        } catch {
          say('تعذّر إرسال الطلب. تحقّق من اتصالك وحاول مرة أخرى بعد قليل.');
        } finally {
          clearTimeout(timer);
          submit.disabled = false;
          submit.textContent = label;
        }
        return;
      }

      if (cfg.demoEmail) {
        const body = [`الاسم: ${data.name}`, `المنشأة: ${data.company}`, `الجوال: ${data.phone}`, `يبيع: ${data.trade}`, `النشاط: ${data.sector || '-'}`, `المدينة: ${data.city || '-'}`].join('\n');
        track();
        location.href = `mailto:${encodeURIComponent(cfg.demoEmail)}?subject=${encodeURIComponent('طلب عرض تجريبي لـ OpsOne')}&body=${encodeURIComponent(body)}`;
        say(`فتحنا بريدك برسالة جاهزة إلى <span dir="ltr">${esc(cfg.demoEmail)}</span>. يصلنا طلبك بعد أن ترسلها من هناك.`);
        return;
      }

      say('لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد.');
    });
  }
})();
