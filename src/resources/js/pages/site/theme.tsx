import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ImagePlus,
    RotateCcw,
    Save,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { SiteNav } from '@/components/site/shared';
import { SelectField, TextField } from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { PageHeader } from '@/components/opsone/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { SiteTabs, ThemePreview } from '@/types/site';

type Props = {
    state: 'default' | 'custom' | 'draft';
    settings: {
        brand_color: string | null;
        font: string;
        appearance: string;
        logo: string | null;
        logo_dark: string | null;
        favicon: string | null;
    };
    published: { brand_color: string | null };
    preview: ThemePreview;
    presets: Record<string, string>;
    defaultColor: string;
    fonts: string[];
    can: { edit: boolean; apply: boolean };
    tabs: SiteTabs;
};

const ASSETS = ['logo', 'logo_dark', 'favicon'] as const;

/** Variables of one mode, as an inline style for the sample card. */
const vars = (palette: Record<string, string> | undefined) =>
    Object.fromEntries(
        Object.entries(palette ?? {}).map(([k, v]) => [`--${k}`, v]),
    ) as React.CSSProperties;

export default function ThemeEdit({
    state,
    settings,
    preview,
    presets,
    defaultColor,
    fonts,
    can,
    tabs,
}: Props) {
    const t = useT();
    const errors = usePage().props.errors as Record<string, string>;
    const form = useForm({
        brand_color: settings.brand_color ?? '',
        font: settings.font,
        appearance: settings.appearance,
    });
    const [live, setLive] = useState<ThemePreview>(preview);
    const files = useRef<Record<string, HTMLInputElement | null>>({});
    const color = form.data.brand_color || defaultColor;

    useEffect(() => {
        if (!/^#[0-9a-fA-F]{6}$/.test(color)) return;
        const handle = setTimeout(async () => {
            const res = await api<ThemePreview>(
                'POST',
                '/site-admin/theme/preview',
                { brand_color: color },
            );
            if (res.ok && res.data) setLive(res.data);
        }, 250);

        return () => clearTimeout(handle);
    }, [color]);

    const failing = live.report.filter((r) => !r.pass);
    const rejected = live.status === 'rejected';
    const asset = (k: (typeof ASSETS)[number]) => settings[k];

    return (
        <>
            <Head title={t('theme.title')} />
            <PageHeader
                title={t('theme.title')}
                description={t('theme.description')}
                actions={
                    <>
                        <Badge
                            variant={
                                state === 'draft'
                                    ? 'warning'
                                    : state === 'custom'
                                      ? 'brand'
                                      : 'neutral'
                            }
                        >
                            {t(`theme.state.${state}` as TranslationKey)}
                        </Badge>
                        {can.edit && (
                            <Button
                                onClick={() =>
                                    form.put('/site-admin/theme', {
                                        preserveScroll: true,
                                    })
                                }
                                disabled={form.processing || rejected}
                                data-test="theme-save"
                            >
                                <Icon icon={Save} />
                                {t('theme.save_draft')}
                            </Button>
                        )}
                        {can.apply && state === 'draft' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/site-admin/theme/apply',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                data-test="theme-apply"
                            >
                                <Icon icon={Check} />
                                {t('theme.apply')}
                            </Button>
                        )}
                    </>
                }
            />
            <SiteNav active="theme" tabs={tabs} />

            {Object.values(errors).length > 0 && (
                <Alert variant="destructive" className="mb-4" role="alert">
                    <AlertTitle>{t('theme.errors')}</AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc ps-5">
                            {Object.values(errors).map((e, i) => (
                                <li key={i}>{e}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <div className="grid content-start gap-6">
                    <section
                        className="grid gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="brand-h"
                    >
                        <h2 id="brand-h" className="text-subtitle">
                            {t('theme.brand')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('theme.brand_hint')}
                        </p>
                        <div
                            className="flex flex-wrap gap-2"
                            role="group"
                            aria-label={t('theme.presets')}
                        >
                            {Object.entries(presets).map(([name, hex]) => (
                                <button
                                    key={name}
                                    type="button"
                                    disabled={!can.edit}
                                    onClick={() =>
                                        form.setData('brand_color', hex)
                                    }
                                    aria-label={t(
                                        `theme.preset.${name}` as TranslationKey,
                                    )}
                                    aria-pressed={
                                        color.toLowerCase() ===
                                        hex.toLowerCase()
                                    }
                                    className="size-9 rounded-full border-2 border-stroke aria-pressed:ring-2 aria-pressed:ring-focus aria-pressed:ring-offset-2"
                                    style={{ background: hex }}
                                />
                            ))}
                        </div>
                        <div className="grid items-end gap-3 sm:grid-cols-[auto_1fr]">
                            <input
                                type="color"
                                value={
                                    /^#[0-9a-fA-F]{6}$/.test(color)
                                        ? color
                                        : defaultColor
                                }
                                disabled={!can.edit}
                                onChange={(e) =>
                                    form.setData(
                                        'brand_color',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                aria-label={t('theme.picker')}
                                className="h-11 w-16 cursor-pointer rounded-md border border-stroke-control bg-field"
                            />
                            <TextField
                                label={t('theme.hex')}
                                dir="ltr"
                                value={form.data.brand_color}
                                placeholder={defaultColor}
                                disabled={!can.edit}
                                onChange={(e) =>
                                    form.setData('brand_color', e.target.value)
                                }
                                error={form.errors.brand_color}
                            />
                        </div>
                        {live.message && (
                            <Alert
                                variant={rejected ? 'destructive' : 'default'}
                                role={rejected ? 'alert' : 'status'}
                            >
                                <Icon icon={TriangleAlert} />
                                <AlertDescription>
                                    {live.message}
                                    {live.status === 'adjusted' && (
                                        <>
                                            {' '}
                                            <span dir="ltr">
                                                {live.input} → {live.brand}
                                            </span>
                                        </>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                        {form.data.brand_color !== '' && can.edit && (
                            <div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        form.setData('brand_color', '')
                                    }
                                >
                                    {t('theme.use_default')}
                                </Button>
                            </div>
                        )}
                    </section>

                    <section
                        className="grid gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="look-h"
                    >
                        <h2 id="look-h" className="text-subtitle">
                            {t('theme.look')}
                        </h2>
                        <SelectField
                            label={t('theme.font')}
                            hint={t('theme.font_hint')}
                            value={form.data.font}
                            disabled={!can.edit}
                            onChange={(e) =>
                                form.setData('font', e.target.value)
                            }
                            options={fonts.map((f) => ({
                                value: f,
                                label: t(`theme.font_${f}` as TranslationKey),
                            }))}
                        />
                        <SelectField
                            label={t('theme.appearance')}
                            hint={t('theme.appearance_hint')}
                            value={form.data.appearance}
                            disabled={!can.edit}
                            onChange={(e) =>
                                form.setData('appearance', e.target.value)
                            }
                            options={['system', 'light', 'dark'].map((a) => ({
                                value: a,
                                label: t(
                                    `theme.appearance_${a}` as TranslationKey,
                                ),
                            }))}
                        />
                    </section>

                    <section
                        className="grid gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="files-h"
                    >
                        <h2 id="files-h" className="text-subtitle">
                            {t('theme.files')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('theme.files_hint')}
                        </p>
                        {ASSETS.map((k) => (
                            <div
                                key={k}
                                className="flex flex-wrap items-center gap-3"
                            >
                                <div className="grid size-16 place-items-center overflow-hidden rounded-md border border-stroke-weak bg-fill">
                                    {asset(k) ? (
                                        <img
                                            src={asset(k) as string}
                                            alt=""
                                            className="max-h-full max-w-full object-contain"
                                        />
                                    ) : (
                                        <Icon icon={ImagePlus} />
                                    )}
                                </div>
                                <div className="grid flex-1 gap-1">
                                    <span className="text-label font-medium">
                                        {t(
                                            `theme.asset_${k}` as TranslationKey,
                                        )}
                                    </span>
                                    <span className="text-caption text-weak">
                                        {t(
                                            `theme.asset_${k}_hint` as TranslationKey,
                                        )}
                                    </span>
                                </div>
                                {can.edit && (
                                    <div className="flex gap-2">
                                        <input
                                            ref={(el) => {
                                                files.current[k] = el;
                                            }}
                                            type="file"
                                            accept="image/png,image/webp"
                                            className="sr-only"
                                            aria-label={t(
                                                `theme.asset_${k}` as TranslationKey,
                                            )}
                                            data-test={`asset-${k}`}
                                            onChange={(e) => {
                                                const f = e.target.files?.[0];
                                                if (f)
                                                    router.post(
                                                        `/site-admin/theme/assets/${k}`,
                                                        { file: f },
                                                        {
                                                            forceFormData: true,
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                e.target.value = '';
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                files.current[k]?.click()
                                            }
                                        >
                                            {t('theme.upload')}
                                        </Button>
                                        {asset(k) && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t('theme.remove')}
                                                onClick={() =>
                                                    router.delete(
                                                        `/site-admin/theme/assets/${k}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Icon icon={Trash2} />
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>

                    <div className="flex flex-wrap gap-2">
                        {can.edit && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/site-admin/theme/restore',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Icon icon={RotateCcw} />
                                {t('theme.restore')}
                            </Button>
                        )}
                        {can.edit && state === 'draft' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/site-admin/theme/discard',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {t('theme.discard')}
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid content-start gap-6">
                    <section
                        className="grid gap-3 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="prev-h"
                    >
                        <h2 id="prev-h" className="text-subtitle">
                            {t('theme.preview')}
                        </h2>
                        <p className="text-caption text-weak">
                            {t('theme.preview_hint')}
                        </p>
                        {live.palette && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                {(['light', 'dark'] as const).map((mode) => (
                                    <div
                                        key={mode}
                                        className="grid gap-2 rounded-lg border p-4"
                                        style={{
                                            ...vars(live.palette?.[mode]),
                                            background:
                                                mode === 'light'
                                                    ? '#FFFFFF'
                                                    : '#0F1D31',
                                            color:
                                                mode === 'light'
                                                    ? '#0B1B33'
                                                    : '#F5F7F9',
                                            borderColor:
                                                mode === 'light'
                                                    ? '#DDE3EA'
                                                    : '#2A3B55',
                                        }}
                                    >
                                        <p className="text-label font-semibold">
                                            {t(
                                                `theme.mode_${mode}` as TranslationKey,
                                            )}
                                        </p>
                                        <span
                                            className="w-fit rounded-md px-4 py-2 text-control font-medium"
                                            style={{
                                                background: 'var(--brand)',
                                                color: 'var(--on-brand)',
                                            }}
                                        >
                                            {t('theme.sample_button')}
                                        </span>
                                        <a
                                            href="#preview"
                                            onClick={(e) => e.preventDefault()}
                                            className="w-fit underline"
                                            style={{ color: 'var(--brand)' }}
                                        >
                                            {t('theme.sample_link')}
                                        </a>
                                        <span
                                            className="w-fit rounded-sm px-2 py-1 text-label"
                                            style={{
                                                background:
                                                    'var(--brand-subtle)',
                                                color: 'var(--brand-subtle-text)',
                                            }}
                                        >
                                            {t('theme.sample_badge')}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                        <p className="text-caption text-weak">
                            {t('theme.impact')}
                        </p>
                    </section>

                    <section
                        className="grid gap-2 rounded-lg border border-stroke-weak bg-surface p-4"
                        aria-labelledby="contrast-h"
                    >
                        <h2 id="contrast-h" className="text-subtitle">
                            {t('theme.contrast')}
                        </h2>
                        <p
                            className={
                                failing.length === 0
                                    ? 'text-success-text'
                                    : 'text-danger-text'
                            }
                        >
                            {failing.length === 0
                                ? t('theme.contrast_ok')
                                : t('theme.contrast_fail', {
                                      count: failing.length,
                                  })}
                        </p>
                        <div className="overflow-x-auto">
                            <table className="w-full text-caption">
                                <thead>
                                    <tr className="text-start text-weak">
                                        <th
                                            scope="col"
                                            className="py-1 text-start"
                                        >
                                            {t('theme.col_mode')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-1 text-start"
                                        >
                                            {t('theme.col_pair')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="py-1 text-start"
                                        >
                                            {t('theme.col_ratio')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {live.report.map((r, i) => (
                                        <tr
                                            key={i}
                                            className={
                                                r.pass ? '' : 'text-danger-text'
                                            }
                                        >
                                            <td className="py-1">
                                                {t(
                                                    `theme.mode_${r.mode}` as TranslationKey,
                                                )}
                                            </td>
                                            <td className="py-1" dir="ltr">
                                                {r.pair}
                                            </td>
                                            <td className="py-1" dir="ltr">
                                                {r.ratio} / {r.min}{' '}
                                                {r.pass ? '✓' : '✗'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
