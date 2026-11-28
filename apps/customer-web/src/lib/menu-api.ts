/**
 * The home screen's data layer: today's real menu, from `GET /menus/today`.
 *
 * No authentication and no credentials - the endpoint is public, and nothing
 * here ever sends a cookie or a token. `storefrontEnv.apiBaseUrl` is relative,
 * so this resolves against the gateway's own origin, same as the images the
 * API returns (see the note on `storefrontEnv`).
 */

import { storefrontEnv } from './env';

export class MenuApiError extends Error {}

interface ApiMoney {
  readonly amount: string;
  readonly currency: string;
}

interface ApiCategory {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

interface ApiMenuItem {
  readonly id: string;
  readonly product: {
    readonly id: string;
    readonly name: string;
    readonly slug: string;
    readonly description: string | null;
    readonly image_url: string | null;
    readonly icon: string | null;
    readonly category: ApiCategory | null;
  };
  readonly variant: {
    readonly id: string;
    readonly name: string;
    readonly sku: string | null;
  };
  readonly price: ApiMoney;
  readonly is_available: boolean;
  readonly available_quantity: number | null;
  readonly daily_capacity: number | null;
  readonly sort_order: number;
}

interface ApiTodayMenu {
  readonly store_id: string;
  readonly menu_id: string;
  readonly name: string;
  readonly service_date: string;
  readonly timezone: string;
  readonly items: readonly ApiMenuItem[];
}

/** One sellable variant of a dish, exactly as a cart line will reference it. */
export interface MenuItem {
  readonly id: string;
  readonly productId: string;
  readonly variantId: string;
  readonly productName: string;
  readonly description: string | null;
  readonly imageUrl: string | null;
  readonly icon: string | null;
  readonly category: ApiCategory | null;
  readonly variantName: string;
  /** Integer đồng. */
  readonly price: number;
  readonly isAvailable: boolean;
  /**
   * Portions left today, or null for an item nobody counts.
   *
   * Null and zero are NOT the same thing: null is a bottled drink the kitchen
   * never counts, zero is a dish that has run out (CLAUDE.md section 6, and
   * the API's own MenuItem entity). Reading null as zero would close the shop.
   */
  readonly availableQuantity: number | null;
  readonly sortOrder: number;
}

export interface TodayMenu {
  readonly storeId: string;
  readonly menuId: string;
  readonly name: string;
  readonly serviceDate: string;
  readonly timezone: string;
  readonly items: readonly MenuItem[];
}

/** Integer đồng from the transport string (CLAUDE.md section 6). */
function toAmount(money: ApiMoney): number {
  return Number.parseInt(money.amount, 10);
}

function toMenuItem(item: ApiMenuItem): MenuItem {
  return {
    id: item.id,
    productId: item.product.id,
    variantId: item.variant.id,
    productName: item.product.name,
    description: item.product.description,
    imageUrl: item.product.image_url,
    icon: item.product.icon,
    category: item.product.category,
    variantName: item.variant.name,
    price: toAmount(item.price),
    isAvailable: item.is_available,
    availableQuantity: item.available_quantity,
    sortOrder: item.sort_order,
  };
}

/**
 * What a customer can order right now.
 *
 * `null` means the store has nothing on sale - not an error the screen must
 * explain away, just an empty menu. A real failure (network down, server
 * error) throws `MenuApiError` instead.
 */
export async function loadTodayMenu(): Promise<TodayMenu | null> {
  let response: Response;

  try {
    response = await fetch(`${storefrontEnv.apiBaseUrl}/menus/today`, {
      headers: { Accept: 'application/json' },
    });
  } catch {
    throw new MenuApiError('Không kết nối được máy chủ.');
  }

  if (response.status === 404) {
    return null;
  }

  if (!response.ok) {
    throw new MenuApiError('Không tải được thực đơn.');
  }

  const data = (await response.json()) as ApiTodayMenu;

  return {
    storeId: data.store_id,
    menuId: data.menu_id,
    name: data.name,
    serviceDate: data.service_date,
    timezone: data.timezone,
    items: data.items.map(toMenuItem),
  };
}

/** One dish, with every variant a customer may choose between (packaged weight, size...). */
export interface MenuProductCard {
  readonly productId: string;
  readonly name: string;
  readonly description: string | null;
  readonly imageUrl: string | null;
  readonly icon: string | null;
  readonly category: ApiCategory | null;
  /** Only the variants still on sale; see `isSoldOut`. */
  readonly options: readonly MenuItem[];
  /** True when every variant of this dish is unavailable right now. */
  readonly isSoldOut: boolean;
}

/**
 * Whether this variant can still be added to a cart.
 *
 * Two different ways a dish stops being sellable: staff switched it off
 * (`isAvailable`), or today's portions ran out (`availableQuantity === 0`).
 * The API refuses both at checkout; this is the same rule, applied early so
 * the customer sees "Hết hàng" instead of filling a cart that will be
 * rejected.
 */
export function isSellable(item: MenuItem): boolean {
  return item.isAvailable && item.availableQuantity !== 0;
}

/**
 * Groups menu items back into dishes.
 *
 * The menu is a flat list of sellable variants - "Chả ốc 250g" and "Chả ốc
 * 1kg" are two different rows, each with its own id and price, because that is
 * what a cart line actually references. A customer does not browse it that
 * way: one dish, one card, a picker for the packaged weight. This is the one
 * reshaping step between the two.
 */
export function groupIntoCards(items: readonly MenuItem[]): readonly MenuProductCard[] {
  const order: string[] = [];
  const byProduct = new Map<string, MenuItem[]>();

  for (const item of items) {
    const existing = byProduct.get(item.productId);

    if (existing === undefined) {
      byProduct.set(item.productId, [item]);
      order.push(item.productId);
    } else {
      existing.push(item);
    }
  }

  const cards: MenuProductCard[] = [];

  for (const productId of order) {
    const variants = byProduct.get(productId) ?? [];
    const first = variants[0];

    if (first === undefined) {
      continue;
    }

    const options = variants.filter(isSellable);

    cards.push({
      productId,
      name: first.productName,
      description: first.description,
      imageUrl: first.imageUrl,
      icon: first.icon,
      category: first.category,
      options,
      isSoldOut: options.length === 0,
    });
  }

  return cards;
}
