export type SiteTabs = { pages: boolean; theme: boolean; domain: boolean };

export type PageState =
    | 'draft'
    | 'published'
    | 'published_with_draft'
    | 'archived'
    | 'system';

export type SectionType =
    | 'hero'
    | 'text'
    | 'services'
    | 'gallery'
    | 'testimonials'
    | 'faq'
    | 'contact'
    | 'ready_goods';

export type Section = {
    id: string;
    type: SectionType;
    visible: boolean;
    data: Record<string, unknown>;
};

export type LocaleContent = {
    title: string;
    meta_description: string;
    sections: Section[];
    has_draft: boolean;
    published: boolean;
};

export type Issue = {
    section: number | null;
    code: string;
    field: string | null;
};

export type Readiness = Record<
    'ar' | 'en',
    { has_draft: boolean; complete: boolean; issues: Issue[] }
>;

export type PageRow = {
    id: number;
    slug: string;
    policy: 'content' | 'editable' | 'system';
    system_key: string | null;
    state: PageState;
    title: { ar: string; en: string };
    locales: Record<'ar' | 'en', { published: boolean; draft: boolean }>;
    can_edit: boolean;
    can_archive: boolean;
    can_restore: boolean;
};

export type SiteImageFile = { uuid: string; name: string; url: string };

export type ContrastRow = {
    mode: 'light' | 'dark' | 'contrast';
    pair: string;
    ratio: number;
    min: number;
    pass: boolean;
};

export type ThemePreview = {
    status: 'ok' | 'adjusted' | 'rejected';
    input: string;
    brand: string;
    message: string | null;
    palette: Record<
        'light' | 'dark' | 'contrast',
        Record<string, string>
    > | null;
    report: ContrastRow[];
};
