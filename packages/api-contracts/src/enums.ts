/**
 * Enumerations shared by the API, the customer web app and the admin web app.
 * These mirror the database enums; change both together (see docs/database/DATABASE_SPEC.md).
 */

/** Order lifecycle. Separate state machine from PaymentStatus. */
export const ORDER_STATUS = [
  'PENDING_PAYMENT',
  'PLACED',
  'CONFIRMED',
  'PREPARING',
  'READY',
  'COMPLETED',
  'CANCELLED',
  'EXPIRED',
] as const;
export type OrderStatus = (typeof ORDER_STATUS)[number];

/** Payment lifecycle. `COMPLETED` order never implies `PAID`. */
export const PAYMENT_STATUS = [
  'PENDING',
  'PROCESSING',
  'PAID',
  'FAILED',
  'PARTIALLY_REFUNDED',
  'REFUNDED',
] as const;
export type PaymentStatus = (typeof PAYMENT_STATUS)[number];

/** MVP payment methods — fixed scope, see CLAUDE.md §6. */
export const PAYMENT_METHOD = ['CASH', 'BANK_TRANSFER'] as const;
export type PaymentMethod = (typeof PAYMENT_METHOD)[number];

export const FULFILLMENT_TYPE = ['PICKUP', 'DELIVERY'] as const;
export type FulfillmentType = (typeof FULFILLMENT_TYPE)[number];

export const FULFILLMENT_STATUS = [
  'PENDING',
  'PREPARING',
  'READY',
  'DISPATCHED',
  'DELIVERED',
  'CANCELLED',
] as const;
export type FulfillmentStatus = (typeof FULFILLMENT_STATUS)[number];

/** Admin roles. Customer access is scoped separately. */
export const ADMIN_ROLE = [
  'SUPER_ADMIN',
  'STORE_OWNER',
  'STORE_MANAGER',
  'CASHIER',
  'KITCHEN_STAFF',
] as const;
export type AdminRole = (typeof ADMIN_ROLE)[number];

export const ROLE_SCOPE = ['GLOBAL', 'STORE'] as const;
export type RoleScope = (typeof ROLE_SCOPE)[number];
