/* api.js - the one place that talks to the PHP API. */

const API_BASE = '../api';

let csrfToken = null;

async function getCsrfToken() {
    if (csrfToken === null) {
        const data = await apiRequest('auth/csrf');
        csrfToken = data.csrf_token;
    }
    return csrfToken;
}

/* Sends a request to the API; throws { status, message, errors } on failure. */
async function apiRequest(path, { method = 'GET', body = null } = {}) {
    const headers = { Accept: 'application/json' };
    const options = { method, headers, credentials: 'same-origin' };

    if (method !== 'GET') {
        headers['X-CSRF-Token'] = await getCsrfToken();
        if (body instanceof FormData) {
            // For files the browser must set Content-Type itself.
            options.body = body;
        } else {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body ?? {});
        }
    }

    let response;
    try {
        response = await fetch(`${API_BASE}/${path}`, options);
    } catch (networkError) {
        throw { status: 0, message: 'Cannot reach the server. Check your internet connection.', errors: {} };
    }

    const result = await response.json().catch(() => null);

    if (!response.ok || !result || !result.success) {
        // The token may have expired, so fetch a new one next time.
        if (response.status === 403) {
            csrfToken = null;
        }
        throw {
            status: response.status,
            message: (result && result.message) || 'Unexpected server error.',
            errors: (result && result.errors) || {},
        };
    }

    return result.data;
}

/* Clears or replaces the saved token after login or logout. */
function resetCsrfToken(newToken = null) {
    csrfToken = newToken;
}
