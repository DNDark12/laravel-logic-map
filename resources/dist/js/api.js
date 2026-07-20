export class LogicMapApiError extends Error {
    constructor(message, { status = 0, errors = null, meta = {} } = {}) {
        super(message);
        this.name = 'LogicMapApiError';
        this.status = status;
        this.errors = errors;
        this.meta = meta;
    }
}

export function encodeCanonicalId(id) {
    const bytes = new TextEncoder().encode(String(id));
    let binary = '';

    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

export function createApi(baseUrl) {
    const base = String(baseUrl).replace(/\/$/, '');

    async function request(path, { method = 'GET', body, signal } = {}) {
        const headers = { Accept: 'application/json' };
        const options = { method, headers, signal };

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            if (csrf) {
                headers['X-CSRF-TOKEN'] = csrf;
            }

            options.body = JSON.stringify(body);
        }

        const response = await fetch(`${base}${path}`, options);
        let payload;

        try {
            payload = await response.json();
        } catch {
            throw new LogicMapApiError('Logic Map returned an invalid JSON response.', { status: response.status });
        }

        if (!response.ok || payload?.ok !== true) {
            throw new LogicMapApiError(
                payload?.message || `Logic Map request failed with status ${response.status}.`,
                { status: response.status, errors: payload?.errors ?? null, meta: payload?.meta ?? {} },
            );
        }

        return payload;
    }

    return Object.freeze({
        status: ({ signal } = {}) => request('/status', { signal }),
        search: (query, { signal } = {}) => request(`/symbols/search?q=${encodeURIComponent(query)}`, { signal }),
        context: (id, { signal } = {}) => request(`/symbols/${encodeCanonicalId(id)}/context`, { signal }),
        workflow: (id, { signal } = {}) => request(`/workflows/${encodeCanonicalId(id)}`, { signal }),
        workflowExport: (id, format, { signal } = {}) => request(
            `/workflows/${encodeCanonicalId(id)}?format=${encodeURIComponent(format)}`,
            { signal },
        ),
        impact: (selection, { signal } = {}) => request('/impact', { method: 'POST', body: selection, signal }),
        impactExport: (selection, format, { signal } = {}) => request(
            '/impact',
            { method: 'POST', body: { ...selection, format }, signal },
        ),
        modules: ({ signal } = {}) => request('/modules', { signal }),
        module: (id, { signal } = {}) => request(`/modules/${encodeCanonicalId(id)}`, { signal }),
    });
}
