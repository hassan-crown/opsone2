import type {
    ActionDef,
    ActionTypeKey,
    CatalogueEntry,
    Definition,
    Localized,
    StageDef,
    Target,
} from './types';

export const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

export const blankName = (): Localized => ({ ar: '', en: '' });

/** Localized text of the current language, falling back to the other one. */
export function pick(
    name: { ar: string; en: string | null } | null | undefined,
    locale: string,
): string {
    if (!name) {
        return '';
    }
    const first = locale === 'ar' ? name.ar : name.en;
    const second = locale === 'ar' ? name.en : name.ar;

    return (first && first.trim() !== '' ? first : second) ?? '';
}

/** A short identifier (`^[a-z0-9_]{1,40}$`) not yet used by any stage or action of the definition. */
export function freshKey(def: Definition, base: string): string {
    const used = new Set<string>();
    for (const stage of def.stages) {
        used.add(stage.key);
        stage.actions.forEach((a) => used.add(a.key));
    }
    const clean =
        base
            .toLowerCase()
            .replace(/[^a-z0-9_]/g, '_')
            .slice(0, 34) || 'item';
    let n = 1;
    let key = clean;
    while (used.has(key)) {
        n += 1;
        key = `${clean}_${n}`;
    }

    return key;
}

export function newStage(def: Definition): StageDef {
    return {
        key: freshKey(def, 'stage'),
        name: blankName(),
        public_name: null,
        show_to_customer: true,
        follow_up: { type: 'order_assignee' },
        target_days: null,
        actions: [],
    };
}

export function newAction(def: Definition, entry: CatalogueEntry): ActionDef {
    return {
        key: freshKey(def, entry.type),
        type: entry.type,
        name: { ar: entry.label.ar, en: entry.label.en },
        requirement: 'required',
        applies_when: 'always',
        assign: { type: 'order_assignee' },
        inputs: clone(entry.defaults),
        after: null,
    };
}

export function move<T>(list: T[], index: number, by: -1 | 1): T[] {
    const to = index + by;
    if (to < 0 || to >= list.length) {
        return list;
    }
    const next = [...list];
    [next[index], next[to]] = [next[to], next[index]];

    return next;
}

export const targetValue = (target: Target): string =>
    target.type === 'order_assignee'
        ? 'order_assignee'
        : `${target.type}:${target.id ?? ''}`;

export function parseTarget(value: string): Target {
    if (value === 'order_assignee' || value === '') {
        return { type: 'order_assignee' };
    }
    const [type, id] = value.split(':');

    return { type: type as Target['type'], id: Number(id) };
}

export const MEASUREMENT_ACTIONS: ActionTypeKey[] = ['record_measurements'];

/** Where an issue's `path` points, for the editor: stage index, action index and the field. */
export function locate(path: string): {
    stage: number | null;
    action: number | null;
    field: string | null;
} {
    const m = path.match(
        /^(?:upcoming\.)?stages\.(\d+)(?:\.actions\.(\d+))?(?:\.(.+))?$/,
    );
    if (!m) {
        return { stage: null, action: null, field: null };
    }

    return {
        stage: Number(m[1]),
        action: m[2] === undefined ? null : Number(m[2]),
        field: m[3] ?? null,
    };
}
