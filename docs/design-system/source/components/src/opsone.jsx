/* OpsOne design system — React 18 components.
   Build: esbuild --bundle --format=iife (React read from window.React). */
import { ICONS } from './icons.js';
import { LOGO } from './logo.js';

const React = window.React;
const { createContext, useContext, useId, useState, useRef, useEffect, useCallback, Children, cloneElement, isValidElement } = React;

const cx = (...a) => a.filter(Boolean).join(' ');

/* ------------------------------------------------------------------ locale */
const STRINGS = {
  ar: {
    required: 'مطلوب', loading: 'جارٍ التحميل…', close: 'إغلاق', openMenu: 'فتح القائمة',
    skip: 'تخطَّ إلى المحتوى', error: 'خطأ:', dismiss: 'إخفاء التنبيه', nav: 'التنقل الرئيسي',
    currency: { SAR: 'ر.س' }, optional: 'اختياري',
    clearSearch: 'مسح البحث', search: 'بحث', pagination: 'ترقيم الصفحات', prev: 'السابق', next: 'التالي',
    page: (p, n) => `صفحة ${p} من ${n}`, goToPage: (p) => `الصفحة ${p}`, showing: (a, b, t) => `عرض ${a}–${b} من ${t}`,
    breadcrumbs: 'مسار التنقل', notifications: 'الإشعارات', more: 'خيارات إضافية', menu: 'القائمة',
    choose: 'اختر ملفًا', chooseMany: 'اختر ملفات', drop: 'أو اسحبه وأفلته هنا', remove: (n) => `إزالة ${n}`,
    uploading: 'جارٍ الرفع', uploaded: 'تم الرفع', failed: 'تعذّر الرفع',
    stepDone: 'مكتمل', stepCurrent: 'الخطوة الحالية', stepUpcoming: 'قادم', progress: 'التقدم',
  },
  en: {
    required: 'required', loading: 'Loading…', close: 'Close', openMenu: 'Open menu',
    skip: 'Skip to content', error: 'Error:', dismiss: 'Dismiss', nav: 'Main navigation',
    currency: { SAR: 'SAR' }, optional: 'optional',
    clearSearch: 'Clear search', search: 'Search', pagination: 'Pagination', prev: 'Previous', next: 'Next',
    page: (p, n) => `Page ${p} of ${n}`, goToPage: (p) => `Page ${p}`, showing: (a, b, t) => `Showing ${a}–${b} of ${t}`,
    breadcrumbs: 'Breadcrumb', notifications: 'Notifications', more: 'More options', menu: 'Menu',
    choose: 'Choose a file', chooseMany: 'Choose files', drop: 'or drag and drop it here', remove: (n) => `Remove ${n}`,
    uploading: 'Uploading', uploaded: 'Uploaded', failed: 'Upload failed',
    stepDone: 'Completed', stepCurrent: 'Current step', stepUpcoming: 'Upcoming', progress: 'Progress',
  },
};
const LocaleContext = createContext(null);
function docLang() {
  if (typeof document === 'undefined') return 'ar';
  const l = (document.documentElement.getAttribute('lang') || 'ar').toLowerCase();
  return l.startsWith('en') ? 'en' : 'ar';
}
export function useLocale() {
  const lang = useContext(LocaleContext) || docLang();
  return { lang, dir: lang === 'ar' ? 'rtl' : 'ltr', t: STRINGS[lang] };
}
export function LocaleProvider({ lang = 'ar', children }) {
  return <LocaleContext.Provider value={lang.startsWith('en') ? 'en' : 'ar'}>{children}</LocaleContext.Provider>;
}

/* Page-level helpers: set <html lang dir> and data-theme. */
export function setLanguage(lang) {
  const l = lang && lang.startsWith('en') ? 'en' : 'ar';
  document.documentElement.setAttribute('lang', l);
  document.documentElement.setAttribute('dir', l === 'ar' ? 'rtl' : 'ltr');
}
let themeMqls = [], themeListener = null;
export function setTheme(mode = 'system') {
  const root = document.documentElement;
  themeMqls.forEach(m => m.removeEventListener('change', themeListener));
  themeMqls = []; themeListener = null;
  if (mode === 'system' && window.matchMedia) {
    const dark = window.matchMedia('(prefers-color-scheme: dark)');
    const more = window.matchMedia('(prefers-contrast: more)');
    themeListener = () => root.setAttribute('data-theme', more.matches && !dark.matches ? 'contrast' : dark.matches ? 'dark' : 'light');
    themeListener();
    themeMqls = [dark, more];
    themeMqls.forEach(m => m.addEventListener('change', themeListener));
  } else {
    root.setAttribute('data-theme', ['dark', 'contrast'].includes(mode) ? mode : 'light');
  }
}

