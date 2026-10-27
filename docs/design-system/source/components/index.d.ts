import type * as React from 'react';

/** Names of the bundled Lucide icons (named by their LTR meaning; directional ones mirror in RTL). */
export type IconName =
  | 'layout-dashboard' | 'clipboard-list' | 'users' | 'calendar' | 'receipt' | 'package' | 'settings' | 'bell'
  | 'search' | 'menu' | 'x' | 'log-out' | 'user' | 'plus' | 'pencil' | 'trash-2' | 'download' | 'upload'
  | 'filter' | 'ellipsis' | 'copy' | 'refresh-cw' | 'eye' | 'eye-off' | 'printer' | 'chevron-left'
  | 'chevron-right' | 'chevron-down' | 'chevron-up' | 'arrow-left' | 'arrow-right' | 'external-link' | 'info'
  | 'triangle-alert' | 'circle-x' | 'circle-check' | 'circle-alert' | 'check' | 'clock' | 'inbox' | 'file-text'
  | 'phone' | 'map-pin' | 'sun' | 'moon' | 'languages' | 'lock' | 'minus' | 'trending-up' | 'trending-down' | 'paperclip' | 'file';
type IconSlot = IconName | React.ReactNode;

export interface LogoProps extends React.SVGAttributes<SVGSVGElement> { variant?: 'full' | 'mark'; height?: number; title?: string }
export declare function Logo(props: LogoProps): React.ReactElement;

export interface IconProps extends React.SVGAttributes<SVGSVGElement> { name: IconName; size?: number; label?: string; strokeWidth?: number }
export declare function Icon(props: IconProps): React.ReactElement | null;

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 'md' | 'sm';
  icon?: IconSlot; iconEnd?: IconSlot;
  /** Square icon button: requires aria-label. */
  iconOnly?: boolean;
  loading?: boolean; block?: boolean;
  /** Renders an <a> with the same look. */
  href?: string;
}
export declare const Button: React.ForwardRefExoticComponent<ButtonProps & React.RefAttributes<HTMLButtonElement>>;

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> { tone?: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand'; icon?: IconSlot }
export declare function Badge(props: BadgeProps): React.ReactElement;

export interface CounterProps { value: number; max?: number; /** Full spoken text, e.g. "3 pending approvals". */ label?: string; className?: string }
export declare function Counter(props: CounterProps): React.ReactElement;

export interface AlertProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'title'> {
  tone?: 'info' | 'success' | 'warning' | 'danger'; title?: React.ReactNode; action?: React.ReactNode; onDismiss?: () => void;
}
export declare function Alert(props: AlertProps): React.ReactElement;

interface FieldBase { label?: React.ReactNode; hint?: React.ReactNode; error?: React.ReactNode; required?: boolean; optional?: boolean; disabled?: boolean; id?: string; className?: string }
export interface FieldProps extends FieldBase { children: React.ReactNode }
export declare function Field(props: FieldProps): React.ReactElement;
export interface TextFieldProps extends FieldBase, Omit<React.InputHTMLAttributes<HTMLInputElement> & React.TextareaHTMLAttributes<HTMLTextAreaElement>, 'className' | 'id'> {
  multiline?: boolean; rows?: number; dir?: 'ltr' | 'rtl' | 'auto';
}
export declare const TextField: React.ForwardRefExoticComponent<TextFieldProps & React.RefAttributes<HTMLInputElement>>;
export interface SelectOption { value: string; label: React.ReactNode; disabled?: boolean }
export interface SelectFieldProps extends FieldBase, Omit<React.SelectHTMLAttributes<HTMLSelectElement>, 'className' | 'id'> { options?: SelectOption[]; placeholder?: string }
export declare const SelectField: React.ForwardRefExoticComponent<SelectFieldProps & React.RefAttributes<HTMLSelectElement>>;
export declare const Input: React.ForwardRefExoticComponent<React.InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>;
export declare const Textarea: React.ForwardRefExoticComponent<React.TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>;
export declare const Select: React.ForwardRefExoticComponent<React.SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean; options?: SelectOption[]; placeholder?: string }>;

export interface CheckboxProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> { label: React.ReactNode; hint?: React.ReactNode; error?: React.ReactNode }
export declare const Checkbox: React.ForwardRefExoticComponent<CheckboxProps & React.RefAttributes<HTMLInputElement>>;

export interface RadioGroupProps {
  label: React.ReactNode; name?: string; options: SelectOption[]; value?: string; defaultValue?: string;
  onChange?: (value: string) => void; hint?: React.ReactNode; error?: React.ReactNode; required?: boolean; disabled?: boolean; inline?: boolean; className?: string;
}
export declare function RadioGroup(props: RadioGroupProps): React.ReactElement;

export interface TabItem { id: string; label: React.ReactNode; icon?: IconSlot; count?: number; disabled?: boolean; content?: React.ReactNode }
export interface TabsProps { tabs: TabItem[]; value?: string; defaultValue?: string; onChange?: (id: string) => void; /** Accessible name of the tab list. */ label: string; className?: string; children?: React.ReactNode | ((activeId: string) => React.ReactNode) }
export declare function Tabs(props: TabsProps): React.ReactElement;

