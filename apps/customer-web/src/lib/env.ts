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
