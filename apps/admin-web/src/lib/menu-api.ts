/**
 * The menu screen's data layer: the designed contract on one side, the shape
 * the screens render on the other.
 *
 * Why the mapping exists at all. The API models what the business models:
 * products are master data, and a MENU decides what is sold, at which price,
 * with how many portions left today. The screen shows one card per dish with a
 * price and a portion count, which is a menu item joined to its product. This
 * module is that join, in one place, so no screen has to know it.
 *
 * Two API calls make up one "add a dish", and deliberately so: creating a
 * product does not put it on sale, a menu does. The alternative - an endpoint
 * that creates and lists in one step - would bake the food vertical into the
 * catalog, which is exactly what CLAUDE.md section 16 keeps apart so packaged
 * goods and retail stay possible.
 */

import type { FoodIconName } from '@/components/admin/icons';

import { api } from './api-client';

/** What the dashboard screens render. `id` is the product's UUID. */
export interface MenuProduct {
  readonly id: string;
  readonly name: string;
  readonly cat: string;
  /** The icon shown when there is no photo. */
  readonly icon: FoodIconName;
  /** An uploaded photo, or null when the dish shows its icon instead. */
  readonly imageUrl: string | null;
  /** Integer đồng, as the API sends it. */
  readonly price: number;
  /** Portions still sellable today. */
  readonly stock: number;
  /** Portions prepared today. */
  readonly capacity: number;
}

/**
 * The same dish plus what the API needs to change it.
 *
 * Kept out of `MenuProduct` so a draft built from a form cannot accidentally
 * carry stale server ids.
 */
export interface MenuProductRecord extends MenuProduct {
  readonly variantId: string;
  readonly menuItemId: string;
}

const FOOD_ICONS: readonly FoodIconName[] = ['banh-mi', 'xoi', 'pho', 'drink', 'combo', 'gift'];

const UNCATEGORISED = 'Chưa phân loại';

/** Portions default to zero rather than null: the card shows a number. */
const PORTIONS_FALLBACK = 0;

interface Money {
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
    readonly icon: string | null;
    readonly image_url: string | null;
    readonly category: ApiCategory | null;
  };
  readonly variant: { readonly id: string; readonly name: string };
  readonly price: Money;
  readonly is_available: boolean;
  readonly available_quantity: number | null;
  readonly daily_capacity: number | null;
  readonly sort_order?: number;
}

export interface ApiMenu {
  readonly id: string;
  readonly name: string;
  readonly status: string;
  readonly items: readonly ApiMenuItem[];
}

interface ApiProduct {
  readonly id: string;
  readonly name: string;
  readonly category_id: string | null;
  readonly category: ApiCategory | null;
  readonly icon: string | null;
  readonly image_url: string | null;
  readonly status: string;
  readonly variants: readonly {
    readonly id: string;
    readonly name: string;
    readonly base_price: Money;
    readonly status: string;
  }[];
}

