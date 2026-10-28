import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import {
    CheckboxField,
    SelectField,
    TextareaField,
    TextField,
} from '@/components/opsone/field';
import { Icon } from '@/components/opsone/icon';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type { Section, SectionType, SiteImageFile } from '@/types/site';

type Data = Record<string, unknown>;
type Item = Record<string, string>;

const str = (v: unknown): string => (typeof v === 'string' ? v : '');
const items = (v: unknown): Item[] => (Array.isArray(v) ? (v as Item[]) : []);

/** Empty data of a new section; mirrors App\Site\Sections\SectionCatalogue::empty(). */
export function emptyData(type: SectionType): Data {
    switch (type) {
        case 'hero':
            return {
                heading: '',
                subheading: '',
                cta_label: '',
                cta_link: null,
                image: null,
            };
        case 'text':
            return { heading: '', body: '' };
        case 'services':
            return { heading: '', items: [] };
        case 'gallery':
            return { heading: '', images: [] };
        case 'testimonials':
            return { heading: '', items: [] };
        case 'faq':
            return { heading: '', items: [] };
        case 'contact':
            return { heading: '', text: '', show_form: true };
        case 'ready_goods':
            return { heading: '', text: '' };
    }
}

const ITEM_FIELDS: Partial<
    Record<
        SectionType,
        {
            key: string;
            max: number;
            fields: { key: string; multiline?: boolean }[];
        }
    >
> = {
    services: {
        key: 'items',
        max: 8,
        fields: [{ key: 'title' }, { key: 'text', multiline: true }],
    },
    testimonials: {
        key: 'items',
        max: 6,
        fields: [
            { key: 'quote', multiline: true },
            { key: 'author' },
            { key: 'role' },
        ],
    },
    faq: {
        key: 'items',
        max: 12,
        fields: [{ key: 'question' }, { key: 'answer', multiline: true }],
    },
};

