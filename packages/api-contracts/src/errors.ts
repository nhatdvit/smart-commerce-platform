/**
 * Stable, machine-readable error codes. The HTTP status carries the class of
 * failure; this code carries the reason. Never change an existing code's
 * meaning - add a new one (CLAUDE.md sections 3 and 8).
 */
export const ERROR_CODE = {
  // 400 / 422 - request shape and business validation
  VALIDATION_FAILED: 'VALIDATION_FAILED',
  INVALID_STORE_CONTEXT: 'INVALID_STORE_CONTEXT',

  // 401 / 403 - authentication and authorization
  UNAUTHENTICATED: 'UNAUTHENTICATED',
  FORBIDDEN: 'FORBIDDEN',
  STORE_SCOPE_DENIED: 'STORE_SCOPE_DENIED',

  // 404
  RESOURCE_NOT_FOUND: 'RESOURCE_NOT_FOUND',

  // 409 - conflicts and concurrency
  IDEMPOTENCY_KEY_REUSED: 'IDEMPOTENCY_KEY_REUSED',
  INVALID_STATE_TRANSITION: 'INVALID_STATE_TRANSITION',
  OUT_OF_STOCK: 'OUT_OF_STOCK',
  PRICE_CHANGED: 'PRICE_CHANGED',
  MENU_UNAVAILABLE: 'MENU_UNAVAILABLE',
  PAYMENT_ALREADY_CONFIRMED: 'PAYMENT_ALREADY_CONFIRMED',
  PAYMENT_AMOUNT_MISMATCH: 'PAYMENT_AMOUNT_MISMATCH',

  // 429
  RATE_LIMITED: 'RATE_LIMITED',

  // 500 / 503
  INTERNAL_ERROR: 'INTERNAL_ERROR',
  DEPENDENCY_UNAVAILABLE: 'DEPENDENCY_UNAVAILABLE',
} as const;

export type ErrorCode = (typeof ERROR_CODE)[keyof typeof ERROR_CODE];

/** The single error body shape returned by every endpoint. */
export interface ApiErrorResponse {
  statusCode: number;
  code: ErrorCode;
  message: string;
  /** Field-level detail for validation failures. Never contains secrets. */
  details?: Array<{ field: string; message: string }>;
  /** Correlation id, also returned as the x-request-id response header. */
  requestId: string;
  timestamp: string;
  path: string;
}
