/**
 * Installation photos on a weak connection (ADR-0036): each photo is compressed on the device, written to an
 * IndexedDB queue (`opsone-field` / `uploads`, shared with public/field-sw.js) and sent from there. A failed send
 * stays queued and is retried when the connection returns, when the app opens, and by Background Sync where the
 * browser has it. The `id` doubles as the server's `client_uuid`, so a retry after a lost response never adds a copy.
 */

export type QueuedUpload = {
    id: string;
    url: string;
    appointmentId: number;
    phase: 'before' | 'after';
    blob: Blob;
    name: string;
    xsrf: string;
    created: number;
    /** HTTP status of a refusal that retrying will not fix (shown until discarded). */
    failed?: number;
};

const DB = 'opsone-field';
const STORE = 'uploads';
const MAX_EDGE = 1600;
const QUALITY = 0.75;

function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB, 1);
        open.onupgradeneeded = () =>
            open.result.createObjectStore(STORE, { keyPath: 'id' });
        open.onsuccess = () => resolve(open.result);
        open.onerror = () => reject(open.error);
    });
}

async function run<T>(
    mode: IDBTransactionMode,
    work: (store: IDBObjectStore) => IDBRequest<T> | void,
): Promise<T | undefined> {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const request = work(tx.objectStore(STORE));
        tx.oncomplete = () => resolve(request ? request.result : undefined);
        tx.onerror = () => reject(tx.error);
    });
}

export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Longest edge 1600 px, JPEG ~75 %: a phone photo of 3–6 MB becomes ~200–400 KB. */
export async function compressImage(file: File): Promise<Blob> {
    const bitmap = await createImageBitmap(file).catch(() => null);
    if (!bitmap) {
        return file;
    }
    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas
        .getContext('2d')
        ?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', QUALITY),
    );

    return blob && blob.size < file.size ? blob : file;
}

export async function enqueue(upload: QueuedUpload): Promise<void> {
    await run('readwrite', (store) => store.put(upload));
    const registration = await navigator.serviceWorker?.ready.catch(
        () => undefined,
    );
    // Background Sync (Chromium/Android): the browser retries even after the app is closed.
    const sync = (
        registration as
            | (ServiceWorkerRegistration & {
                  sync?: { register: (tag: string) => Promise<void> };
              })
            | undefined
    )?.sync;
    await sync?.register('field-uploads').catch(() => undefined);
}

export async function queued(): Promise<QueuedUpload[]> {
    return ((await run('readonly', (store) => store.getAll())) ??
        []) as QueuedUpload[];
}

export async function discard(id: string): Promise<void> {
    await run('readwrite', (store) => store.delete(id));
}

let flushing: Promise<number> | null = null;

/**
 * Sends what is queued, oldest first; stops at the first network failure. Returns how many were stored.
 */
export function flush(): Promise<number> {
    flushing ??= (async () => {
        let sent = 0;
        const items = (await queued())
            .filter((item) => !item.failed)
            .sort((a, b) => a.created - b.created);
        for (const item of items) {
            const form = new FormData();
            form.append('phase', item.phase);
            form.append('client_uuid', item.id);
            form.append('file', item.blob, item.name);
            let response: Response;
            try {
                response = await fetch(item.url, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrfToken(),
                    },
                });
            } catch {
                break;
            }
            if (response.ok) {
                await discard(item.id);
                sent++;
            } else if ([403, 404, 409, 422].includes(response.status)) {
                await run('readwrite', (store) =>
                    store.put({ ...item, failed: response.status }),
                );
            }
        }

        return sent;
    })().finally(() => {
        flushing = null;
    });

    return flushing;
}
