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

interface ApiCustomerProfile {
  readonly id: string;
  readonly name: string;
  readonly email: string | null;
  readonly phone: string | null;
  readonly pending_email: string | null;
  readonly created_at: string;
}

function toCustomerFromProfile(profile: ApiCustomerProfile): CurrentCustomer {
  return { id: profile.id, name: profile.name, phone: profile.phone };
}

/** Full name only - see `UpdateCustomerName` on the backend for why phone/email are not here. */
export async function updateOwnProfile(name: string): Promise<CurrentCustomer> {
  return toCustomerFromProfile(await api.put<ApiCustomerProfile>('/customer/profile', { name }));
}

export async function changeOwnPassword(currentPassword: string, newPassword: string): Promise<void> {
  await api.put('/customer/password', {
    current_password: currentPassword,
    new_password: newPassword,
  });
}

export interface LoyaltyStanding {
  readonly balance: number;
  readonly lifetimeEarnedPoints: number;
  readonly tier: string;
  readonly tierLabel: string;
}

interface ApiLoyaltyStanding {
  readonly phone: string;
  readonly lifetime_earned_points: number;
  readonly balance: number;
  readonly tier: string;
  readonly tier_label: string;
}

/** The signed-in customer's own standing in the current store's loyalty program. */
export async function getOwnLoyalty(): Promise<LoyaltyStanding> {
  const data = await api.get<ApiLoyaltyStanding>('/customer/loyalty');

  return {
    balance: data.balance,
    lifetimeEarnedPoints: data.lifetime_earned_points,
    tier: data.tier,
    tierLabel: data.tier_label,
  };
}
