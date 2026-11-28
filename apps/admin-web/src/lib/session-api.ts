/**
 * Who is signed in, and what the screen should therefore offer them.
 *
 * The server is the authority on every one of these decisions: each admin
 * route checks the permission AND the store membership, and answers 403 when
 * the answer is no (CLAUDE.md section 8). Nothing here grants anything. It
 * only keeps the dashboard from showing a kitchen account a revenue figure it
 * would be refused if it asked for it - a tidier screen, not a security
 * boundary.
 *
 * The grants mirrored below are `database/seeders/RolePermissionSeeder.php`.
 */

import { api } from './api-client';

export const STAFF_ROLES = [
  'SUPER_ADMIN',
  'STORE_OWNER',
  'STORE_MANAGER',
  'CASHIER',
  'KITCHEN_STAFF',
] as const;

export type StaffRole = (typeof STAFF_ROLES)[number];

export interface StaffIdentity {
  readonly name: string;
  readonly email: string;
  /** Null when the session has no store role this app knows about. */
  readonly role: StaffRole | null;
  /** Two letters for the avatar. */
  readonly initials: string;
}

interface ApiMe {
  readonly name: string | null;
  readonly email: string | null;
  readonly roles: readonly string[];
  readonly store_memberships: readonly {
    readonly role: string;
    readonly status: string;
    readonly store_id: string;
    readonly store_name: string;
  }[];
}

function toRole(value: string | undefined): StaffRole | null {
  return STAFF_ROLES.find((role) => role === value) ?? null;
}

/**
 * "Nguyễn Văn An" -> "NA". A single word gives its first two letters, and an
 * empty name gives nothing rather than a placeholder that looks like a name.
 */
function toInitials(name: string): string {
  const words = name
    .trim()
    .split(/\s+/)
    .filter((word) => word !== '');

  if (words.length === 0) {
    return '';
  }

  if (words.length === 1) {
    return (words[0] ?? '').slice(0, 2).toUpperCase();
  }

  const first = words[0] ?? '';
  const last = words[words.length - 1] ?? '';

  return (first.charAt(0) + last.charAt(0)).toUpperCase();
}

function toIdentity(me: ApiMe): StaffIdentity {
  const global = me.roles.map(toRole).find((role) => role !== null) ?? null;
  const membership =
    me.store_memberships.find((entry) => entry.status === 'ACTIVE') ?? me.store_memberships[0];
  const name = me.name ?? me.email ?? '';

  return {
    name,
    email: me.email ?? '',
    role: global ?? toRole(membership?.role),
    initials: toInitials(name),
  };
}

/**
 * Asked once per page life: who is signed in cannot change without a new
 * session, and every screen that wants it would otherwise ask again.
 */
let identityRequest: Promise<StaffIdentity> | undefined;

export async function loadIdentity(): Promise<StaffIdentity> {
  // A failure is not cached: the usual reason this read fails is that nobody
  // is signed in yet, and remembering that answer would outlive the sign-in
  // that follows it.
  identityRequest ??= api
    .get<ApiMe>('/me')
    .then(toIdentity)
    .catch((error: unknown) => {
      identityRequest = undefined;

      throw error;
    });

  return identityRequest;
}

/** Called after signing out, so the next operator is not greeted by name. */
export function forgetIdentity(): void {
  identityRequest = undefined;
}

/** What each role is called on screen. */
export const ROLE_LABELS: Readonly<Record<StaffRole, string>> = {
  SUPER_ADMIN: 'Quản trị hệ thống',
  STORE_OWNER: 'Chủ cửa hàng',
  STORE_MANAGER: 'Quản lý cửa hàng',
  CASHIER: 'Thu ngân',
  KITCHEN_STAFF: 'Nhân viên bếp',
};

/**
 * `payment.read`: may see what the shop has taken.
 *
 * Kitchen staff deliberately cannot - "No payment or pricing access" is the
 * role's whole definition in the seeder, and a dashboard that shows them the
 * day's revenue would be the one place in the app that ignores it.
 */
export function canSeeMoney(role: StaffRole | null): boolean {
  return role !== null && role !== 'KITCHEN_STAFF';
}

/** `catalog.manage` / `menu.manage`: deciding what is sold and for how much. */
export function canManageMenu(role: StaffRole | null): boolean {
  return role === 'SUPER_ADMIN' || role === 'STORE_OWNER' || role === 'STORE_MANAGER';
}

/** `payment.confirm`: standing at the counter settling money. */
export function canConfirmPayments(role: StaffRole | null): boolean {
  return canSeeMoney(role);
}