interface Page<T> {
  readonly items: readonly T[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

/** A dish as the form produced it, before the server has seen it. */
export interface MenuProductDraft {
  readonly name: string;
  readonly cat: string;
  readonly icon: FoodIconName;
  readonly imageUrl: string | null;
  readonly price: number;
  readonly stock: number;
  readonly capacity: number;
}

function toIcon(value: string | null): FoodIconName {
  return FOOD_ICONS.find((icon) => icon === value) ?? 'gift';
}

/**
 * Integer đồng from the transport string.
 *
 * `Number` rather than `BigInt` because these are displayed, not summed: the
 * largest price the server accepts is a hundred million, far inside a safe
 * integer. The API's string form is still the contract (CLAUDE.md section 6).
 */
function toAmount(money: Money): number {
  return Number.parseInt(money.amount, 10);
}

function toMenuProduct(item: ApiMenuItem): MenuProductRecord {
  return {
    id: item.product.id,
    name: item.product.name,
    cat: item.product.category?.name ?? UNCATEGORISED,
    icon: toIcon(item.product.icon),
    imageUrl: item.product.image_url,
    price: toAmount(item.price),
    stock: item.available_quantity ?? PORTIONS_FALLBACK,
    capacity: item.daily_capacity ?? item.available_quantity ?? PORTIONS_FALLBACK,
    variantId: item.variant.id,
    menuItemId: item.id,
  };
}

/**
 * The photo-or-icon pair, as the API wants it.
 *
 * Exactly one is set and the other is explicitly null — not omitted — so a
 * save actually clears what the dish stopped showing. The server enforces the
 * same rule and refuses a payload carrying both.
 */
function imageFields(draft: MenuProductDraft): { image_url: string | null; icon: string | null } {
  return draft.imageUrl === null
    ? { image_url: null, icon: draft.icon }
    : { image_url: draft.imageUrl, icon: null };
}

/** The item payload for a menu replacement, carrying today's counts unchanged. */
function toItemPayload(record: MenuProductRecord, index: number) {
  return {
    variant_id: record.variantId,
    selling_price_amount: String(record.price),
    is_available: record.stock > 0,
    available_quantity: record.stock,
    daily_capacity: record.capacity,
    sort_order: index,
  };
}

export interface MenuSnapshot {
  readonly menu: ApiMenu;
  readonly products: readonly MenuProductRecord[];
}

/**
 * Loads the store's menu and the dishes on it.
 *
 * The store comes from the host the request arrives on, never from the client,
 * so there is no store id to pass.
 */
export async function loadMenu(): Promise<MenuSnapshot> {
  const page = await api.get<Page<ApiMenu>>('/admin/menus');
  const menu = page.items[0];

  if (menu === undefined) {
    throw new Error('Cửa hàng này chưa có thực đơn nào.');
  }

  // The list endpoint already returns each menu with its items, so one call is
  // enough; the single-menu read exists for a deep link, not for this screen.
  return { menu, products: menu.items.map(toMenuProduct) };
}

/**
 * Adds a dish: create the product, then list it on the menu.
 *
 * The menu write is a full replacement, as designed, so the current items are
 * sent back with the new one appended.
 */
export async function createMenuProduct(
  snapshot: MenuSnapshot,
  draft: MenuProductDraft,
): Promise<MenuSnapshot> {
  const product = await api.post<ApiProduct>('/admin/products', {
    name: draft.name,
    status: 'ACTIVE',
    category_name: draft.cat,
    ...imageFields(draft),
    variants: [
      // One default variant: the form offers one price, so inventing sizes
      // nobody asked for would be worse than honest.
      { name: 'Mặc định', base_price_amount: String(draft.price), status: 'ACTIVE' },
    ],
  });

  const variant = product.variants[0];

  if (variant === undefined) {
    throw new Error('Máy chủ trả về món không có mức giá nào.');
  }

  return replaceMenu(snapshot, [
    ...snapshot.products.map(toItemPayload),
    {
      variant_id: variant.id,
      selling_price_amount: String(draft.price),
      is_available: draft.stock > 0,
      available_quantity: draft.stock,
      daily_capacity: draft.capacity,
      sort_order: snapshot.products.length,
    },
  ]);
}

/**
 * Saves an edit: the product's own fields, then its price and portions.
 *
 * The variant price is updated as well as the menu price. They are different
 * things - one is the catalogue default, the other is what this store charges -
 * but the form shows one field, so the honest reading is that both change.
 */
export async function updateMenuProduct(
  snapshot: MenuSnapshot,
  productId: string,
  draft: MenuProductDraft,
): Promise<MenuSnapshot> {
  const current = snapshot.products.find((product) => product.id === productId);

  if (current === undefined) {
    throw new Error('Món này không còn trong thực đơn.');
  }

  await api.put<ApiProduct>(`/admin/products/${productId}`, {
    name: draft.name,
    status: 'ACTIVE',
    category_name: draft.cat,
    ...imageFields(draft),
    variants: [
      {
        id: current.variantId,
        name: 'Mặc định',
        base_price_amount: String(draft.price),
        status: 'ACTIVE',
      },
    ],
  });

  return replaceMenu(
    snapshot,
    snapshot.products.map((product, index) =>
      product.id === productId
        ? toItemPayload({ ...product, ...draft, id: productId }, index)
        : toItemPayload(product, index),
    ),
  );
}

/**
 * Removes a dish by archiving the product.
 *
 * Never a hard delete: a dish that has been ordered has to stay readable, so
 * the product keeps its row with status ARCHIVED and the menu stops offering
 * it (CLAUDE.md sections 6 and 7). The menu item row survives too.
 */
export async function archiveMenuProduct(
  snapshot: MenuSnapshot,
  productId: string,
): Promise<MenuSnapshot> {
  const current = snapshot.products.find((product) => product.id === productId);

  if (current === undefined) {
    throw new Error('Món này không còn trong thực đơn.');
  }

  await api.put<ApiProduct>(`/admin/products/${productId}`, {
    name: current.name,
    status: 'ARCHIVED',
    category_name: current.cat,
    ...imageFields(current),
    variants: [
      {
        id: current.variantId,
        name: 'Mặc định',
        base_price_amount: String(current.price),
        status: 'ARCHIVED',
      },
    ],
  });

  return loadMenu();
}

/** Sets today's remaining portions on one dish. */
export async function saveMenuProductStock(
  snapshot: MenuSnapshot,
  productId: string,
  stock: number,
): Promise<MenuSnapshot> {
  const current = snapshot.products.find((product) => product.id === productId);

  if (current === undefined) {
    throw new Error('Món này không còn trong thực đơn.');
  }

  const item = await api.put<ApiMenuItem>(`/admin/menu-items/${current.menuItemId}/availability`, {
    is_available: stock > 0,
    available_quantity: stock,
    // Raised when more portions are cooked than were planned; a kitchen that
    // made twenty more should not have to edit the menu to say so.
    daily_capacity: Math.max(current.capacity, stock),
  });

  const updated = toMenuProduct(item);

  return {
    menu: snapshot.menu,
    products: snapshot.products.map((product) => (product.id === productId ? updated : product)),
  };
}

async function replaceMenu(
  snapshot: MenuSnapshot,
  items: readonly ReturnType<typeof toItemPayload>[],
): Promise<MenuSnapshot> {
  const menu = await api.put<ApiMenu>(`/admin/menus/${snapshot.menu.id}`, {
    name: snapshot.menu.name,
    status: 'ACTIVE',
    schedules: [],
    items,
  });

  return { menu, products: menu.items.map(toMenuProduct) };
}
