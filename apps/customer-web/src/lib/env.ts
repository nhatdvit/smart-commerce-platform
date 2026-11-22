/**
 * Browser-visible configuration. Only NEXT_PUBLIC_ variables may be read here;
 * anything secret stays on the server.
 */
export const publicEnv = {
  apiBaseUrl: process.env.NEXT_PUBLIC_API_BASE_URL ?? 'http://localhost:3000/api/v1',
  siteName: process.env.NEXT_PUBLIC_SITE_NAME ?? 'Smart Food Ordering',
} as const;

/** Server-only base URL, used for requests inside the docker network. */
export function serverApiBaseUrl(): string {
  return process.env.INTERNAL_API_BASE_URL ?? publicEnv.apiBaseUrl;
}

/**
 * What the storefront's own browser-side API calls use, which is not
 * `publicEnv.apiBaseUrl`.
 *
 * Relative by default, because the gateway serves the storefront and the API
 * from one origin (`localhost:8080` in development) - see
 * `infra/nginx/conf.d/default.conf`. That is also why an image path the API
 * returns (an uploaded photo, or a seeded one under `/assets`) is rendered
 * exactly as given, with no origin to join it against: both already resolve
 * against this same origin.
 *
 * Set NEXT_PUBLIC_STOREFRONT_API_BASE_URL to the gateway's origin when opening
 * the Next.js port directly in development; `/api/v1` would otherwise resolve
 * against Next itself, which serves no API.
 */
export const storefrontEnv = {
  apiBaseUrl: process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? '/api/v1',
  csrfPath:
    (process.env.NEXT_PUBLIC_STOREFRONT_API_BASE_URL ?? '').replace(/\/api\/v1\/?$/, '') +
    '/sanctum/csrf-cookie',
} as const;
