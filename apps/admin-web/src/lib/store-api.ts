/**
 * "Which store is this session in."
 *
 * Every other screen never needs this: the API resolves its store silently by
 * host or default. The two payment-settings endpoints are the one place the
 * store id has to travel in the URL (CLAUDE.md section 8's own example), so
 * this is the one lookup the dashboard needs before it can build them.
 */

import { api } from './api-client';

export interface StoreContext {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  readonly timezone: string;
}

export async function loadStoreContext(): Promise<StoreContext> {
  return api.get<StoreContext>('/admin/store');
}