export function SectionEditor({
    section,
    index,
    count,
    lang,
    images,
    links,
    disabled,
    onChange,
    onMove,
    onRemove,
}: {
    section: Section;
    index: number;
    count: number;
    lang: 'ar' | 'en';
    images: SiteImageFile[];
    links: string[];
    disabled: boolean;
    onChange: (next: Section) => void;
    onMove: (delta: -1 | 1) => void;
    onRemove: () => void;
}) {
    const t = useT();
    const d = section.data;
    const set = (patch: Data) =>
        onChange({ ...section, data: { ...d, ...patch } });
    const dir = lang === 'ar' ? 'rtl' : 'ltr';
    const text = (key: string, multiline = false, required = false) => {
        const Component = multiline ? TextareaField : TextField;

        return (
            <Component
                key={key}
                label={t(`site.field.${key}` as TranslationKey)}
                value={str(d[key])}
                onChange={(e) => set({ [key]: e.target.value })}
                lang={lang}
                dir={dir}
                disabled={disabled}
                required={required}
            />
        );
    };
    const imageOptions = [
        { value: '', label: t('site.field.no_image') },
        ...images.map((i) => ({ value: i.uuid, label: i.name })),
    ];
    const list = ITEM_FIELDS[section.type];
    const rows = list ? items(d[list.key]) : [];

    return (
        <section
            aria-label={t(`site.section.${section.type}` as TranslationKey)}
            className="grid gap-4 rounded-lg border border-stroke-weak bg-surface p-4"
            data-test={`section-${section.type}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-subtitle text-strong">
                    {index + 1}.{' '}
                    {t(`site.section.${section.type}` as TranslationKey)}
                </h3>
                <div className="flex flex-wrap items-center gap-1">
                    <CheckboxField
                        label={t('site.field.visible')}
                        checked={section.visible}
                        disabled={disabled}
                        onChange={(e) =>
                            onChange({ ...section, visible: e.target.checked })
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={disabled || index === 0}
                        onClick={() => onMove(-1)}
                        aria-label={t('site.editor.move_up')}
                    >
                        <Icon icon={ArrowUp} />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={disabled || index === count - 1}
                        onClick={() => onMove(1)}
                        aria-label={t('site.editor.move_down')}
                    >
                        <Icon icon={ArrowDown} />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        disabled={disabled}
                        onClick={onRemove}
                        aria-label={t('site.editor.remove_section')}
                    >
                        <Icon icon={Trash2} />
                    </Button>
                </div>
            </div>

            {section.type === 'hero' && (
                <>
                    {text('heading')}
                    {text('subheading', true)}
                    <div className="grid gap-4 md:grid-cols-2">
                        {text('cta_label')}
                        <SelectField
                            label={t('site.field.cta_link')}
                            value={str(d.cta_link)}
                            disabled={disabled}
                            onChange={(e) =>
                                set({
                                    cta_link:
                                        e.target.value === ''
                                            ? null
                                            : e.target.value,
                                })
                            }
                            options={[
                                { value: '', label: t('site.field.no_link') },
                                {
                                    value: '#contact',
                                    label: t('site.field.link_contact'),
                                },
                                ...links.map((l) => ({
                                    value: l,
                                    label:
                                        l === 'home'
                                            ? t('site.field.link_home')
                                            : `/${l}`,
                                })),
                            ]}
                        />
                    </div>
                    <SelectField
                        label={t('site.field.image')}
                        value={str(d.image)}
                        disabled={disabled}
                        onChange={(e) =>
                            set({
                                image:
                                    e.target.value === ''
                                        ? null
                                        : e.target.value,
                            })
                        }
                        options={imageOptions}
                    />
                </>
            )}

            {section.type === 'text' && (
                <>
                    {text('heading')}
                    {text('body', true, true)}
                </>
            )}

            {(section.type === 'contact' || section.type === 'ready_goods') && (
                <>
                    {text('heading', false, true)}
                    {text('text', true)}
                    {section.type === 'contact' && (
                        <CheckboxField
                            label={t('site.field.show_form')}
                            checked={Boolean(d.show_form)}
                            disabled={disabled}
                            onChange={(e) =>
                                set({ show_form: e.target.checked })
                            }
                        />
                    )}
                    {section.type === 'ready_goods' && (
                        <p className="text-caption text-weak">
                            {t('site.field.ready_goods_hint')}
                        </p>
                    )}
                </>
            )}

            {list && (
                <>
                    {text('heading', false, section.type === 'services')}
                    {rows.map((row, i) => (
                        <fieldset
                            key={i}
                            className="grid gap-3 rounded-md border border-stroke-weak p-3"
                        >
                            <legend className="px-1 text-label text-weak">
                                {i + 1}
                            </legend>
                            {list.fields.map((f) => {
                                const Component = f.multiline
                                    ? TextareaField
                                    : TextField;

                                return (
                                    <Component
                                        key={f.key}
                                        label={t(
                                            `site.field.${f.key}` as TranslationKey,
                                        )}
                                        value={row[f.key] ?? ''}
                                        lang={lang}
                                        dir={dir}
                                        disabled={disabled}
                                        onChange={(e) =>
                                            set({
                                                [list.key]: rows.map((r, j) =>
                                                    j === i
                                                        ? {
                                                              ...r,
                                                              [f.key]:
                                                                  e.target
                                                                      .value,
                                                          }
                                                        : r,
                                                ),
                                            })
                                        }
                                    />
                                );
                            })}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={disabled}
                                    onClick={() =>
                                        set({
                                            [list.key]: rows.filter(
                                                (_, j) => j !== i,
                                            ),
                                        })
                                    }
                                >
                                    <Icon icon={Trash2} size="sm" />
                                    {t('site.editor.remove_item')}
                                </Button>
                            </div>
                        </fieldset>
                    ))}
                    {rows.length < list.max && (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={disabled}
                                onClick={() =>
                                    set({
                                        [list.key]: [
                                            ...rows,
                                            Object.fromEntries(
                                                list.fields.map((f) => [
                                                    f.key,
                                                    '',
                                                ]),
                                            ),
                                        ],
                                    })
                                }
                            >
                                <Icon icon={Plus} size="sm" />
                                {t('site.editor.add_item')}
                            </Button>
                        </div>
                    )}
                </>
            )}

            {section.type === 'gallery' && (
                <>
                    {text('heading')}
                    {items(d.images).map((img, i) => (
                        <div
                            key={i}
                            className="grid items-end gap-3 md:grid-cols-[1fr_1fr_auto]"
                        >
                            <SelectField
                                label={t('site.field.image')}
                                value={img.image ?? ''}
                                disabled={disabled}
                                options={imageOptions.slice(1)}
                                onChange={(e) =>
                                    set({
                                        images: items(d.images).map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      image: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    })
                                }
                            />
                            <TextField
                                label={t('site.field.caption')}
                                value={img.caption ?? ''}
                                lang={lang}
                                dir={dir}
                                disabled={disabled}
                                onChange={(e) =>
                                    set({
                                        images: items(d.images).map((r, j) =>
                                            j === i
                                                ? {
                                                      ...r,
                                                      caption: e.target.value,
                                                  }
                                                : r,
                                        ),
                                    })
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={disabled}
                                onClick={() =>
                                    set({
                                        images: items(d.images).filter(
                                            (_, j) => j !== i,
                                        ),
                                    })
                                }
                                aria-label={t('site.editor.remove_item')}
                            >
                                <Icon icon={Trash2} />
                            </Button>
                        </div>
                    ))}
                    {items(d.images).length < 12 && images.length > 0 && (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={disabled}
                                onClick={() =>
                                    set({
                                        images: [
                                            ...items(d.images),
                                            {
                                                image: images[0].uuid,
                                                caption: '',
                                            },
                                        ],
                                    })
                                }
                            >
                                <Icon icon={Plus} size="sm" />
                                {t('site.editor.add_image')}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