/* ------------------------------------------------------------------ Icon */
const FLIP_IN_RTL = new Set(['chevron-left', 'chevron-right', 'arrow-left', 'arrow-right', 'log-out', 'external-link', 'trending-up', 'trending-down']);
export const iconNames = Object.keys(ICONS);
export function Icon({ name, size = 20, label, strokeWidth = 1.75, className, ...rest }) {
  const nodes = ICONS[name];
  if (!nodes) return null;
  const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': true, focusable: 'false' };
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor"
      strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round"
      className={cx('o1-icon', FLIP_IN_RTL.has(name) && 'o1-icon--flip', className)} {...a11y} {...rest}>
      {nodes.map(([tag, attrs], i) => React.createElement(tag, { key: i, ...attrs }))}
    </svg>
  );
}
const renderIcon = (icon, size) => (typeof icon === 'string' ? <Icon name={icon} size={size} /> : icon || null);

/* ------------------------------------------------------------------ Logo */
export function Logo({ variant = 'full', height = 32, title = 'OpsOne', className, ...rest }) {
  const mark = variant === 'mark';
  const vbW = mark ? 64 : 262.11;
  const shapes = mark ? LOGO.filter(e => e[2] !== 'word') : LOGO;
  return (
    <svg viewBox={`0 0 ${vbW} 64`} height={height} width={(height * vbW) / 64} role="img" aria-label={title}
      className={cx('o1-logo', className)} {...rest}>
      {shapes.map(([tag, attrs, role], i) => React.createElement(tag, { key: i, ...attrs, className: 'o1-logo-' + role }))}
    </svg>
  );
}

/* ------------------------------------------------------------------ Spinner */
export function Spinner({ size = 20, label, className }) {
  const { t } = useLocale();
  return (
    <span role="status" className={cx('o1-spinner', className)}>
      <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeWidth="2.5" opacity="0.25" />
        <path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" />
      </svg>
      <span className="o1-sr">{label || t.loading}</span>
    </span>
  );
}

/* ------------------------------------------------------------------ Button */
export const Button = React.forwardRef(function Button(
  { variant = 'primary', size = 'md', icon, iconEnd, iconOnly = false, loading = false, block = false,
    disabled, type = 'button', href, className, children, onClick, ...rest }, ref) {
  const inactive = disabled || loading;
  const cls = cx('o1-btn', 'o1-btn--' + variant, size === 'sm' && 'o1-btn--sm', iconOnly && 'o1-btn--icon',
    block && 'o1-btn--block', loading && 'o1-btn--loading', className);
  const iconSize = 20;
  const content = (
    <>
      {loading ? <span className="o1-btn-spin" aria-hidden="true"><Spinner size={18} label="" /></span> : renderIcon(icon, iconSize)}
      {iconOnly ? null : <span className="o1-btn-label">{children}</span>}
      {!iconOnly && iconEnd ? renderIcon(iconEnd, iconSize) : null}
    </>
  );
  const guard = (e) => { if (inactive) { e.preventDefault(); return; } onClick && onClick(e); };
  if (href) {
    return (
      <a ref={ref} href={inactive ? undefined : href} role={inactive ? 'link' : undefined} aria-disabled={inactive || undefined}
        aria-busy={loading || undefined} className={cls} onClick={guard} {...rest}>{content}</a>
    );
  }
  return (
    <button ref={ref} type={type} className={cls} aria-disabled={inactive || undefined} aria-busy={loading || undefined}
      onClick={guard} {...rest}>{content}</button>
  );
});

