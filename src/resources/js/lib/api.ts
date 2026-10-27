export type ApiResult<T> = {
    ok: boolean;
    status: number;
    data: T | null;
    /** Field errors of a 422, keyed by dotted field name. */
    errors: Record<string, string[]>;
    /** 409 `stale_version`: someone else changed the record since the form was loaded. */
    stale: boolean;
    /** 403 and other errors carry a server message when there is one. */
    message: string | null;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON call to a Laravel endpoint under the web session (CSRF via the XSRF cookie). Used where the endpoint's
 * contract is JSON (orders: docs/p4-orders-api.md) rather than an Inertia redirect.
 */
export async function api<T = unknown>(
    method: 'GET' | 'POST' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<ApiResult<T>> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        ...(method === 'GET' || body === undefined
            ? {}
            : { body: JSON.stringify(body) }),
    }).catch(() => null);

    if (response === null) {
        return {
            ok: false,
            status: 0,
            data: null,
            errors: {},
            stale: false,
            message: null,
        };
    }

    const data = (await response.json().catch(() => null)) as
        | (T & {
              code?: string;
              message?: string;
              errors?: Record<string, string[]>;
          })
        | null;

    return {
        ok: response.ok,
        status: response.status,
        data: response.ok ? data : null,
        errors: response.status === 422 ? (data?.errors ?? {}) : {},
        stale: response.status === 409 && data?.code === 'stale_version',
        message: response.ok ? null : (data?.message ?? null),
    };
}
