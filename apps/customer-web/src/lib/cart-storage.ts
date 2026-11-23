/**
 * The one place that touches the cart's localStorage key - there is no
 * persisted Cart module yet (see `use-storefront.ts`'s own doc comment), so
 * this is what lets the home page and the order-history page's "Đặt lại"
 * action agree on the same cart without sharing any React state.
 */

export type CartState = Readonly<Record<string, number>>;

const CART_KEY = 'pq-v5-cart';

function readStored(): unknown {
  try {
    const raw = window.localStorage.getItem(CART_KEY);

    return raw === null ? undefined : (JSON.parse(raw) as unknown);
  } catch {
    // A private window, blocked site data or a corrupted value: fall back to defaults.
    return undefined;
  }
}

function asCart(value: unknown): CartState {
  if (typeof value !== 'object' || value === null) {
    return {};
  }

  const cart: Record<string, number> = {};
  for (const [key, quantity] of Object.entries(value as Record<string, unknown>)) {
    if (typeof quantity === 'number' && Number.isFinite(quantity) && quantity > 0) {
      cart[key] = Math.floor(quantity);
    }
  }

  return cart;
}

export function readCart(): CartState {
  return asCart(readStored());
}

export function writeCart(cart: CartState): void {
  try {
    window.localStorage.setItem(CART_KEY, JSON.stringify(cart));
  } catch {
    // Storage unavailable: the caller's own in-memory state keeps working.
  }
}

/** Adds `quantity` of one menu item on top of whatever is already in the stored cart, and returns the result. */
export function addToStoredCart(menuItemId: string, quantity: number): CartState {
  const current = readCart();
  const next = { ...current, [menuItemId]: (current[menuItemId] ?? 0) + quantity };
  writeCart(next);

  return next;
}