/* ------------------------------------------------------------------ Badge / Counter */
export function Badge({ tone = 'neutral', icon, className, children, ...rest }) {
  return (
    <span className={cx('o1-badge', 'o1-badge--' + tone, className)} {...rest}>
      {renderIcon(icon, 16)}{children}
    </span>
  );
}
export function Counter({ value, max = 99, label, className }) {
  const shown = value > max ? `${max}+` : String(value);
  return (
    <span className={cx('o1-counter', className)} aria-label={label} role={label ? 'img' : undefined}>
      <bdi dir="ltr" aria-hidden={label ? true : undefined}>{shown}</bdi>
    </span>
  );
}

/* ------------------------------------------------------------------ Alert */
const ALERT_ICON = { info: 'info', success: 'circle-check', warning: 'triangle-alert', danger: 'circle-x' };
export function Alert({ tone = 'info', title, children, action, onDismiss, className, ...rest }) {
  const { t } = useLocale();
  const role = tone === 'danger' ? 'alert' : 'status';
  return (
    <div role={role} className={cx('o1-alert', 'o1-alert--' + tone, className)} {...rest}>
      <span className="o1-alert-icon"><Icon name={ALERT_ICON[tone]} /></span>
      <div className="o1-alert-body">
        {title ? <p className="o1-alert-title">{title}</p> : null}
        {children ? <div className="o1-alert-text">{children}</div> : null}
      </div>
      {action ? <div className="o1-alert-action">{action}</div> : null}
      {onDismiss ? (
        <button type="button" className="o1-alert-dismiss" aria-label={t.dismiss} onClick={onDismiss}><Icon name="x" size={18} /></button>
      ) : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Field + controls */
const FieldContext = createContext(null);
export function Field({ label, hint, error, required = false, optional = false, disabled = false, id, className, children }) {
  const { t } = useLocale();
  const auto = useId();
  const ctrlId = id || 'o1f' + auto.replace(/:/g, '');
  const hintId = hint ? ctrlId + '-hint' : undefined;
  const errId = error ? ctrlId + '-err' : undefined;
  const describedBy = [errId, hintId].filter(Boolean).join(' ') || undefined;
  const ctx = { id: ctrlId, describedBy, invalid: !!error, required, disabled };
  return (
    <div className={cx('o1-field', disabled && 'o1-field--disabled', className)}>
      {label ? (
        <label className="o1-label" htmlFor={ctrlId}>
          {label}
          {required ? <span className="o1-req" aria-hidden="true">*</span> : null}
          {optional && !required ? <span className="o1-opt">({t.optional})</span> : null}
        </label>
      ) : null}
      <FieldContext.Provider value={ctx}>{children}</FieldContext.Provider>
      {error ? (
        <p className="o1-error" id={errId}><Icon name="circle-alert" size={16} /><span className="o1-sr">{t.error} </span>{error}</p>
      ) : null}
      {hint ? <p className="o1-hint" id={hintId}>{hint}</p> : null}
    </div>
  );
}
function useFieldProps(props) {
  const f = useContext(FieldContext) || {};
  return {
    id: props.id || f.id,
    'aria-describedby': cx(props['aria-describedby'], f.describedBy) || undefined,
    'aria-invalid': props.invalid || f.invalid || undefined,
    required: props.required ?? f.required,
    disabled: props.disabled ?? f.disabled,
  };
}
export const Input = React.forwardRef(function Input({ className, invalid, dir, ...rest }, ref) {
  const fp = useFieldProps({ invalid, ...rest });
  return <input ref={ref} dir={dir} className={cx('o1-input', className)} {...rest} {...fp} />;
});
export const Textarea = React.forwardRef(function Textarea({ className, invalid, rows = 4, ...rest }, ref) {
  const fp = useFieldProps({ invalid, ...rest });
  return <textarea ref={ref} rows={rows} className={cx('o1-input', 'o1-textarea', className)} {...rest} {...fp} />;
});
export const Select = React.forwardRef(function Select({ className, invalid, options, placeholder, children, ...rest }, ref) {
  const fp = useFieldProps({ invalid, ...rest });
  return (
    <span className="o1-select">
      <select ref={ref} className={cx('o1-input', className)} {...rest} {...fp}>
        {placeholder != null ? <option value="" disabled>{placeholder}</option> : null}
        {options ? options.map(o => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>) : children}
      </select>
      <Icon name="chevron-down" size={18} className="o1-select-icon" />
    </span>
  );
});
export const TextField = React.forwardRef(function TextField(
  { label, hint, error, required, optional, disabled, id, multiline = false, className, ...rest }, ref) {
  return (
    <Field label={label} hint={hint} error={error} required={required} optional={optional} disabled={disabled} id={id} className={className}>
      {multiline ? <Textarea ref={ref} {...rest} /> : <Input ref={ref} {...rest} />}
    </Field>
  );
});
export const SelectField = React.forwardRef(function SelectField(
  { label, hint, error, required, optional, disabled, id, className, ...rest }, ref) {
  return (
    <Field label={label} hint={hint} error={error} required={required} optional={optional} disabled={disabled} id={id} className={className}>
      <Select ref={ref} {...rest} />
    </Field>
  );
});

export const Checkbox = React.forwardRef(function Checkbox({ label, hint, error, id, className, ...rest }, ref) {
  const { t } = useLocale();
  const auto = useId();
  const cid = id || 'o1c' + auto.replace(/:/g, '');
  const hintId = hint ? cid + '-hint' : undefined, errId = error ? cid + '-err' : undefined;
  return (
    <div className={cx('o1-check', rest.disabled && 'o1-check--disabled', className)}>
      <span className="o1-check-row">
        <input ref={ref} type="checkbox" id={cid} className="o1-check-input" aria-invalid={error ? true : undefined}
          aria-describedby={[errId, hintId].filter(Boolean).join(' ') || undefined} {...rest} />
        <label htmlFor={cid} className="o1-check-label">{label}</label>
      </span>
      {error ? <p className="o1-error" id={errId}><Icon name="circle-alert" size={16} /><span className="o1-sr">{t.error} </span>{error}</p> : null}
      {hint ? <p className="o1-hint" id={hintId}>{hint}</p> : null}
    </div>
  );
});

export function RadioGroup({ label, name, options = [], value, defaultValue, onChange, hint, error, required, disabled, inline = false, className }) {
  const { t } = useLocale();
  const auto = useId();
  const gid = 'o1r' + auto.replace(/:/g, '');
  const groupName = name || gid;
  const [inner, setInner] = useState(defaultValue);
  const current = value !== undefined ? value : inner;
  const hintId = hint ? gid + '-hint' : undefined, errId = error ? gid + '-err' : undefined;
  return (
    <fieldset className={cx('o1-radios', inline && 'o1-radios--inline', className)} disabled={disabled}
      aria-describedby={[errId, hintId].filter(Boolean).join(' ') || undefined} aria-invalid={error ? true : undefined}
      aria-required={required || undefined}>
      {label ? <legend className="o1-label">{label}{required ? <span className="o1-req" aria-hidden="true">*</span> : null}</legend> : null}
      <div className="o1-radios-list">
        {options.map(o => {
          const oid = gid + '-' + o.value;
          return (
            <span className="o1-check-row" key={o.value}>
              <input type="radio" id={oid} name={groupName} value={o.value} className="o1-check-input"
                checked={current === o.value} disabled={o.disabled}
                onChange={() => { if (value === undefined) setInner(o.value); onChange && onChange(o.value); }} />
              <label htmlFor={oid} className="o1-check-label">{o.label}</label>
            </span>
          );
        })}
      </div>
      {error ? <p className="o1-error" id={errId}><Icon name="circle-alert" size={16} /><span className="o1-sr">{t.error} </span>{error}</p> : null}
      {hint ? <p className="o1-hint" id={hintId}>{hint}</p> : null}
    </fieldset>
  );
}

/* ------------------------------------------------------------------ Tabs */
export function Tabs({ tabs = [], value, defaultValue, onChange, label, className, children }) {
  const { dir } = useLocale();
  const auto = useId();
  const base = 'o1t' + auto.replace(/:/g, '');
  const [inner, setInner] = useState(defaultValue ?? (tabs[0] && tabs[0].id));
  const current = value !== undefined ? value : inner;
  const refs = useRef({});
  const select = (id, focus) => {
    if (value === undefined) setInner(id);
    onChange && onChange(id);
    if (focus && refs.current[id]) refs.current[id].focus();
  };
  const enabled = tabs.filter(t => !t.disabled);
  const onKey = (e) => {
    const i = enabled.findIndex(t => t.id === current);
    let next = null;
    const fwd = dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
    const back = dir === 'rtl' ? 'ArrowRight' : 'ArrowLeft';
    if (e.key === fwd) next = enabled[(i + 1) % enabled.length];
    else if (e.key === back) next = enabled[(i - 1 + enabled.length) % enabled.length];
    else if (e.key === 'Home') next = enabled[0];
    else if (e.key === 'End') next = enabled[enabled.length - 1];
    if (next) { e.preventDefault(); select(next.id, true); }
  };
  const active = tabs.find(t => t.id === current);
  const panel = typeof children === 'function' ? children(current) : (active && active.content) ?? children;
  return (
    <div className={cx('o1-tabs', className)}>
      <div role="tablist" aria-label={label} className="o1-tablist" onKeyDown={onKey}>
        {tabs.map(tab => {
          const sel = tab.id === current;
          return (
            <button key={tab.id} ref={el => (refs.current[tab.id] = el)} type="button" role="tab"
              id={base + '-tab-' + tab.id} aria-selected={sel} aria-controls={base + '-panel'}
              tabIndex={sel ? 0 : -1} disabled={tab.disabled} className="o1-tab" onClick={() => select(tab.id)}>
              {renderIcon(tab.icon, 18)}<span>{tab.label}</span>
              {tab.count != null ? <Counter value={tab.count} /> : null}
            </button>
          );
        })}
      </div>
      {panel != null ? (
        <div role="tabpanel" id={base + '-panel'} aria-labelledby={base + '-tab-' + current} tabIndex={0} className="o1-tabpanel">{panel}</div>
      ) : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Card */
export function Card({ title, description, actions, footer, as: Tag = 'section', padding = 'md', className, children, ...rest }) {
  const auto = useId();
  const hid = title ? 'o1h' + auto.replace(/:/g, '') : undefined;
  return (
    <Tag className={cx('o1-card', padding === 'none' && 'o1-card--flush', className)} aria-labelledby={Tag === 'section' ? hid : undefined} {...rest}>
      {title || actions ? (
        <div className="o1-card-head">
          <div className="o1-card-heading">
            {title ? <h3 id={hid} className="o1-card-title">{title}</h3> : null}
            {description ? <p className="o1-card-desc">{description}</p> : null}
          </div>
          {actions ? <div className="o1-card-actions">{actions}</div> : null}
        </div>
      ) : null}
      <div className="o1-card-body">{children}</div>
      {footer ? <div className="o1-card-foot">{footer}</div> : null}
    </Tag>
  );
}

/* ------------------------------------------------------------------ Skeleton / EmptyState */
export function Skeleton({ width, height = 16, radius, lines, className, style }) {
  if (lines) {
    return (
      <span className="o1-skeleton-lines" aria-hidden="true">
        {Array.from({ length: lines }).map((_, i) => (
          <span key={i} className="o1-skeleton" style={{ height, width: i === lines - 1 ? '60%' : '100%' }} />
        ))}
      </span>
    );
  }
  return <span aria-hidden="true" className={cx('o1-skeleton', className)} style={{ width, height, borderRadius: radius, ...style }} />;
}
export function EmptyState({ icon = 'inbox', title, description, action, compact = false, tone = 'neutral', className }) {
  return (
    <div className={cx('o1-empty', compact && 'o1-empty--compact', tone === 'danger' && 'o1-empty--danger', className)}>
      <span className="o1-empty-icon" aria-hidden="true">{renderIcon(icon, 24)}</span>
      {title ? <p className="o1-empty-title">{title}</p> : null}
      {description ? <p className="o1-empty-desc">{description}</p> : null}
      {action ? <div className="o1-empty-action">{action}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Ref / Amount */
export function Ref({ children, href, onClick, className }) {
  const inner = <bdi dir="ltr" className="o1-ref-text">{children}</bdi>;
  if (href) return <a href={href} onClick={onClick} className={cx('o1-ref', 'o1-ref--link', className)}>{inner}</a>;
  return <span className={cx('o1-ref', className)}>{inner}</span>;
}
const fmtNum = (v, d) => new Intl.NumberFormat('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }).format(v);
export function Amount({ value, currency = 'SAR', decimals = 2, strong = false, className }) {
  const { t, lang } = useLocale();
  const cur = (t.currency && t.currency[currency]) || currency;
  const num = typeof value === 'number' ? fmtNum(value, decimals) : value;
  return (
    <span className={cx('o1-amount', strong && 'o1-amount--strong', className)}>
      {lang === 'en' ? <>{cur}&nbsp;<bdi dir="ltr">{num}</bdi></> : <><bdi dir="ltr">{num}</bdi>&nbsp;{cur}</>}
    </span>
  );
}

/* ------------------------------------------------------------------ Table */
export function Table({ columns = [], rows = [], rowKey = 'id', caption, captionHidden = false, loading = false, loadingRows = 4,
  empty, error, onRowClick, stack = true, className }) {
  const state = error ? 'error' : loading ? 'loading' : rows.length === 0 ? 'empty' : 'rows';
  const alignCls = (c) => (c.align === 'end' ? 'o1-td--end' : c.align === 'center' ? 'o1-td--center' : null);
  const regionRef = useRef(null);
  const [scrolls, setScrolls] = useState(false);
  useEffect(() => {
    const el = regionRef.current;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => setScrolls(el.scrollWidth > el.clientWidth + 1));
    ro.observe(el); return () => ro.disconnect();
  }, []);
  return (
    <div className={cx('o1-table-wrap', stack && 'o1-table--stack', className)}>
      <div ref={regionRef} className="o1-table-scroll" tabIndex={scrolls ? 0 : undefined}
        role={scrolls ? 'region' : undefined} aria-label={scrolls && caption ? caption : undefined}>
        <table className="o1-table" aria-busy={loading || undefined}>
          {caption ? <caption className={captionHidden ? 'o1-sr' : 'o1-caption'}>{caption}</caption> : null}
          <thead>
            <tr>{columns.map(c => <th key={c.key} scope="col" className={alignCls(c)} style={c.width ? { width: c.width } : undefined}>{c.header}</th>)}</tr>
          </thead>
          <tbody>
            {state === 'rows' && rows.map((r, i) => {
              const k = typeof rowKey === 'function' ? rowKey(r, i) : r[rowKey] ?? i;
              return (
                <tr key={k} className={onRowClick ? 'o1-tr--click' : undefined} onClick={onRowClick ? () => onRowClick(r) : undefined}>
                  {columns.map(c => (
                    <td key={c.key} data-label={typeof c.header === 'string' ? c.header : undefined} className={alignCls(c)}>
                      {c.render ? c.render(r) : r[c.key]}
                    </td>
                  ))}
                </tr>
              );
            })}
            {state === 'loading' && Array.from({ length: loadingRows }).map((_, i) => (
              <tr key={'s' + i} className="o1-tr--skeleton">
                {columns.map(c => <td key={c.key} className={alignCls(c)}><Skeleton width={c.align === 'end' ? '64px' : '70%'} height={14} /></td>)}
              </tr>
            ))}
            {(state === 'empty' || state === 'error') && (
              <tr className="o1-tr--state"><td colSpan={columns.length}>{state === 'error' ? error : empty}</td></tr>
            )}
          </tbody>
        </table>
      </div>
      {loading ? <span className="o1-sr"><Spinner /></span> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Dialog */
export function Dialog({ open, onClose, title, description, children, footer, size = 'md', dismissible = true, className }) {
  const { t } = useLocale();
  const ref = useRef(null);
  const auto = useId();
  const hid = 'o1d' + auto.replace(/:/g, '');
  useEffect(() => {
    const d = ref.current; if (!d) return;
    if (open && !d.open) {
      d.showModal ? d.showModal() : d.setAttribute('open', '');
      const af = d.querySelector('[data-autofocus]'); if (af) af.focus();
    }
    if (!open && d.open) { d.close ? d.close() : d.removeAttribute('open'); }
  }, [open]);
  const onCancel = (e) => { e.preventDefault(); if (dismissible && onClose) onClose(); };
  const onBackdrop = (e) => { if (dismissible && e.target === ref.current && onClose) onClose(); };
  return (
    <dialog ref={ref} className={cx('o1-dialog', size === 'sm' && 'o1-dialog--sm', size === 'lg' && 'o1-dialog--lg', className)}
      aria-labelledby={hid} aria-describedby={description ? hid + '-d' : undefined} onCancel={onCancel} onClick={onBackdrop}>
      <div className="o1-dialog-inner">
        <div className="o1-dialog-head">
          <h2 id={hid} className="o1-dialog-title">{title}</h2>
          {dismissible ? (
            <button type="button" className="o1-dialog-close" aria-label={t.close} onClick={() => onClose && onClose()}><Icon name="x" /></button>
          ) : null}
        </div>
        {description ? <p id={hid + '-d'} className="o1-dialog-desc">{description}</p> : null}
        {children ? <div className="o1-dialog-body">{children}</div> : null}
        {footer ? <div className="o1-dialog-foot">{footer}</div> : null}
      </div>
    </dialog>
  );
}

/* ------------------------------------------------------------------ PageHeader */
export function PageHeader({ title, description, actions, back, className }) {
  return (
    <div className={cx('o1-pagehead', className)}>
      <div className="o1-pagehead-text">
        {back ? <div className="o1-pagehead-back">{back}</div> : null}
        <h1 className="o1-pagehead-title">{title}</h1>
        {description ? <p className="o1-pagehead-desc">{description}</p> : null}
      </div>
      {actions ? <div className="o1-pagehead-actions">{actions}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ AppShell / NavItem */
export function NavItem({ label, icon, href = '#', active = false, badge, onClick }) {
  return (
    <a href={href} className={cx('o1-nav-item', active && 'is-active')} aria-current={active ? 'page' : undefined} onClick={onClick}>
      {renderIcon(icon, 20)}<span className="o1-nav-label">{label}</span>
      {badge != null ? <Counter value={badge} /> : null}
    </a>
  );
}
function NavList({ items, activeId, onNavigate, label }) {
  return (
    <nav aria-label={label} className="o1-nav">
      <ul>
        {items.map(it => (
          <li key={it.id}>
            <NavItem {...it} active={it.id === activeId}
              onClick={(e) => { if (onNavigate) { e.preventDefault(); onNavigate(it.id); } }} />
          </li>
        ))}
      </ul>
    </nav>
  );
}
export function AppShell({ nav = [], activeId, onNavigate, brand, topbar, sidebarFooter, navLabel, children, className }) {
  const { t } = useLocale();
  const [open, setOpen] = useState(false);
  const drawer = useRef(null);
  const menuBtn = useRef(null);
  useEffect(() => {
    const d = drawer.current; if (!d) return;
    if (open && !d.open) d.showModal && d.showModal();
    if (!open && d.open) { d.close(); menuBtn.current && menuBtn.current.focus(); }
  }, [open]);
  const brandNode = brand || <Logo height={28} />;
  const go = (id) => { setOpen(false); onNavigate && onNavigate(id); };
  return (
    <div className={cx('o1-shell', className)}>
      <a className="o1-skip" href="#o1-main">{t.skip}</a>
      <aside className="o1-sidebar">
        <div className="o1-sidebar-brand">{brandNode}</div>
        <NavList items={nav} activeId={activeId} onNavigate={onNavigate} label={navLabel || t.nav} />
        {sidebarFooter ? <div className="o1-sidebar-foot">{sidebarFooter}</div> : null}
      </aside>
      <dialog ref={drawer} className="o1-drawer" aria-label={navLabel || t.nav}
        onCancel={(e) => { e.preventDefault(); setOpen(false); }}
        onClick={(e) => { if (e.target === drawer.current) setOpen(false); }}>
        <div className="o1-drawer-inner">
          <div className="o1-sidebar-brand">
            {brandNode}
            <button type="button" className="o1-icon-btn" aria-label={t.close} onClick={() => setOpen(false)}><Icon name="x" /></button>
          </div>
          <NavList items={nav} activeId={activeId} onNavigate={go} label={navLabel || t.nav} />
          {sidebarFooter ? <div className="o1-sidebar-foot">{sidebarFooter}</div> : null}
        </div>
      </dialog>
      <div className="o1-shell-main">
        <header className="o1-topbar">
          <button ref={menuBtn} type="button" className="o1-icon-btn o1-menu-btn" aria-label={t.openMenu}
            aria-expanded={open} aria-haspopup="dialog" onClick={() => setOpen(true)}><Icon name="menu" /></button>
          <div className="o1-topbar-brand">{brand || <Logo height={24} />}</div>
          <div className="o1-topbar-end">{topbar}</div>
        </header>
        <main id="o1-main" tabIndex={-1} className="o1-main">{children}</main>
      </div>
    </div>
  );
}
