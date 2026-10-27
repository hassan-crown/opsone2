export type Text = { ar: string | null; en: string | null; label: string };

export type CatalogTabs = {
    products: boolean;
    ready: boolean;
    materials: boolean;
};

export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type ImageFile = { uuid: string; name: string; url: string };

export type VersionRow = {
    id: number;
    version: number;
    created_at: string;
    author: string | null;
    payload: Record<string, unknown>;
};

export type OptionGroup = {
    key: string;
    label: { ar: string | null; en: string | null };
    type: 'single' | 'multiple' | 'text' | 'number' | 'attachment';
    required: boolean;
    unit: string | null;
    values: { ar: string | null; en: string | null }[];
    material_ids: number[];
};
