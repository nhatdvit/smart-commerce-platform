/**
 * Browser-visible configuration for the operations dashboard.
 * The admin app is staff-only; it must never ship customer PII to the client
 * beyond what the signed-in operator is authorized to see.
 */
export const publicEnv = {
  apiBaseUrl: process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:3000/api/v1',
  siteName: (process.env.NEXT_PUBLIC_SITE_NAME ?? 'Smart Food Ordering') + ' Admin',
} as const;

/** Server-only base URL, used for requests inside the docker network. */
export function serverApiBaseUrl(): string {
  return process.env.INTERNAL_API_BASE_URL ?? publicEnv.apiBaseUrl;
}

/**
 * What the dashboard's own API calls use, which is not `publicEnv.apiBaseUrl`.
 *
 * Relative by default, because the gateway serves the dashboard and the API
 * from one origin (`admin.localhost:8080`). A session cookie is only sent on a
 * same-origin request unless SameSite is loosened, and loosening it for a
 * staff tool that can change prices is not a trade worth making.
 *
 * Set NEXT_PUBLIC_ADMIN_API_BASE_URL to the gateway's origin when opening the
 * Next.js port directly in development; `/api/v1` would otherwise resolve
 * against Next itself, which serves no API.
 */
export const adminEnv = {
  apiBaseUrl: process.env.NEXT_PUBLIC_ADMIN_API_BASE_URL ?? '/api/v1',
  csrfPath:
    (process.env.NEXT_PUBLIC_ADMIN_API_BASE_URL ?? '').replace(/\/api\/v1\/?$/, '') +
    '/sanctum/csrf-cookie',
} as const;
