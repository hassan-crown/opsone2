import { Head } from '@inertiajs/react';
import {
    Download,
    Inbox,
    Pencil,
    Receipt,
    Search,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Amount } from '@/components/opsone/amount';
import { Breadcrumbs } from '@/components/opsone/breadcrumbs';
import { Counter } from '@/components/opsone/counter';
import { EmptyState } from '@/components/opsone/empty-state';
import {
    CheckboxField,
    RadioGroup,
    SelectField,
    SwitchField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { LanguageSwitch } from '@/components/opsone/language-switch';
import { Logo } from '@/components/opsone/logo';
import { Menu } from '@/components/opsone/menu';
import { PageHeader } from '@/components/opsone/page-header';
import { Ref } from '@/components/opsone/ref';
import { StatCard } from '@/components/opsone/stat-card';
import { ThemeSwitch } from '@/components/opsone/theme-switch';
import { notify } from '@/components/opsone/toast';
import {
    Alert,
    AlertBody,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useLocale } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';
import { dashboard } from '@/routes';

type Copy = Record<string, string>;

const COPY: Record<Locale, Copy> = {
    ar: {
        title: 'نظام تصميم OpsOne',
        desc: 'معاينة المكونات (للتطوير فقط). بدّل اللغة والثيم من الأعلى.',
        colors: 'الألوان (الأدوار)',
        type: 'الخطوط',
        titleSample: 'تفاصيل الطلب',
        bodySample: 'تم تأكيد موعد التركيب وإرسال التفاصيل إلى العميل.',
        buttons: 'الأزرار',
        primary: 'أساسي',
        secondary: 'ثانوي',
        ghost: 'نصي',
        danger: 'حذف',
        loading: 'جارٍ الحفظ',
        disabled: 'معطّل',
        small: 'صغير',
        badges: 'الشارات والعدّاد',
        success: 'مكتمل',
        warning: 'بانتظار',
        error: 'متأخر',
        info: 'جديد',
        neutral: 'مسودة',
        brand: 'قيد التنفيذ',
        alerts: 'التنبيهات',
        alertTitle: 'تم حفظ التغييرات',
        alertBody: 'ستظهر التحديثات للفريق فورًا.',
        forms: 'النماذج',
        name: 'اسم العميل',
        nameHint: 'كما يظهر في العقد.',
        phone: 'رقم الجوال',
        phoneError: 'أدخل رقم جوال صحيحًا.',
        notes: 'ملاحظات',
        city: 'المدينة',
        riyadh: 'الرياض',
        jeddah: 'جدة',
        dammam: 'الدمام',
        agree: 'أوافق على الشروط',
        channel: 'قناة التواصل',
        whatsapp: 'واتساب',
        phoneCall: 'اتصال',
        notify: 'إشعارات الطلبات',
        notifyHint: 'عند تغيّر حالة الطلب.',
        tabs: 'التبويبات',
        overview: 'نظرة عامة',
        activity: 'النشاط',
        files: 'الملفات',
        cards: 'البطاقات والإحصاءات',
        cardTitle: 'جدولة التركيب',
        cardDesc: 'اختر موعدًا يناسب العميل.',
        save: 'حفظ',
        cancel: 'إلغاء',
        orders: 'الطلبات المفتوحة',
        revenue: 'مبيعات الشهر',
        overlays: 'النوافذ والقوائم والتنبيهات المؤقتة',
        openDialog: 'فتح نافذة',
        dialogTitle: 'حذف الطلب؟',
        dialogDesc: 'لا يمكن التراجع عن هذا الإجراء.',
        menu: 'قائمة',
        edit: 'تعديل',
        export: 'تصدير',
        remove: 'حذف',
        tooltip: 'تلميح',
        tooltipText: 'يظهر بعد لحظة',
        toastOk: 'إشعار نجاح',
        toastErr: 'إشعار خطأ',
        feedback: 'التحميل والحالات الفارغة',
        emptyTitle: 'لا توجد طلبات بعد',
        emptyDesc: 'ستظهر هنا الطلبات فور إنشائها.',
        misc: 'مرجع ومبلغ وصورة رمزية',
        search: 'بحث',
        logo: 'الشعار',
        icons: 'الأيقونات الاتجاهية (تنعكس في RTL)',
    },
    en: {
        title: 'OpsOne design system',
        desc: 'Component preview (development only). Switch language and theme from the top.',
        colors: 'Colours (roles)',
        type: 'Typography',
        titleSample: 'Order details',
        bodySample:
            'The installation appointment is confirmed and the customer has been notified.',
        buttons: 'Buttons',
        primary: 'Primary',
        secondary: 'Secondary',
        ghost: 'Ghost',
        danger: 'Delete',
        loading: 'Saving',
        disabled: 'Disabled',
        small: 'Small',
        badges: 'Badges and counter',
        success: 'Completed',
        warning: 'Waiting',
        error: 'Overdue',
        info: 'New',
        neutral: 'Draft',
        brand: 'In progress',
        alerts: 'Alerts',
        alertTitle: 'Changes saved',
        alertBody: 'The team will see the updates right away.',
        forms: 'Forms',
        name: 'Customer name',
        nameHint: 'As shown on the contract.',
        phone: 'Mobile number',
        phoneError: 'Enter a valid mobile number.',
        notes: 'Notes',
        city: 'City',
        riyadh: 'Riyadh',
        jeddah: 'Jeddah',
        dammam: 'Dammam',
        agree: 'I agree to the terms',
        channel: 'Contact channel',
        whatsapp: 'WhatsApp',
        phoneCall: 'Phone call',
        notify: 'Order notifications',
        notifyHint: 'When an order changes status.',
        tabs: 'Tabs',
        overview: 'Overview',
        activity: 'Activity',
        files: 'Files',
        cards: 'Cards and stats',
        cardTitle: 'Schedule installation',
        cardDesc: 'Pick a time that suits the customer.',
        save: 'Save',
        cancel: 'Cancel',
        orders: 'Open orders',
        revenue: 'Sales this month',
        overlays: 'Dialog, menu and toasts',
        openDialog: 'Open dialog',
        dialogTitle: 'Delete this order?',
        dialogDesc: 'This action cannot be undone.',
        menu: 'Menu',
        edit: 'Edit',
        export: 'Export',
        remove: 'Delete',
        tooltip: 'Tooltip',
        tooltipText: 'Appears after a moment',
        toastOk: 'Success toast',
        toastErr: 'Error toast',
        feedback: 'Loading and empty states',
        emptyTitle: 'No orders yet',
        emptyDesc: 'Orders will appear here as soon as they are created.',
        misc: 'Reference, amount and avatar',
        search: 'Search',
        logo: 'Logo',
        icons: 'Directional icons (mirror in RTL)',
    },
};

const ROLES = [
    'background',
    'surface',
    'fill',
    'brand',
    'brand-subtle',
    'highlight',
    'success-bg',
    'warning-bg',
    'danger-bg',
    'info-bg',
    'neutral-bg',
    'text-strong',
    'text-weak',
    'stroke-control',
    'stroke-weak',
    'danger',
];

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <h2 className="text-section text-strong">{title}</h2>
            {children}
        </section>
    );
}

