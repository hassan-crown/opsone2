/* OpsOne — shared components: feedback, navigation, data display, overlays and upload. */
import { Icon, Button, Spinner, Logo, useLocale } from './opsone.jsx';

const React = window.React;
const { createContext, useContext, useId, useState, useRef, useEffect, useCallback, useMemo, cloneElement, Children, isValidElement } = React;
const cx = (...a) => a.filter(Boolean).join(' ');
const uid = (raw, p) => p + raw.replace(/:/g, '');
const renderIcon = (icon, size) => (typeof icon === 'string' ? <Icon name={icon} size={size} /> : icon || null);

/* ------------------------------------------------------------------ Progress */
export function Progress({ value, max = 100, label, showValue = false, tone = 'brand', className }) {
  const { t } = useLocale();
  const indeterminate = value == null;
  const pct = indeterminate ? 0 : Math.max(0, Math.min(100, (value / max) * 100));
  return (
    <div className={cx('o1-progress', tone === 'danger' && 'o1-progress--danger', className)}>
      <div className="o1-progress-track" role="progressbar" aria-label={label || t.progress}
        aria-valuemin={0} aria-valuemax={max} aria-valuenow={indeterminate ? undefined : value}>
        <span className={cx('o1-progress-bar', indeterminate && 'is-indeterminate')} style={indeterminate ? undefined : { width: pct + '%' }} />
      </div>
      {showValue && !indeterminate ? <span className="o1-progress-value"><bdi dir="ltr">{Math.round(pct)}%</bdi></span> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Toast */
const ToastContext = createContext(null);
const TOAST_ICON = { info: 'info', success: 'circle-check', warning: 'triangle-alert', danger: 'circle-x' };
let toastSeq = 0;
export function ToastProvider({ children, max = 3 }) {
  const { t } = useLocale();
  const [items, setItems] = useState([]);
  const timers = useRef({});
  const dismiss = useCallback((id) => {
    clearTimeout(timers.current[id]); delete timers.current[id];
    setItems(list => list.filter(x => x.id !== id));
  }, []);
  const arm = useCallback((item) => {
    if (!item.duration) return;
    clearTimeout(timers.current[item.id]);
    timers.current[item.id] = setTimeout(() => dismiss(item.id), item.duration);
  }, [dismiss]);
  const show = useCallback((opts) => {
    const o = typeof opts === 'string' ? { message: opts } : opts || {};
    const tone = o.tone || 'success';
    const item = { id: o.id || 'toast-' + (++toastSeq), tone, title: o.title, message: o.message, action: o.action,
      duration: o.duration !== undefined ? o.duration : (tone === 'danger' || o.action ? 0 : 5000) };
    setItems(list => [...list.filter(x => x.id !== item.id), item].slice(-max));
    arm(item);
    return item.id;
  }, [arm, max]);
  useEffect(() => () => Object.values(timers.current).forEach(clearTimeout), []);
  const api = useMemo(() => ({ show, dismiss }), [show, dismiss]);
  const pause = (id) => clearTimeout(timers.current[id]);
  const resume = (item) => arm(item);
  const polite = items.filter(i => i.tone !== 'danger'), urgent = items.filter(i => i.tone === 'danger');
  const renderItem = (it) => (
    <div key={it.id} className={cx('o1-toast', 'o1-toast--' + it.tone)}
      onMouseEnter={() => pause(it.id)} onMouseLeave={() => resume(it)} onFocus={() => pause(it.id)} onBlur={() => resume(it)}>
      <span className="o1-toast-icon"><Icon name={TOAST_ICON[it.tone]} /></span>
      <div className="o1-toast-body">
        {it.title ? <p className="o1-toast-title">{it.title}</p> : null}
        {it.message ? <p className="o1-toast-text">{it.message}</p> : null}
      </div>
      {it.action ? <div className="o1-toast-action">{it.action}</div> : null}
      <button type="button" className="o1-alert-dismiss" aria-label={t.dismiss} onClick={() => dismiss(it.id)}><Icon name="x" size={18} /></button>
    </div>
  );
  return (
    <ToastContext.Provider value={api}>
      {children}
      <section className="o1-toasts" aria-label={t.notifications}>
        <div role="status" aria-live="polite" className="o1-toasts-group">{polite.map(renderItem)}</div>
        <div role="alert" aria-live="assertive" className="o1-toasts-group">{urgent.map(renderItem)}</div>
      </section>
    </ToastContext.Provider>
  );
}
export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>.');
  return ctx;
}

/* ------------------------------------------------------------------ Switch */
export function Switch({ label, description, checked, defaultChecked = false, onChange, disabled = false, id, className }) {
  const auto = useId();
  const sid = id || uid(auto, 'o1s');
  const [inner, setInner] = useState(defaultChecked);
  const on = checked !== undefined ? checked : inner;
  const toggle = () => { if (disabled) return; if (checked === undefined) setInner(!on); onChange && onChange(!on); };
  return (
    <div className={cx('o1-switch', disabled && 'is-disabled', className)}>
      <span className="o1-switch-text">
        <span id={sid + '-l'} className="o1-switch-label" onClick={toggle}>{label}</span>
        {description ? <span id={sid + '-d'} className="o1-hint">{description}</span> : null}
      </span>
      <button type="button" role="switch" id={sid} aria-checked={on} aria-labelledby={sid + '-l'}
        aria-describedby={description ? sid + '-d' : undefined} disabled={disabled} className="o1-switch-control" onClick={toggle}>
        <span className="o1-switch-thumb" />
      </button>
    </div>
  );
}

/* ------------------------------------------------------------------ SearchField */
export const SearchField = React.forwardRef(function SearchField(
  { label, labelHidden = true, value, defaultValue = '', onChange, onSearch, placeholder, loading = false, id, className, ...rest }, ref) {
  const { t } = useLocale();
  const auto = useId();
  const sid = id || uid(auto, 'o1q');
  const [inner, setInner] = useState(defaultValue);
  const v = value !== undefined ? value : inner;
  const inputRef = useRef(null);
  const set = (nv) => { if (value === undefined) setInner(nv); onChange && onChange(nv); };
  return (
    <div className={cx('o1-search', className)} role="search">
      <label htmlFor={sid} className={labelHidden ? 'o1-sr' : 'o1-label'}>{label || t.search}</label>
      <span className="o1-search-box">
        <span className="o1-search-icon">{loading ? <Spinner size={18} /> : <Icon name="search" size={18} />}</span>
        <input ref={el => { inputRef.current = el; if (typeof ref === 'function') ref(el); else if (ref) ref.current = el; }}
          id={sid} type="search" className="o1-input o1-search-input" value={v} placeholder={placeholder}
          onChange={e => set(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter') onSearch && onSearch(v); if (e.key === 'Escape' && v) { e.preventDefault(); set(''); } }}
          autoComplete="off" {...rest} />
        {v ? (
          <button type="button" className="o1-search-clear" aria-label={t.clearSearch}
            onClick={() => { set(''); onSearch && onSearch(''); inputRef.current && inputRef.current.focus(); }}>
            <Icon name="x" size={18} />
          </button>
        ) : null}
      </span>
    </div>
  );
});

/* ------------------------------------------------------------------ Pagination */
function pageList(page, count) {
  if (count <= 7) return Array.from({ length: count }, (_, i) => i + 1);
  const out = [1];
  const start = Math.max(2, page - 1), end = Math.min(count - 1, page + 1);
  if (start > 2) out.push('…');
  for (let i = start; i <= end; i++) out.push(i);
  if (end < count - 1) out.push('…');
  out.push(count);
  return out;
}
export function Pagination({ page, pageCount, onChange, total, pageSize, label, className }) {
  const { t } = useLocale();
  if (!pageCount || pageCount < 2) return null;
  const go = (p) => { if (p >= 1 && p <= pageCount && p !== page) onChange && onChange(p); };
  const summary = total != null && pageSize ? t.showing((page - 1) * pageSize + 1, Math.min(page * pageSize, total), total) : null;
  return (
    <nav className={cx('o1-pagination', className)} aria-label={label || t.pagination}>
      {summary ? <p className="o1-pagination-summary"><bdi>{summary}</bdi></p> : null}
      <div className="o1-pagination-controls">
        <Button variant="secondary" size="sm" icon="chevron-left" disabled={page <= 1} onClick={() => go(page - 1)}>{t.prev}</Button>
        <ul className="o1-pagination-pages">
          {pageList(page, pageCount).map((p, i) => (
            <li key={i}>
              {p === '…' ? <span className="o1-pagination-gap" aria-hidden="true">…</span> : (
                <button type="button" className={cx('o1-page', p === page && 'is-current')} aria-current={p === page ? 'page' : undefined}
                  aria-label={t.goToPage(p)} onClick={() => go(p)}><bdi dir="ltr">{p}</bdi></button>
              )}
            </li>
          ))}
        </ul>
        <span className="o1-pagination-compact" aria-hidden="true">{t.page(page, pageCount)}</span>
        <Button variant="secondary" size="sm" iconEnd="chevron-right" disabled={page >= pageCount} onClick={() => go(page + 1)}>{t.next}</Button>
      </div>
    </nav>
  );
}

/* ------------------------------------------------------------------ Breadcrumbs */
export function Breadcrumbs({ items = [], label, className }) {
  const { t } = useLocale();
  return (
    <nav aria-label={label || t.breadcrumbs} className={cx('o1-crumbs', className)}>
      <ol>
        {items.map((it, i) => {
          const last = i === items.length - 1;
          return (
            <li key={i}>
              {last ? <span aria-current="page" className="o1-crumb-current">{it.label}</span>
                : <a href={it.href || '#'} onClick={it.onClick} className="o1-crumb-link">{it.label}</a>}
              {!last ? <Icon name="chevron-right" size={16} className="o1-crumb-sep" /> : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

/* ------------------------------------------------------------------ Tooltip */
export function Tooltip({ content, placement = 'top', delay = 400, children }) {
  const auto = useId();
  const tid = uid(auto, 'o1tt');
  const [open, setOpen] = useState(false);
  const timer = useRef(null);
  const show = () => { clearTimeout(timer.current); timer.current = setTimeout(() => setOpen(true), delay); };
  const hide = () => { clearTimeout(timer.current); setOpen(false); };
  useEffect(() => {
    if (!open) return;
    const k = (e) => { if (e.key === 'Escape') hide(); };
    document.addEventListener('keydown', k); return () => document.removeEventListener('keydown', k);
  }, [open]);
  useEffect(() => () => clearTimeout(timer.current), []);
  const child = Children.only(children);
  const trigger = isValidElement(child) ? cloneElement(child, {
    'aria-describedby': cx(child.props['aria-describedby'], tid),
    onMouseEnter: (e) => { show(); child.props.onMouseEnter && child.props.onMouseEnter(e); },
    onMouseLeave: (e) => { hide(); child.props.onMouseLeave && child.props.onMouseLeave(e); },
    onFocus: (e) => { setOpen(true); child.props.onFocus && child.props.onFocus(e); },
    onBlur: (e) => { hide(); child.props.onBlur && child.props.onBlur(e); },
  }) : child;
  return (
    <span className="o1-tooltip-wrap" onMouseLeave={hide}>
      {trigger}
      <span role="tooltip" id={tid} className={cx('o1-tooltip', 'o1-tooltip--' + placement, open && 'is-open')}>{content}</span>
    </span>
  );
}

/* ------------------------------------------------------------------ Menu */
export function Menu({ label, items = [], icon = 'ellipsis', iconOnly, variant = 'secondary', size = 'md', align = 'end', className }) {
  const { t } = useLocale();
  const auto = useId();
  const mid = uid(auto, 'o1m');
  const [open, setOpen] = useState(false);
  const btn = useRef(null), list = useRef(null), wrap = useRef(null);
  const onlyIcon = iconOnly !== undefined ? iconOnly : !label;
  const enabled = () => Array.from(list.current ? list.current.querySelectorAll('[role="menuitem"]:not([aria-disabled="true"])') : []);
  const close = (refocus = true) => { setOpen(false); if (refocus && btn.current) btn.current.focus(); };
  const openWith = (which) => { setOpen(true); setTimeout(() => { const e = enabled(); const el = which === 'last' ? e[e.length - 1] : e[0]; el && el.focus(); }, 0); };
  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => { if (wrap.current && !wrap.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onDoc); return () => document.removeEventListener('mousedown', onDoc);
  }, [open]);
  const onListKey = (e) => {
    const els = enabled(); const i = els.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); els[(i + 1) % els.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); els[(i - 1 + els.length) % els.length].focus(); }
    else if (e.key === 'Home') { e.preventDefault(); els[0].focus(); }
    else if (e.key === 'End') { e.preventDefault(); els[els.length - 1].focus(); }
    else if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'Tab') { setOpen(false); }
  };
  const pick = (it) => { if (it.disabled) return; close(); it.onSelect && it.onSelect(); };
  return (
    <span ref={wrap} className={cx('o1-menu', className)}>
      <Button ref={btn} variant={variant} size={size} icon={icon} iconOnly={onlyIcon} aria-label={onlyIcon ? (label || t.more) : undefined}
        aria-haspopup="menu" aria-expanded={open} aria-controls={mid}
        onClick={() => (open ? close(false) : openWith('first'))}
        onKeyDown={(e) => { if (e.key === 'ArrowDown') { e.preventDefault(); openWith('first'); } if (e.key === 'ArrowUp') { e.preventDefault(); openWith('last'); } }}>
        {onlyIcon ? null : label}
      </Button>
      <div ref={list} id={mid} role="menu" aria-label={label || t.more} hidden={!open}
        className={cx('o1-menu-list', align === 'start' ? 'o1-menu--start' : 'o1-menu--end')} onKeyDown={onListKey}>
        {items.map((it, i) => it.separator ? <div key={i} role="separator" className="o1-menu-sep" /> : (
          <div key={it.id || i} role="menuitem" tabIndex={-1} aria-disabled={it.disabled || undefined}
            className={cx('o1-menu-item', it.danger && 'is-danger')}
            onClick={() => pick(it)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(it); } }}>
            {renderIcon(it.icon, 18)}<span>{it.label}</span>
          </div>
        ))}
      </div>
    </span>
  );
}

/* ------------------------------------------------------------------ Avatar */
function initials(name = '') {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '';
  const first = parts[0].replace(/^(ال)/, '');
  const take = (w) => Array.from(w)[0] || '';
  return parts.length === 1 ? take(first) : take(first) + '\u200C' + take(parts[parts.length - 1].replace(/^(ال)/, ''));
}
export function Avatar({ name, src, size = 'md', decorative = false, className }) {
  const [broken, setBroken] = useState(false);
  const a11y = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': name };
  return (
    <span className={cx('o1-avatar', 'o1-avatar--' + size, className)} {...a11y}>
      {src && !broken ? <img src={src} alt="" onError={() => setBroken(true)} /> : <span aria-hidden="true">{initials(name)}</span>}
    </span>
  );
}

/* ------------------------------------------------------------------ DescriptionList */
export function DescriptionList({ items = [], columns = 2, className }) {
  return (
    <dl className={cx('o1-dl', 'o1-dl--' + columns, className)}>
      {items.map((it, i) => (
        <div key={it.key || i} className={cx('o1-dl-item', it.wide && 'is-wide')}>
          <dt>{it.label}</dt>
          <dd>{it.value == null || it.value === '' ? <span className="o1-dl-empty">—</span> : it.value}</dd>
        </div>
      ))}
    </dl>
  );
}

/* ------------------------------------------------------------------ StatCard */
export function StatCard({ label, value, delta, hint, icon, loading = false, href, className }) {
  const Tag = href ? 'a' : 'div';
  const up = delta && delta.direction === 'up';
  const good = delta && (delta.positive !== undefined ? delta.positive : up);
  return (
    <Tag href={href} className={cx('o1-stat', href && 'o1-stat--link', className)} aria-busy={loading || undefined}>
      <div className="o1-stat-head">
        <span className="o1-stat-label">{label}</span>
        {icon ? <span className="o1-stat-icon" aria-hidden="true">{renderIcon(icon, 20)}</span> : null}
      </div>
      {loading ? <span className="o1-skeleton" style={{ width: '50%', height: 28, marginBlock: 4 }} aria-hidden="true" />
        : <p className="o1-stat-value"><bdi>{value}</bdi></p>}
      {!loading && (delta || hint) ? (
        <p className="o1-stat-foot">
          {delta ? (
            <span className={cx('o1-stat-delta', good ? 'is-good' : 'is-bad')}>
              <Icon name={up ? 'trending-up' : 'trending-down'} size={16} />
              <bdi dir="ltr">{delta.value}</bdi>
              {delta.label ? <span className="o1-sr"> {delta.label}</span> : null}
            </span>
          ) : null}
          {hint ? <span className="o1-stat-hint">{hint}</span> : null}
        </p>
      ) : null}
    </Tag>
  );
}

/* ------------------------------------------------------------------ Steps */
export function Steps({ steps = [], current = 0, label, orientation = 'auto', className }) {
  const { t } = useLocale();
  return (
    <div className={cx('o1-steps-wrap', className)}>
    <ol className={cx('o1-steps', 'o1-steps--' + orientation)} aria-label={label}>
      {steps.map((s, i) => {
        const state = i < current ? 'done' : i === current ? 'current' : 'upcoming';
        return (
          <li key={s.id || i} className={'o1-step is-' + state} aria-current={state === 'current' ? 'step' : undefined}>
            <span className="o1-step-marker" aria-hidden="true">
              {state === 'done' ? <Icon name="check" size={16} strokeWidth={2.25} /> : <bdi dir="ltr">{i + 1}</bdi>}
            </span>
            <span className="o1-step-text">
              <span className="o1-step-label">{s.label}</span>
              {s.description ? <span className="o1-step-desc">{s.description}</span> : null}
              <span className="o1-sr">{state === 'done' ? t.stepDone : state === 'current' ? t.stepCurrent : t.stepUpcoming}</span>
            </span>
          </li>
        );
      })}
    </ol>
    </div>
  );
}

/* ------------------------------------------------------------------ SiteHeader */
export function SiteHeader({ nav = [], activeId, brand, actions, navLabel, onNavigate, className }) {
  const { t } = useLocale();
  const [open, setOpen] = useState(false);
  const drawer = useRef(null), menuBtn = useRef(null);
  useEffect(() => {
    const d = drawer.current; if (!d) return;
    if (open && !d.open) d.showModal && d.showModal();
    if (!open && d.open) { d.close(); menuBtn.current && menuBtn.current.focus(); }
  }, [open]);
  const links = (onPick) => (
    <ul className="o1-site-links">
      {nav.map(it => (
        <li key={it.id}>
          <a href={it.href || '#'} className={cx('o1-site-link', it.id === activeId && 'is-active')} aria-current={it.id === activeId ? 'page' : undefined}
            onClick={(e) => { if (onNavigate) { e.preventDefault(); onNavigate(it.id); } onPick && onPick(); }}>{it.label}</a>
        </li>
      ))}
    </ul>
  );
  const brandNode = brand || <Logo height={28} />;
  return (
    <header className={cx('o1-site', className)}>
      <a className="o1-skip" href="#o1-main">{t.skip}</a>
      <div className="o1-site-inner">
        <a href="/" className="o1-site-brand">{brandNode}</a>
        <nav aria-label={navLabel || t.nav} className="o1-site-nav">{links()}</nav>
        <div className="o1-site-actions">{actions}</div>
        {nav.length ? (
          <button ref={menuBtn} type="button" className="o1-icon-btn o1-site-menu" aria-label={t.openMenu}
            aria-expanded={open} aria-haspopup="dialog" onClick={() => setOpen(true)}><Icon name="menu" /></button>
        ) : null}
      </div>
      <dialog ref={drawer} className="o1-drawer" aria-label={navLabel || t.nav}
        onCancel={(e) => { e.preventDefault(); setOpen(false); }} onClick={(e) => { if (e.target === drawer.current) setOpen(false); }}>
        <div className="o1-drawer-inner">
          <div className="o1-sidebar-brand">{brandNode}
            <button type="button" className="o1-icon-btn" aria-label={t.close} onClick={() => setOpen(false)}><Icon name="x" /></button>
          </div>
          <nav aria-label={navLabel || t.nav} className="o1-site-drawer-nav">{links(() => setOpen(false))}</nav>
          {actions ? <div className="o1-sidebar-foot o1-site-drawer-actions">{actions}</div> : null}
        </div>
      </dialog>
    </header>
  );
}

/* ------------------------------------------------------------------ FileUpload */
const fmtSize = (b) => (b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(0) + ' KB' : (b / 1048576).toFixed(1) + ' MB');
export function FileUpload({ label, hint, error, accept, multiple = false, files = [], onSelect, onRemove, disabled = false, required, id, className }) {
  const { t } = useLocale();
  const auto = useId();
  const fid = id || uid(auto, 'o1u');
  const input = useRef(null);
  const [over, setOver] = useState(false);
  const hintId = hint ? fid + '-hint' : undefined, errId = error ? fid + '-err' : undefined;
  const take = (list) => { if (list && list.length && onSelect) onSelect(multiple ? Array.from(list) : [list[0]]); };
  return (
    <div className={cx('o1-field', 'o1-upload', disabled && 'o1-field--disabled', className)}>
      {label ? <span className="o1-label" id={fid + '-l'}>{label}{required ? <span className="o1-req" aria-hidden="true">*</span> : null}</span> : null}
      <div className={cx('o1-drop', over && 'is-over', error && 'is-invalid')}
        onDragOver={(e) => { if (disabled) return; e.preventDefault(); setOver(true); }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); if (!disabled) take(e.dataTransfer.files); }}>
        <span className="o1-drop-icon" aria-hidden="true"><Icon name="upload" /></span>
        <span className="o1-drop-text">
          <Button variant="secondary" size="sm" disabled={disabled} onClick={() => input.current && input.current.click()}
            aria-describedby={[errId, hintId].filter(Boolean).join(' ') || undefined} aria-labelledby={label ? fid + '-l ' + fid + '-b' : undefined} id={fid + '-b'}>
            {multiple ? t.chooseMany : t.choose}
          </Button>
          <span className="o1-drop-or">{t.drop}</span>
        </span>
        <input ref={input} id={fid} type="file" className="o1-sr" tabIndex={-1} aria-hidden="true" accept={accept} multiple={multiple}
          disabled={disabled} onChange={(e) => { take(e.target.files); e.target.value = ''; }} />
      </div>
      {error ? <p className="o1-error" id={errId}><Icon name="circle-alert" size={16} /><span className="o1-sr">{t.error} </span>{error}</p> : null}
      {hint ? <p className="o1-hint" id={hintId}>{hint}</p> : null}
      {files.length ? (
        <ul className="o1-files">
          {files.map(f => (
            <li key={f.id || f.name} className={cx('o1-file', f.status === 'error' && 'is-error')}>
              <span className="o1-file-icon" aria-hidden="true"><Icon name={f.status === 'error' ? 'circle-alert' : f.status === 'done' ? 'circle-check' : 'file'} /></span>
              <span className="o1-file-main">
                <span className="o1-file-name"><bdi>{f.name}</bdi></span>
                <span className="o1-file-meta">
                  {f.size != null ? <bdi dir="ltr">{fmtSize(f.size)}</bdi> : null}
                  {f.status === 'uploading' ? <span> · {t.uploading}</span> : f.status === 'done' ? <span> · {t.uploaded}</span>
                    : f.status === 'error' ? <span className="o1-file-err"> · {f.error || t.failed}</span> : null}
                </span>
                {f.status === 'uploading' ? <Progress value={f.progress} label={t.uploading + ' ' + f.name} /> : null}
              </span>
              {onRemove ? (
                <button type="button" className="o1-alert-dismiss" aria-label={t.remove(f.name)} onClick={() => onRemove(f.id || f.name)}><Icon name="x" size={18} /></button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