export interface CardProps extends Omit<React.HTMLAttributes<HTMLElement>, 'title'> { title?: React.ReactNode; description?: React.ReactNode; actions?: React.ReactNode; footer?: React.ReactNode; as?: 'section' | 'div' | 'article'; padding?: 'md' | 'none' }
export declare function Card(props: CardProps): React.ReactElement;

export interface TableColumn<Row> { key: string; header: React.ReactNode; render?: (row: Row) => React.ReactNode; align?: 'start' | 'end' | 'center'; width?: string }
export interface TableProps<Row> {
  columns: TableColumn<Row>[]; rows: Row[]; rowKey?: string | ((row: Row, i: number) => React.Key);
  /** Always give one; hide it visually with captionHidden. */ caption: string; captionHidden?: boolean;
  loading?: boolean; loadingRows?: number; empty?: React.ReactNode; error?: React.ReactNode;
  onRowClick?: (row: Row) => void; /** Stack rows as cards under 768px (default true). */ stack?: boolean; className?: string;
}
export declare function Table<Row = Record<string, unknown>>(props: TableProps<Row>): React.ReactElement;

export interface DialogProps { open: boolean; onClose: () => void; title: React.ReactNode; description?: React.ReactNode; children?: React.ReactNode; footer?: React.ReactNode; size?: 'sm' | 'md' | 'lg'; dismissible?: boolean; className?: string }
export declare function Dialog(props: DialogProps): React.ReactElement;

export interface SpinnerProps { size?: number; label?: string; className?: string }
export declare function Spinner(props: SpinnerProps): React.ReactElement;
export interface SkeletonProps { width?: number | string; height?: number | string; radius?: number | string; lines?: number; className?: string; style?: React.CSSProperties }
export declare function Skeleton(props: SkeletonProps): React.ReactElement;
export interface EmptyStateProps { icon?: IconSlot; title: React.ReactNode; description?: React.ReactNode; action?: React.ReactNode; compact?: boolean; tone?: 'neutral' | 'danger'; className?: string }
export declare function EmptyState(props: EmptyStateProps): React.ReactElement;

export interface RefProps { children: React.ReactNode; href?: string; onClick?: React.MouseEventHandler; className?: string }
export declare function Ref(props: RefProps): React.ReactElement;
export interface AmountProps { value: number | string; currency?: string; decimals?: number; strong?: boolean; className?: string }
export declare function Amount(props: AmountProps): React.ReactElement;

export interface PageHeaderProps { title: React.ReactNode; description?: React.ReactNode; actions?: React.ReactNode; back?: React.ReactNode; className?: string }
export declare function PageHeader(props: PageHeaderProps): React.ReactElement;

export interface NavItemProps { id?: string; label: React.ReactNode; icon?: IconSlot; href?: string; active?: boolean; badge?: number; onClick?: React.MouseEventHandler }
export declare function NavItem(props: NavItemProps): React.ReactElement;
export interface AppShellProps { nav: (NavItemProps & { id: string })[]; activeId?: string; onNavigate?: (id: string) => void; brand?: React.ReactNode; topbar?: React.ReactNode; sidebarFooter?: React.ReactNode; navLabel?: string; children: React.ReactNode; className?: string }
export declare function AppShell(props: AppShellProps): React.ReactElement;