export default function Design() {
    const { locale } = useLocale();
    const c = COPY[locale];
    const [switchOn, setSwitchOn] = useState(true);
    const [radio, setRadio] = useState('whatsapp');

    return (
        <>
            <Head title="Design" />
            <div className="min-h-dvh bg-background">
                <header className="sticky top-0 z-10 flex items-center justify-between gap-2 border-b border-stroke-weak bg-surface p-2 md:px-6">
                    <Logo height={28} />
                    <div className="flex items-center gap-1">
                        <LanguageSwitch />
                        <ThemeSwitch />
                    </div>
                </header>

                <main className="mx-auto w-full max-w-content space-y-10 p-4 md:p-6">
                    <PageHeader title={c.title} description={c.desc} />

                    <Section title={c.colors}>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                            {ROLES.map((role) => (
                                <div key={role} className="space-y-1">
                                    <div
                                        className="h-12 rounded-md border border-stroke-weak"
                                        style={{ background: `var(--${role})` }}
                                    />
                                    <p
                                        dir="ltr"
                                        className="font-mono text-caption text-weak"
                                    >
                                        {role}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section title={c.type}>
                        <Card>
                            <h1 className="text-title text-strong">
                                {c.titleSample}
                            </h1>
                            <h2 className="text-section text-strong">
                                {c.titleSample}
                            </h2>
                            <h3 className="text-subtitle text-strong">
                                {c.titleSample}
                            </h3>
                            <p className="text-body text-strong">
                                {c.bodySample}
                            </p>
                            <p className="text-label text-weak">
                                {c.titleSample}
                            </p>
                            <p className="text-caption text-weak">
                                {c.bodySample}
                            </p>
                        </Card>
                    </Section>

                    <Section title={c.buttons}>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button>{c.primary}</Button>
                            <Button variant="secondary">{c.secondary}</Button>
                            <Button variant="ghost">{c.ghost}</Button>
                            <Button variant="destructive">{c.danger}</Button>
                            <Button loading>
                                <Spinner />
                                {c.loading}
                            </Button>
                            <Button disabled>{c.disabled}</Button>
                            <Button size="sm">{c.small}</Button>
                            <Button
                                size="icon"
                                variant="secondary"
                                aria-label={c.search}
                            >
                                <Icon icon={Search} />
                            </Button>
                        </div>
                    </Section>

                    <Section title={c.badges}>
                        <div className="flex flex-wrap items-center gap-3">
                            <Badge variant="success">{c.success}</Badge>
                            <Badge variant="warning">{c.warning}</Badge>
                            <Badge variant="danger">{c.error}</Badge>
                            <Badge variant="info">{c.info}</Badge>
                            <Badge variant="neutral">{c.neutral}</Badge>
                            <Badge variant="brand">{c.brand}</Badge>
                            <Counter value={5} label="5" />
                            <Counter value={120} />
                        </div>
                    </Section>

                    <Section title={c.alerts}>
                        <div className="grid gap-3 md:grid-cols-2">
                            {(
                                [
                                    'info',
                                    'success',
                                    'warning',
                                    'danger',
                                ] as const
                            ).map((tone) => (
                                <Alert key={tone} variant={tone}>
                                    <Icon icon={Inbox} />
                                    <AlertBody>
                                        <AlertTitle>{c.alertTitle}</AlertTitle>
                                        <AlertDescription>
                                            {c.alertBody}
                                        </AlertDescription>
                                    </AlertBody>
                                </Alert>
                            ))}
                        </div>
                    </Section>

                    <Section title={c.forms}>
                        <Card>
                            <div className="grid gap-4 md:grid-cols-2">
                                <TextField
                                    label={c.name}
                                    hint={c.nameHint}
                                    required
                                />
                                <TextField
                                    label={c.phone}
                                    dir="ltr"
                                    inputMode="tel"
                                    defaultValue="05 1234 5678"
                                    error={c.phoneError}
                                />
                                <SelectField
                                    label={c.city}
                                    defaultValue="riyadh"
                                    options={[
                                        { value: 'riyadh', label: c.riyadh },
                                        { value: 'jeddah', label: c.jeddah },
                                        { value: 'dammam', label: c.dammam },
                                    ]}
                                />
                                <TextareaField label={c.notes} optional />
                                <CheckboxField label={c.agree} defaultChecked />
                                <RadioGroup
                                    legend={c.channel}
                                    name="channel"
                                    value={radio}
                                    onChange={setRadio}
                                    inline
                                    options={[
                                        {
                                            value: 'whatsapp',
                                            label: c.whatsapp,
                                        },
                                        { value: 'phone', label: c.phoneCall },
                                    ]}
                                />
                                <SwitchField
                                    label={c.notify}
                                    hint={c.notifyHint}
                                    checked={switchOn}
                                    onCheckedChange={setSwitchOn}
                                />
                            </div>
                        </Card>
                    </Section>

                    <Section title={c.tabs}>
                        <Tabs defaultValue="overview">
                            <TabsList>
                                <TabsTrigger value="overview">
                                    {c.overview}
                                </TabsTrigger>
                                <TabsTrigger value="activity">
                                    {c.activity} <Counter value={3} />
                                </TabsTrigger>
                                <TabsTrigger value="files">
                                    {c.files}
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent value="overview">
                                {c.bodySample}
                            </TabsContent>
                            <TabsContent value="activity">
                                {c.alertBody}
                            </TabsContent>
                            <TabsContent value="files">
                                {c.emptyDesc}
                            </TabsContent>
                        </Tabs>
                    </Section>

                    <Section title={c.cards}>
                        <div className="grid gap-4 md:grid-cols-3">
                            <StatCard
                                label={c.orders}
                                value="128"
                                icon={Users}
                                delta="+12%"
                                hint={c.activity}
                            />
                            <StatCard
                                label={c.revenue}
                                value="48,250.00"
                                icon={Receipt}
                                delta="-4%"
                                trend="down"
                                positive={false}
                            />
                            <StatCard label={c.orders} value="—" loading />
                        </div>
                        <Card>
                            <CardHeader>
                                <CardTitle>{c.cardTitle}</CardTitle>
                                <CardDescription>{c.cardDesc}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <TextField label={c.name} />
                            </CardContent>
                            <CardFooter>
                                <Button variant="secondary">{c.cancel}</Button>
                                <Button>{c.save}</Button>
                            </CardFooter>
                        </Card>
                    </Section>

                    <Section title={c.overlays}>
                        <div className="flex flex-wrap items-center gap-3">
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button variant="secondary">
                                        {c.openDialog}
                                    </Button>
                                </DialogTrigger>
                                <DialogContent size="sm">
                                    <DialogHeader>
                                        <DialogTitle>
                                            {c.dialogTitle}
                                        </DialogTitle>
                                        <DialogDescription>
                                            {c.dialogDesc}
                                        </DialogDescription>
                                    </DialogHeader>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                {c.cancel}
                                            </Button>
                                        </DialogClose>
                                        <Button variant="destructive">
                                            {c.danger}
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>

                            <Menu
                                trigger={
                                    <Button variant="secondary">
                                        {c.menu}
                                    </Button>
                                }
                                items={[
                                    { label: c.edit, icon: Pencil },
                                    { label: c.export, icon: Download },
                                    {
                                        label: c.remove,
                                        icon: Trash2,
                                        danger: true,
                                        separatorBefore: true,
                                    },
                                ]}
                            />

                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button variant="secondary">
                                        {c.tooltip}
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{c.tooltipText}</TooltipContent>
                            </Tooltip>

                            <Button
                                variant="secondary"
                                onClick={() =>
                                    notify('success', {
                                        title: c.alertTitle,
                                        message: c.alertBody,
                                    })
                                }
                            >
                                {c.toastOk}
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    notify('danger', { title: c.phoneError })
                                }
                            >
                                {c.toastErr}
                            </Button>
                        </div>
                    </Section>

                    <Section title={c.feedback}>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Card>
                                <div className="space-y-2">
                                    <Skeleton className="h-4 w-1/2" />
                                    <Skeleton className="h-4 w-full" />
                                    <Skeleton className="h-4 w-3/4" />
                                </div>
                                <Spinner />
                            </Card>
                            <Card>
                                <EmptyState
                                    compact
                                    icon={Inbox}
                                    title={c.emptyTitle}
                                    description={c.emptyDesc}
                                    action={<Button>{c.save}</Button>}
                                />
                            </Card>
                        </div>
                    </Section>

                    <Section title={c.misc}>
                        <div className="flex flex-wrap items-center gap-6">
                            <Ref>ORD-2026-0142</Ref>
                            <Ref href={dashboard()}>INV-9021</Ref>
                            <Amount value={1250.5} strong />
                            <Amount value={-320} />
                            <Avatar>
                                <AvatarFallback>
                                    {locale === 'ar' ? 'مح' : 'MH'}
                                </AvatarFallback>
                            </Avatar>
                        </div>
                        <Breadcrumbs
                            breadcrumbs={[
                                { title: 'nav.dashboard', href: dashboard() },
                                { title: 'nav.settings', href: dashboard() },
                            ]}
                        />
                    </Section>

                    <Section title={c.logo}>
                        <div className="flex flex-wrap items-center gap-8">
                            <Logo height={32} />
                            <Logo variant="mark" height={32} />
                        </div>
                    </Section>

                    <Section title={c.icons}>
                        <div className="flex items-center gap-4">
                            <Button variant="secondary">
                                <span>{c.menu}</span>
                                <Icon icon={Download} />
                            </Button>
                        </div>
                    </Section>
                </main>
            </div>
        </>
    );
}
