/**
 * Customer self-service auth: `POST /auth/register`, `POST /auth/login`,
 * `POST /auth/logout` and `GET /me`, against the designed `CurrentUser`
 * schema (`docs/api/openapi.yaml`).
 *
 * A customer signs in by phone, not email - see `RegisterRequest` on the
 * backend. Staff accounts stay out of scope here entirely: they are
 * provisioned by an admin and sign in through admin-web.
 */
import { ApiError, api } from './api-client';

export interface CurrentCustomer {
  readonly id: string;
  readonly name: string;
  readonly phone: string | null;
}

interface ApiCurrentUser {
  readonly id: string;
  readonly name: string;
  readonly email: string | null;
  readonly phone: string | null;
  readonly roles: readonly string[];
  readonly store_memberships: readonly unknown[];
}

function toCustomer(user: ApiCurrentUser): CurrentCustomer {
  return { id: user.id, name: user.name, phone: user.phone };
}

/** `null` when no one is signed in - not an error worth surfacing on first load. */
export async function getCurrentCustomer(): Promise<CurrentCustomer | null> {
  try {
    return toCustomer(await api.get<ApiCurrentUser>('/me'));
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return null;
    }
    throw error;
  }
}

export async function registerCustomer(
  fullName: string,
  phone: string,
  password: string,
): Promise<CurrentCustomer> {
  return toCustomer(
    await api.post<ApiCurrentUser>('/auth/register', {
      full_name: fullName,
      phone,
      password,
    }),
  );
}

export async function loginCustomer(phone: string, password: string): Promise<CurrentCustomer> {
  return toCustomer(await api.post<ApiCurrentUser>('/auth/login', { phone, password }));
}

export async function logoutCustomer(): Promise<void> {
  await api.post('/auth/logout');
}
