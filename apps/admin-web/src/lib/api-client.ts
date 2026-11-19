/**
 * The browser's way of talking to the API.
 *
 * Cookie authentication, not tokens: the dashboard is a first-party app, so
 * Sanctum's stateful SPA flow applies and no access token ever touches
 * JavaScript (CLAUDE.md section 8). Two things follow, and both are handled
 * here rather than at every call site:
 *
 *   - every request carries credentials, and
 *   - every mutating request carries the CSRF token from the XSRF-TOKEN
 *     cookie, which `GET /sanctum/csrf-cookie` sets.
 *
 * The base URL is relative by default. Behind the gateway the dashboard and
 * the API share an origin (`admin.localhost:8080`), which is the only setup
 * where the session cookie is sent without loosening SameSite. Pointing a
 * browser straight at the Next.js port instead needs
 * NEXT_PUBLIC_ADMIN_API_BASE_URL set to the gateway's origin.
 */

import { adminEnv } from './env';

/** The error envelope every API failure uses. Branch on `code`, never on `message`. */
export interface ApiErrorBody {
  readonly status_code: number;
  readonly code: string;
  readonly message: string;
  readonly details?: readonly { readonly field: string; readonly message: string }[];
  readonly request_id?: string;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly body: ApiErrorBody | null,
  ) {
    super(body?.message ?? `Request failed with status ${status}`);
    this.name = 'ApiError';
  }

  get code(): string {
    return this.body?.code ?? 'INTERNAL_ERROR';
  }

  /** Field messages from a 422, keyed by the field path the API used. */
  fieldErrors(): Record<string, string> {
    const errors: Record<string, string> = {};
    for (const detail of this.body?.details ?? []) {
      errors[detail.field] ??= detail.message;
    }

    return errors;
  }
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

function readCookie(name: string): string | undefined {
  if (typeof document === 'undefined') {
    return undefined;
  }

  const match = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${name}=`))
    ?.slice(name.length + 1);

  return match === undefined ? undefined : decodeURIComponent(match);
}

/**
 * Fetches the CSRF cookie once, and only once per page life.
 *
 * Several dialogs can submit at the same moment, so the in-flight promise is
 * shared: three saves must not each start their own round trip for the same
 * cookie.
 */
let csrfRequest: Promise<void> | undefined;

async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN') !== undefined) {
    return;
  }

  csrfRequest ??= fetch(`${adminEnv.csrfPath}`, { credentials: 'include' })
    .then(() => undefined)
    .finally(() => {
      csrfRequest = undefined;
    });

  await csrfRequest;
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const mutating = MUTATING.has(method);

  if (mutating) {
    await ensureCsrfCookie();
  }

  const headers: Record<string, string> = { Accept: 'application/json' };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const token = readCookie('XSRF-TOKEN');
  if (mutating && token !== undefined) {
    headers['X-XSRF-TOKEN'] = token;
  }

  const response = await fetch(`${adminEnv.apiBaseUrl}${path}`, {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  const parsed: unknown = text === '' ? null : JSON.parse(text);

  if (!response.ok) {
    throw new ApiError(response.status, parsed as ApiErrorBody | null);
  }

  return parsed as T;
}

export const api = {
  get: <T>(path: string): Promise<T> => request<T>('GET', path),
  post: <T>(path: string, body?: unknown): Promise<T> => request<T>('POST', path, body),
  put: <T>(path: string, body?: unknown): Promise<T> => request<T>('PUT', path, body),
};
