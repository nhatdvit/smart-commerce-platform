/** Header names shared by clients and the API. */
export const HEADER_REQUEST_ID = 'x-request-id';
/** Required on order creation; the stored result is replayed on retry. */
export const HEADER_IDEMPOTENCY_KEY = 'idempotency-key';
/** Guest order access token issued at checkout; orders are never addressable by id alone. */
export const HEADER_GUEST_ORDER_TOKEN = 'x-guest-order-token';
