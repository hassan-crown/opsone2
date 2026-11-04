import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vite-plus/test';

const ROOT = join(import.meta.dirname, '..');
const GENERATED = new Set(['actions', 'routes', 'wayfinder']);

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return GENERATED.has(name) && dir === ROOT ? [] : sources(path);
        }

        return name.endsWith('.tsx') ? [path] : [];
    });
}

/**
 * `Icon` already mirrors directional icons in RTL (`rtl:-scale-x-100`). Adding `rtl:rotate-180`
 * flips them a second time, so "next" and "back" point the wrong way in Arabic.
 */
describe('RTL icon mirroring', () => {
    it('never rotates an <Icon> again in RTL', () => {
        const offenders = sources(ROOT).flatMap((file) => {
            const text = readFileSync(file, 'utf8');

            if (!text.includes('rtl:rotate-180')) {
                return [];
            }

            return [...text.matchAll(/<Icon\b[^>]*?rtl:rotate-180/gs)].map(
                (match) =>
                    `${relative(ROOT, file)}:${text.slice(0, match.index).split('\n').length}`,
            );
        });

        expect(offenders).toEqual([]);
    }, 30_000);
});
