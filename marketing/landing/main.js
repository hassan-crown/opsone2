/* OpsOne landing: the live order path, the people tabs, the demo form and the motion layer.
   Everything works without this file except the switches; the page is complete in the HTML. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');

  /* ---------- reveal once ---------- */
  const io = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
    for (const e of entries) if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
  }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 }) : null;
  document.querySelectorAll('.board .tile').forEach((t, k) => t.style.setProperty('--k', String(k % 6)));
  document.querySelectorAll('[data-reveal], .board').forEach((el) => (io ? io.observe(el) : el.classList.add('is-in')));

  /* ---------- many into one: the scattered squares settle into one order as the section scrolls ---------- */
  const many = document.querySelector('[data-many]');
  if (many && !reduce.matches) {
    let seed = 7;
    const rnd = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
    const sets = [...many.querySelectorAll('svg')].map((svg) => {
      const [, , w, h] = svg.getAttribute('viewBox').split(' ').map(Number);
      return [...svg.querySelectorAll('rect')].map((r) => {
        const x = +r.getAttribute('x'), y = +r.getAttribute('y');
        return { r, dx: rnd() * (w - 12) - x, dy: rnd() * (h - 12) - y, lag: rnd() * 0.25 };
      });
    });
    let ticking = false, visible = false;
    const ease = (t) => 1 - Math.pow(1 - t, 3);
    const draw = () => {
      ticking = false;
      const box = many.getBoundingClientRect();
      const vh = innerHeight;
      const p = Math.min(1, Math.max(0, (vh - box.top) / (vh * 0.75 + box.height * 0.2)));
      for (const set of sets) for (const s of set) {
        const t = ease(Math.min(1, Math.max(0, (p - s.lag) / (1 - s.lag))));
        s.r.style.transform = t >= 1 ? '' : `translate(${(s.dx * (1 - t)).toFixed(1)}px, ${(s.dy * (1 - t)).toFixed(1)}px)`;
      }
    };
    const onScroll = () => { if (visible && !ticking) { ticking = true; requestAnimationFrame(draw); } };
    new IntersectionObserver(([e]) => { visible = e.isIntersecting; if (visible) onScroll(); }, { rootMargin: '20% 0px' }).observe(many);
    addEventListener('scroll', onScroll, { passive: true });
    addEventListener('resize', onScroll);
    draw();
  }

  /* ---------- the live order path: trade switch ---------- */
  const trades = {
    kitchen: { item: 'مطبخ أكريليك مطفي بجزيرة وسطح كوارتز، 7.8 م', total: '48,300', vat: '6,300', half: '24,150', designer: 'المصمم', shop: 'ورشة النجارة' },
    aluminium: { item: 'نوافذ وأبواب ألمنيوم بقطع حراري لفيلا، 14 فتحة', total: '62,100', vat: '8,100', half: '31,050', designer: 'مهندس التصميم', shop: 'ورشة القص والتجميع' },
    wardrobe: { item: 'خزانة ملابس بأبواب منزلقة وغرفة تبديل، 4.2 م', total: '18,400', vat: '2,400', half: '9,200', designer: 'المصمم', shop: 'ورشة النجارة' },
  };
  const path = document.querySelector('#path');
  if (path) {
    path.querySelectorAll('input[name="trade"]').forEach((input) => input.addEventListener('change', () => {
      const t = trades[input.value];
      if (!t) return;
      path.querySelectorAll('[data-t]').forEach((el) => { if (t[el.dataset.t] != null) el.textContent = t[el.dataset.t]; });
    }));

    /* a stage opens its real screen beside the list (in its row on a phone) */
    const frame = path.querySelector('.path__shot');
    const frameImg = frame && frame.querySelector('img');
    const caption = path.querySelector('.path__frame figcaption');
    const rows = [...path.querySelectorAll('.stage__row')];
    const fine = matchMedia('(hover: hover) and (pointer: fine)');
    let current = rows[0];
    let swap;
    const select = (row) => {
      if (row === current) return;
      current = row;
      rows.forEach((r) => r.setAttribute('aria-pressed', String(r === row)));
      if (!frameImg) return;
      caption.textContent = row.dataset.cap;
      clearTimeout(swap);
      const apply = () => { frameImg.src = row.dataset.shot; frameImg.style.objectPosition = row.dataset.pos; frame.classList.remove('is-swapping'); };
      if (reduce.matches) { apply(); return; }
      frame.classList.add('is-swapping');
      swap = setTimeout(apply, 180);
    };
    let warmed = false;
    const warm = () => { if (warmed) return; warmed = true; rows.forEach((r) => { new Image().src = r.dataset.shot; }); };
    rows.forEach((row) => {
      row.addEventListener('click', () => { warm(); select(row); });
      row.addEventListener('focus', () => { warm(); select(row); });
      row.addEventListener('pointerenter', () => { if (fine.matches) { warm(); select(row); } });
    });
  }

  /* ---------- people: tabs ---------- */
  const tablist = document.querySelector('.people__tabs');
  if (tablist) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panel = document.getElementById('panel-people');
    const plate = document.querySelector('.people__plate');
    const img = plate.querySelector('img');
    let swap;
    const activate = (tab, focus) => {
      tabs.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      panel.setAttribute('aria-labelledby', tab.id);
      const key = tab.id.replace('tab-', '');
      panel.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
      clearTimeout(swap);
      const apply = () => { img.src = tab.dataset.img; img.width = +tab.dataset.w; img.height = +tab.dataset.h; img.alt = tab.dataset.alt; plate.classList.remove('is-swapping'); };
      if (reduce.matches) apply(); else { plate.classList.add('is-swapping'); swap = setTimeout(apply, 200); }
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => activate(tab));
      tab.addEventListener('keydown', (e) => {
        // right-to-left: ArrowLeft moves forward
        const step = { ArrowLeft: 1, ArrowRight: -1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        activate(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
  }

  /* ---------- demo request form ---------- */
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
      trade: (v) => (v ? '' : 'اختر نشاط المنشأة.'),
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

    const say = (html) => { status.innerHTML = html; status.hidden = false; };
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = [...form.querySelectorAll('input, select')];
      const bad = fields.filter((f) => !check(f));
      if (bad.length) { bad[0].focus(); return; }
      const data = Object.fromEntries(new FormData(form));
      data.phone = latin(data.phone).replace(/[\s-]/g, '');
      const cfg = window.OPSONE_LANDING || {};

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
          form.reset();
          say(`وصل طلبك. سنتواصل معك على <span dir="ltr">${esc(data.phone)}</span> لتحديد موعد العرض.`);
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
        const body = [`الاسم: ${data.name}`, `المنشأة: ${data.company}`, `الجوال: ${data.phone}`, `النشاط: ${data.trade}`, `المدينة: ${data.city || '-'}`].join('\n');
        location.href = `mailto:${encodeURIComponent(cfg.demoEmail)}?subject=${encodeURIComponent('طلب عرض تجريبي لـ OpsOne')}&body=${encodeURIComponent(body)}`;
        say(`فتحنا بريدك برسالة جاهزة إلى <span dir="ltr">${esc(cfg.demoEmail)}</span>. يصلنا طلبك بعد أن ترسلها من هناك.`);
        return;
      }

      say('لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد.');
    });
  }
})();
