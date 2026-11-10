/**
 * Canonical API route table. OpenAPI remains the authority for schemas;
 * this keeps path strings from drifting between the two web apps.
 */
export const API_PREFIX = '/api/v1';

export const ROUTES = {
  health: { live: '/health/live', ready: '/health/ready' },

  public: {
    store: (storeId: string) => API_PREFIX + '/stores/' + storeId,
    menuToday: API_PREFIX + '/menus/today',
    products: API_PREFIX + '/products',
    product: (id: string) => API_PREFIX + '/products/' + id,
    carts: API_PREFIX + '/carts',
    cartItems: (cartId: string) => API_PREFIX + '/carts/' + cartId + '/items',
    orders: API_PREFIX + '/orders',
    order: (id: string) => API_PREFIX + '/orders/' + id,
    orderPayment: (id: string) => API_PREFIX + '/orders/' + id + '/payment',
    paymentMethods: API_PREFIX + '/payment-methods',
  },

  admin: {
    orders: API_PREFIX + '/admin/orders',
    menu: (id: string) => API_PREFIX + '/admin/menus/' + id,
    payments: API_PREFIX + '/admin/payments',
    confirmPayment: (id: string) => API_PREFIX + '/admin/payments/' + id + '/confirm',
    storeBankAccount: (storeId: string) =>
      API_PREFIX + '/admin/stores/' + storeId + '/bank-account',
  },
} as const;