export interface SiteHeaderProps { nav?: { id: string; label: React.ReactNode; href?: string }[]; activeId?: string; onNavigate?: (id: string) => void; brand?: React.ReactNode; actions?: React.ReactNode; navLabel?: string; className?: string }
export declare function SiteHeader(props: SiteHeaderProps): React.ReactElement;
export interface BreadcrumbsProps { items: { label: React.ReactNode; href?: string; onClick?: React.MouseEventHandler }[]; label?: string; className?: string }
export declare function Breadcrumbs(props: BreadcrumbsProps): React.ReactElement;
export interface PaginationProps { page: number; pageCount: number; onChange: (page: number) => void; total?: number; pageSize?: number; label?: string; className?: string }
export declare function Pagination(props: PaginationProps): React.ReactElement | null;
export type MenuItem = { id?: string; label: React.ReactNode; icon?: IconSlot; onSelect?: () => void; danger?: boolean; disabled?: boolean } | { separator: true };
export interface MenuProps { label?: string; items: MenuItem[]; icon?: IconSlot; iconOnly?: boolean; variant?: ButtonProps['variant']; size?: ButtonProps['size']; align?: 'start' | 'end'; className?: string }
export declare function Menu(props: MenuProps): React.ReactElement;
export interface TooltipProps { content: React.ReactNode; placement?: 'top' | 'bottom'; delay?: number; children: React.ReactElement }
export declare function Tooltip(props: TooltipProps): React.ReactElement;
export interface ToastOptions { id?: string; tone?: 'success' | 'info' | 'warning' | 'danger'; title?: React.ReactNode; message?: React.ReactNode; action?: React.ReactNode; /** ms; 0 keeps it until dismissed. Default 5000 (danger or with action: 0). */ duration?: number }
export declare function ToastProvider(props: { children: React.ReactNode; max?: number }): React.ReactElement;
export declare function useToast(): { show: (opts: ToastOptions | string) => string; dismiss: (id: string) => void };
export interface ProgressProps { value?: number; max?: number; label?: string; showValue?: boolean; tone?: 'brand' | 'danger'; className?: string }
export declare function Progress(props: ProgressProps): React.ReactElement;
export interface SwitchProps { label: React.ReactNode; description?: React.ReactNode; checked?: boolean; defaultChecked?: boolean; onChange?: (checked: boolean) => void; disabled?: boolean; id?: string; className?: string }
export declare function Switch(props: SwitchProps): React.ReactElement;
export interface SearchFieldProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value' | 'defaultValue'> { label?: string; labelHidden?: boolean; value?: string; defaultValue?: string; onChange?: (value: string) => void; onSearch?: (value: string) => void; loading?: boolean }
export declare const SearchField: React.ForwardRefExoticComponent<SearchFieldProps & React.RefAttributes<HTMLInputElement>>;
export interface UploadFile { id?: string | number; name: string; size?: number; status?: 'uploading' | 'done' | 'error'; progress?: number; error?: React.ReactNode }
export interface FileUploadProps { label?: React.ReactNode; hint?: React.ReactNode; error?: React.ReactNode; accept?: string; multiple?: boolean; files?: UploadFile[]; onSelect?: (files: File[]) => void; onRemove?: (id: string | number) => void; disabled?: boolean; required?: boolean; id?: string; className?: string }
export declare function FileUpload(props: FileUploadProps): React.ReactElement;
export interface AvatarProps { name: string; src?: string; size?: 'sm' | 'md' | 'lg'; decorative?: boolean; className?: string }
export declare function Avatar(props: AvatarProps): React.ReactElement;
export interface DescriptionListProps { items: { key?: string; label: React.ReactNode; value?: React.ReactNode; wide?: boolean }[]; columns?: 1 | 2 | 3; className?: string }
export declare function DescriptionList(props: DescriptionListProps): React.ReactElement;
export interface StatCardProps { label: React.ReactNode; value?: React.ReactNode; delta?: { value: string; direction: 'up' | 'down'; positive?: boolean; label?: string }; hint?: React.ReactNode; icon?: IconSlot; href?: string; loading?: boolean; className?: string }
export declare function StatCard(props: StatCardProps): React.ReactElement;
export interface StepsProps { steps: { id?: string; label: React.ReactNode; description?: React.ReactNode }[]; current: number; label?: string; orientation?: 'auto' | 'horizontal' | 'vertical'; className?: string }
export declare function Steps(props: StepsProps): React.ReactElement;

export declare function LocaleProvider(props: { lang: 'ar' | 'en'; children: React.ReactNode }): React.ReactElement;
export declare function useLocale(): { lang: 'ar' | 'en'; dir: 'rtl' | 'ltr'; t: Record<string, unknown> };
/** Sets <html lang dir>. */
export declare function setLanguage(lang: 'ar' | 'en'): void;
/** 'system' follows prefers-color-scheme and prefers-contrast: more. */
export declare function setTheme(mode: 'light' | 'dark' | 'contrast' | 'system'): void;
export declare const iconNames: IconName[];

declare global {
  interface Window {
    OpsOne: {
      Logo: typeof Logo; Icon: typeof Icon; Button: typeof Button; Badge: typeof Badge; Counter: typeof Counter; Alert: typeof Alert;
      Field: typeof Field; Input: typeof Input; Textarea: typeof Textarea; Select: typeof Select; TextField: typeof TextField; SelectField: typeof SelectField;
      Checkbox: typeof Checkbox; RadioGroup: typeof RadioGroup; Tabs: typeof Tabs; Card: typeof Card; Table: typeof Table; Dialog: typeof Dialog;
      Spinner: typeof Spinner; Skeleton: typeof Skeleton; EmptyState: typeof EmptyState; Ref: typeof Ref; Amount: typeof Amount;
      PageHeader: typeof PageHeader; NavItem: typeof NavItem; AppShell: typeof AppShell; SiteHeader: typeof SiteHeader;
      Breadcrumbs: typeof Breadcrumbs; Pagination: typeof Pagination; Menu: typeof Menu; Tooltip: typeof Tooltip; ToastProvider: typeof ToastProvider; useToast: typeof useToast;
      Progress: typeof Progress; Switch: typeof Switch; SearchField: typeof SearchField; FileUpload: typeof FileUpload; Avatar: typeof Avatar;
      DescriptionList: typeof DescriptionList; StatCard: typeof StatCard; Steps: typeof Steps;
      LocaleProvider: typeof LocaleProvider; useLocale: typeof useLocale; setLanguage: typeof setLanguage; setTheme: typeof setTheme; iconNames: typeof iconNames;
    };
  }
}
