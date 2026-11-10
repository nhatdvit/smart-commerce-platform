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
