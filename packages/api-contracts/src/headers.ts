/** Header names shared by clients and the API. */
export const HEADER_REQUEST_ID = 'x-request-id';
/** Required on order creation and on manual payment confirmation; the stored result is replayed on retry. */
export const HEADER_IDEMPOTENCY_KEY = 'idempotency-key';
/** Guest cart access token, issued once by POST /carts; only its hash is stored, so it is never re-issued. */
export const HEADER_GUEST_CART_TOKEN = 'x-guest-cart-token';
/** Guest order access token issued at checkout; orders are never addressable by id alone. */
export const HEADER_GUEST_ORDER_TOKEN = 'x-guest-order-token';
