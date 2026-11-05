/* OpsOne site home, variant B: the hero keeps the order's line; every other section has a form of its own.
   GSAP + ScrollTrigger only for the pinned journey timeline; everything else is CSS and the Web Animations API.
   Everything is readable without this file; it only adds the switches and the motion. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isRTL = document.dir === 'rtl';
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';
  const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const centre = (el) => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; };
  // run fn once, the first time el is at least `t` on screen
  const once = (el, fn, t = 0.4) => {
    if (!el) return;
    if (!('IntersectionObserver' in window)) { fn(); return; }
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); fn(); } }, { threshold: t });
    io.observe(el);
  };
  const count = (el, to, from = 0, ms = 1200) => {
    const t0 = performance.now();
    const tick = (now) => {
      const k = Math.min(1, (now - t0) / ms), e = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(from + (to - from) * e);
      if (k < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  /* ---------- hero: one order on one line ---------- */
  // Every value comes from the approved copy and its demo order ORD-2026-00178.
  const screens = {
    showroom: ['فرصة البيع INT-0231', 'المعرض', [['عبدالله سعد، زيارة المعرض', 'فرع الرياض'], ['2 سبتمبر', 'سُجّل الاهتمام'], ['4 سبتمبر', 'طلب موعد قياس'], ['تحويل إلى طلب', ['done', 'ORD-2026-00178']]]],
    office: ['العقد والدفعة الأولى', 'العقد والدفعة', [['عرض سعر QUO-2026-00071', '48,300.00 ر.س'], ['العقد CON-2026-00031', ['done', 'موقّع 23 سبتمبر']], ['الدفعة الأولى 50%، RCV-2026-00412', ['done', 'مسدد']], ['الدفعة الأخيرة عند التركيب', ['next', '24,150.00']]]],
    factory: ['مراحل الطلب ORD-2026-00178', 'المصنع', [['العقد والدفعة الأولى', ['done', 'مكتملة']], ['إصدار أمر التصنيع، الإنتاج', ['done', 'مكتملة']], ['التصنيع، 6 نوفمبر', ['live', 'جارية']], ['التركيب والتسليم، 8 نوفمبر', ['next', 'لاحقًا']]]],
    dispatch: ['مواعيد اليوم', 'التسليم', [['08:00 تركيب، حي النرجس', 'زياد سالم'], ['12:00 قياس، حي الياسمين', 'حسن علي'], ['14:00 تسليم بنود جاهزة', 'عمر يوسف']]],
    site: ['تركيب في موقع العميل', 'موقع العميل', [['ORD-2026-00178، حي النرجس', '8 نوفمبر، 08:00'], ['صور قبل التركيب وبعده', 'من جوال الفني'], ['توقيع العميل على الاستلام', ['next', 'لاحقًا']]]],
  };
  const LIVE = 2;
  const route = document.getElementById('route');
  if (route) {
    const stationsEl = document.getElementById('stations');
    const stations = [...stationsEl.querySelectorAll('.station')];
    const fill = document.getElementById('fill'), ticket = document.getElementById('ticket');
    const screen = document.getElementById('screen'), body = document.getElementById('screen-body'), notch = document.getElementById('notch');
    const vertical = () => matchMedia('(max-width: 760px)').matches;
    const dotOf = (k) => stations.find((s) => s.dataset.k === k).querySelector('.dot');
    const cell = (v) => (Array.isArray(v) ? `<span class="pill-s pill-s--${v[0]}">${v[1]}</span>` : `<b class="nums">${v}</b>`);
    const render = (k) => { const [t, room, rows] = screens[k]; return `<div class="shead"><b class="nums">${t}</b><small>${room}</small></div><ul class="srows">${rows.map((r) => `<li><span class="nums">${r[0]}</span>${cell(r[1])}</li>`).join('')}</ul>`; };
    let current = 'factory', swapTimer, drawn = false;

    // The screen sits in the stations' grid: inline under its station on phones, a full-width second row on wide screens,
    // where it slides under the selected station. It starts under the factory in the HTML, so nothing moves on load.
    const place = (k) => {
      if (vertical()) {
        const st = stations.find((s) => s.dataset.k === k);
        if (screen.previousElementSibling !== st) st.after(screen);
        screen.style.setProperty('--x', '0px');
        return;
      }
      const box = route.getBoundingClientRect(), [dx] = centre(dotOf(k));
      const w = screen.offsetWidth, cx = dx - box.left;
      const fromStart = isRTL ? box.width - cx : cx; // translate is physical: measure from the inline start
      const start = Math.max(0, Math.min(box.width - w, fromStart - w / 2));
      screen.style.setProperty('--x', `${isRTL ? -start : start}px`);
      const n = fromStart - start - 7;
      notch.style.setProperty('--n', `${isRTL ? -n : n}px`);
      if (!isRTL) { notch.style.right = 'auto'; notch.style.left = '0'; }
    };
    // The fill ends exactly on the live station's centre, measured, so it holds when the phone screen opens inline.
    const fillTo = () => {
      if (!drawn) return;
      const track = fill.parentElement.getBoundingClientRect(), [lx, ly] = centre(dotOf('factory'));
      fill.style.transform = vertical()
        ? `scaleY(${((ly - track.top) / track.height).toFixed(4)})`
        : `scaleX(${((isRTL ? track.right - lx : lx - track.left) / track.width).toFixed(4)})`;
    };
    const select = (k, animate) => {
      current = k;
      stations.forEach((s) => s.setAttribute('aria-pressed', String(s.dataset.k === k)));
      place(k);
      fillTo();
      clearTimeout(swapTimer);
      if (!animate) { body.innerHTML = render(k); return; }
      body.classList.add('is-leaving');
      swapTimer = setTimeout(() => { body.innerHTML = render(k); body.classList.remove('is-leaving'); }, reduce ? 0 : 160);
    };
    stations.forEach((s) => s.addEventListener('click', () => { if (s.dataset.k !== current) select(s.dataset.k, true); }));
    const placeTicket = () => {
      const box = stationsEl.getBoundingClientRect(), [lx] = centre(dotOf('factory')), [sx] = centre(dotOf('showroom'));
      ticket.style.left = `${lx - box.left}px`;
      return sx - lx;
    };
    // The draw: the fill runs to the live station (1200ms, ease-in-out: movement on screen), each station lights as
    // the fill reaches it (the symmetric curve passes the middle at half time), and the order tag rides its head.
    const play = () => {
      const DRAW = reduce ? 0 : 1200;
      if (!vertical() && !reduce) {
        ticket.style.transition = 'none';
        ticket.style.setProperty('--dx', `${placeTicket()}px`);
        void ticket.offsetWidth;
        ticket.style.transition = '';
        ticket.style.setProperty('--dx', '0px');
      }
      ticket.classList.add('is-in');
      drawn = true;
      fillTo();
      stations.forEach((s, i) => {
        if (i > LIVE) return;
        setTimeout(() => s.classList.add(i === LIVE ? 'is-live' : 'is-done'), DRAW * (i / LIVE));
      });
    };
    select('factory', false);
    placeTicket();
    addEventListener('resize', () => { place(current); placeTicket(); fillTo(); });
    requestAnimationFrame(() => requestAnimationFrame(play));
  }

  /* ---------- the order in one window: manufacturing finishes, installation starts, the client hears on WhatsApp ---------- */
  const app = document.querySelector('.benefits .app');
  if (app && !reduce && app.animate) {
    const q = (s) => app.querySelector(s);
    const make = q('[data-stg="make"]'), install = q('[data-stg="install"]');
    const toast = document.querySelector('[data-toast]');
    const bars = [...app.querySelectorAll('.stg__bar i, [data-meter]')];
    toast.style.opacity = '0';
    once(app, () => {
      app.querySelectorAll('[data-count]').forEach((el, i) => count(el, +el.dataset.count, i === 2 ? 48300 : 0));
      bars.forEach((b) => { const to = getComputedStyle(b).transform; b.animate([{ transform: 'scaleX(0)' }, { transform: to }], { duration: 1200, easing: EASE_OUT, delay: 150 }); });
      const makeBar = make.querySelector('.stg__bar i');
      setTimeout(() => {
        makeBar.animate([{ transform: getComputedStyle(makeBar).transform }, { transform: 'scaleX(1)' }], { duration: 900, easing: EASE_IN_OUT, fill: 'forwards' });
      }, 2200);
      setTimeout(() => {
        q('[data-task]').classList.add('is-done');
        make.classList.replace('is-now', 'is-done');
        make.querySelector('.stg__dot').innerHTML = '<svg class="ic"><use href="#i-check"/></svg>';
        const c1 = make.querySelector('.chip'); c1.className = 'chip chip--ok'; c1.textContent = 'مكتملة';
        install.classList.add('is-now');
        const c2 = install.querySelector('.chip'); c2.className = 'chip chip--info'; c2.textContent = 'جارية';
        const st = q('[data-status]'); st.textContent = 'قيد التركيب';
        [c1, c2, st].forEach((el, i) => el.animate([{ opacity: 0, transform: 'scale(0.95)' }, { opacity: 1, transform: 'none' }], { duration: 240, delay: i * 50, easing: EASE_OUT, fill: 'backwards' }));
        install.querySelector('.stg__bar i').animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(0.18)' }], { duration: 700, easing: EASE_OUT, fill: 'forwards' });
        toast.style.opacity = '';
        toast.animate([{ opacity: 0, transform: 'translateY(16px)' }, { opacity: 1, transform: 'none' }], { duration: 420, delay: 300, easing: EASE_OUT, fill: 'backwards' });
      }, 3200);
    }, 0.45);
  }

  /* ---------- the lead becomes the order: «تحويل إلى طلب» is pressed once ---------- */
  const duo = document.querySelector('[data-duo]');
  if (duo && !reduce) {
    duo.classList.add('is-before');
    const press = duo.querySelector('[data-convert]');
    once(duo, () => {
      setTimeout(() => press.classList.add('is-pressed'), 900);
      setTimeout(() => {
        press.classList.remove('is-pressed');
        duo.classList.remove('is-before');
        duo.querySelectorAll('.duo__now, .duo__done').forEach((el) => el.animate && el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 300, easing: EASE_OUT }));
      }, 1150);
    }, 0.6);
  }

  /* ---------- roles: a segmented control; the pill slides to the role and the matrix lights its column ---------- */
  const tablist = document.querySelector('.roles__tabs');
  const films = [...document.querySelectorAll('[data-film-item]')];
  let filmInView = false;
  const playActive = () => films.forEach((f) => {
    const v = f.querySelector('video');
    if (!v) return;
    if (!f.hidden && filmInView && !reduce) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause();
  });
  const media = document.querySelector('.roles__media');
  if (media && 'IntersectionObserver' in window) new IntersectionObserver(([e]) => { filmInView = e.isIntersecting; playActive(); }, { threshold: 0.2 }).observe(media);
  if (tablist) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panel = document.getElementById('panel-roles');
    const pick = (tab, focus) => {
      tabs.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      const key = tab.dataset.film;
      panel.setAttribute('aria-labelledby', tab.id);
      const idx = tabs.indexOf(tab);
      const pill = tablist.querySelector('.seg__pill');
      if (pill) pill.style.setProperty('--seg-x', `${(isRTL ? -1 : 1) * idx * pill.offsetWidth}px`);
      const matrix = document.querySelector('.matrix');
      if (matrix) matrix.dataset.col = String({ manager: 3, sales: 4, tech: 5 }[key]);
      panel.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
      films.forEach((f) => { f.hidden = f.dataset.filmItem !== key; });
      playActive();
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => pick(tab));
      tab.addEventListener('keydown', (e) => {
        // right to left: ArrowLeft moves forward
        const step = { ArrowLeft: isRTL ? 1 : -1, ArrowRight: isRTL ? -1 : 1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        pick(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
    pick(tabs.find((t) => t.getAttribute('aria-selected') === 'true') || tabs[0]);
    addEventListener('resize', () => pick(tabs.find((t) => t.getAttribute('aria-selected') === 'true')));
  }

  /* ---------- tools: the client's messages arrive, the signature is drawn, the receipt counts ---------- */
  if (!reduce && document.body.animate) {
    const chat = document.querySelector('.chat');
    once(chat, () => chat.querySelectorAll('.ui-wa').forEach((m, i) => m.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 360, delay: i * 260, easing: EASE_OUT, fill: 'backwards' })), 0.5);
    const sign = document.querySelector('.doc__sign svg path');
    if (sign) once(sign.closest('.doc'), () => { const len = sign.getTotalLength(); sign.animate([{ strokeDasharray: len, strokeDashoffset: len }, { strokeDasharray: len, strokeDashoffset: 0 }], { duration: 1200, easing: EASE_IN_OUT }); }, 0.6);
  }
  document.querySelectorAll('video[data-autoplay]').forEach((v) => {
    if (!('IntersectionObserver' in window)) return;
    new IntersectionObserver(([e]) => { if (e.isIntersecting && !reduce && !v.closest('.is-off')) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause(); }, { threshold: 0.3 }).observe(v);
  });


  /* ---------- «لمن OpsOne؟»: the visitor picks what matches them; their answers show ---------- */
  const pickEl = document.querySelector('[data-pick]');
  if (pickEl) {
    const chips = [...pickEl.querySelectorAll('.pick__chip')];
    const sync = () => chips.forEach((c) => document.getElementById(c.getAttribute('aria-controls')).classList.toggle('is-on', c.getAttribute('aria-pressed') === 'true'));
    chips.forEach((c) => c.addEventListener('click', () => {
      const on = c.getAttribute('aria-pressed') !== 'true';
      // never leave the answer empty: the last chip on stays on
      if (!on && chips.filter((x) => x.getAttribute('aria-pressed') === 'true').length === 1) return;
      c.setAttribute('aria-pressed', String(on));
      sync();
    }));
    pickEl.classList.add('is-live');
    sync();
  }

  /* ---------- «نظام واحد»: the statement at the middle of the screen is lit ---------- */
  const says = [...document.querySelectorAll('.say__item')];
  if (says.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => es.forEach((e) => e.target.classList.toggle('is-lit', e.isIntersecting)), { rootMargin: '-42% 0px -42% 0px' });
    says.forEach((x) => io.observe(x));
  }

  /* ---------- the journey: a horizontal timeline; scroll scrubs it on wide screens, a swipe moves it on phones ---------- */
  const hz = document.querySelector('[data-hz]');
  if (hz) {
    const viewport = hz.querySelector('.hz__viewport'), track = hz.querySelector('[data-hz-track]');
    const panels = [...track.children], now = hz.querySelector('[data-hz-now]'), arrows = [...hz.querySelectorAll('[data-hz-step]')];
    let current = -1, st = null;
    const replay = (panel) => {
      if (reduce || !panel) return;
      const ask = panel.querySelector('[data-approve]');
      if (ask && !ask.dataset.played) {
        ask.dataset.played = '1';
        ask.classList.add('is-pending');
        setTimeout(() => { ask.classList.remove('is-pending'); const st = ask.querySelector('.ver__stamp'); st.classList.add('is-pressing'); }, 1300);
      }
      const ink = panel.querySelector('.sign__pad path');
      if (ink && !ink.dataset.played && ink.animate) {
        ink.dataset.played = '1';
        const len = ink.getTotalLength();
        ink.animate([{ strokeDasharray: len, strokeDashoffset: len }, { strokeDasharray: len, strokeDashoffset: 0 }], { duration: 1100, easing: EASE_IN_OUT, delay: 250, fill: 'backwards' });
      }
    };
    let go = () => {};
    const setActive = (i) => {
      if (i === current) return;
      current = i;
      now.textContent = String(i + 1).padStart(2, '0');
      arrows[0].disabled = i === 0;
      arrows[1].disabled = i === panels.length - 1;
      replay(panels[i]);
    };
    arrows.forEach((b) => b.addEventListener('click', () => go(Math.max(0, Math.min(panels.length - 1, current + +b.dataset.hzStep)))));
    // GSAP is fetched only where the timeline is pinned: wide screens with motion allowed. Phones never download it.
    const load = (src) => new Promise((ok, no) => { const el = document.createElement('script'); el.src = src; el.onload = ok; el.onerror = no; document.head.appendChild(el); });
    const native = () => {
      // native horizontal scroller: the panel nearest the centre is current
      const onScroll = () => {
        const mid = viewport.getBoundingClientRect().left + viewport.clientWidth / 2;
        let best = 0, bd = Infinity;
        panels.forEach((pn, k) => { const r = pn.getBoundingClientRect(); const d = Math.abs(r.left + r.width / 2 - mid); if (d < bd) { bd = d; best = k; } });
        setActive(best);
      };
      let raf = 0;
      viewport.addEventListener('scroll', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(onScroll); }, { passive: true });
      go = (k) => panels[k].scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest', inline: 'center' });
      onScroll();
    };
    const pinned = () => {
      gsap.registerPlugin(ScrollTrigger);
      hz.classList.add('is-pinned');
      const dist = () => track.scrollWidth - document.documentElement.clientWidth;
      const tween = gsap.to(track, { x: () => (isRTL ? 1 : -1) * dist(), ease: 'none' });
      st = ScrollTrigger.create({
        trigger: hz, start: 'top top+=72', end: () => '+=' + dist(), pin: true, scrub: 0.6, animation: tween, invalidateOnRefresh: true, anticipatePin: 1,
        onUpdate: (self) => setActive(Math.min(panels.length - 1, Math.round(self.progress * (panels.length - 1)))),
      });
      go = (k) => scrollTo({ top: st.start + (st.end - st.start) * (k / (panels.length - 1)), behavior: reduce ? 'auto' : 'smooth' });
      if (document.readyState === 'complete') ScrollTrigger.refresh(); else addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
    };
    if (matchMedia('(min-width: 1024px)').matches && !reduce) load('vendor/gsap.min.js').then(() => load('vendor/ScrollTrigger.min.js')).then(pinned, native);
    else native();

    /* step 9: publishing version 4 takes a deliberate press-and-hold (1.2s, linear); letting go early springs back */
    const release = hz.querySelector('[data-release]');
    const hold = release && release.querySelector('[data-publish]');
    if (hold) {
      let timer = 0;
      const done = () => {
        hold.classList.remove('is-holding');
        release.classList.add('is-published');
        hint.hidden = true;
        release.querySelectorAll('.v-new').forEach((el) => el.animate && el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 300, easing: EASE_OUT }));
      };
      const start = (e) => {
        if (release.classList.contains('is-published')) return;
        if (e.type === 'keydown') { if (e.repeat || (e.key !== ' ' && e.key !== 'Enter')) return; e.preventDefault(); }
        hold.classList.add('is-holding');
        clearTimeout(timer);
        timer = setTimeout(done, 1200);
      };
      const stop = () => {
        if (hold.classList.contains('is-holding') && !release.classList.contains('is-published')) { hint.classList.remove('is-nudged'); void hint.offsetWidth; hint.classList.add('is-nudged'); }
        clearTimeout(timer); hold.classList.remove('is-holding');
      };
      hold.addEventListener('pointerdown', start);
      hold.addEventListener('keydown', start);
      ['pointerup', 'pointerleave', 'pointercancel', 'keyup', 'blur'].forEach((ev) => hold.addEventListener(ev, stop));
      hold.addEventListener('click', (e) => e.preventDefault());
      hold.addEventListener('contextmenu', (e) => e.preventDefault());
      hold.setAttribute('aria-describedby', 'hold-note');
      const hint = document.createElement('span'); hint.id = 'hold-note'; hint.className = 'hold__hint'; hint.textContent = 'اضغط مطوّلًا للاعتماد';
      hold.closest('.flow__draft').appendChild(hint);
    }
  }

  /* ---------- channels: a fanned deck; the card you pick comes to the front and its revenue is lit ---------- */
  const deck = document.querySelector('[data-deck]');
  if (deck) {
    const cards = [...deck.querySelectorAll('.deck__card')];
    const stageEl = document.querySelector('.deck__stage');
    const rows = [...document.querySelectorAll('.deck__stage .mix__row')];
    const order = ['showroom', 'store', 'field'];
    const front = (key) => {
      const rest = cards.filter((c) => c.dataset.ch !== key);
      [cards.find((c) => c.dataset.ch === key), ...rest].forEach((c, i) => { c.style.setProperty('--pos', String(i)); c.setAttribute('aria-pressed', String(i === 0)); });
      rows.forEach((r, i) => r.classList.toggle('is-hl', order[i] === key));
      stageEl.classList.add('has-pick');
    };
    cards.forEach((c) => c.addEventListener('click', () => front(c.dataset.ch)));
    front('field');
  }

  /* ---------- tools: one slot prints the document you pick ---------- */
  const printer = document.querySelector('[data-printer]');
  if (printer) {
    const keys = [...printer.querySelectorAll('.printer__key')];
    const papers = [...printer.querySelectorAll('.paper')];
    const caps = [...document.querySelectorAll('.press__caps .tool__cap')];
    let printing = null;
    const print = (k, animate) => {
      keys.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.doc === k)));
      caps.forEach((c) => { c.hidden = c.dataset.doc !== k; });
      papers.forEach((p) => { p.hidden = p.dataset.doc !== k; });
      const paper = papers.find((p) => p.dataset.doc === k);
      if (printing) printing.cancel();
      // the sheet feeds out of the slot: movement on screen, so ease-in-out; a printer never bounces
      if (animate && !reduce && paper.animate) printing = paper.animate([{ transform: 'translateY(-100%)' }, { transform: 'none' }], { duration: 1100, easing: EASE_IN_OUT });
      const n = paper.querySelector('[data-count]');
      if (n && animate && !reduce) count(n, +n.dataset.count);
    };
    keys.forEach((b) => b.addEventListener('click', () => print(b.dataset.doc, true)));
    once(printer, () => print('sign', true), 0.35);
  }

  /* ---------- «كل ما في OpsOne»: the figures count in, the order reference settles out of a scramble ---------- */
  if (!reduce) {
    document.querySelectorAll('[data-countin]').forEach((el) => once(el, () => count(el, +el.dataset.countin, 0, 1400), 0.8));
    const glyphs = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    document.querySelectorAll('[data-scramble]').forEach((el) => once(el, () => {
      const target = el.dataset.scramble, t0 = performance.now(), ms = 900;
      const tick = (now) => {
        const k = Math.min(1, (now - t0) / ms), fixed = Math.floor(k * target.length);
        el.textContent = target.split('').map((ch, i) => (i < fixed || ch === '-' ? ch : glyphs[(Math.random() * glyphs.length) | 0])).join('');
        if (k < 1) requestAnimationFrame(tick); else el.textContent = target;
      };
      requestAnimationFrame(tick);
    }, 0.8));
  }
})();
