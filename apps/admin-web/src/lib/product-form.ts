/**
 * The menu item form: one definition of a draft and one validator, shared by
 * the "add" and the "edit" dialog.
 *
 * Kept apart from both so the two cannot drift - which they already had: the
 * edit dialog accepted a price and a portion count while the add dialog was a
 * placeholder, so there was no agreement anywhere about what a valid menu item
 * is. This module is that agreement, and it is the piece the Catalog module's
 * Form Requests have to match when the API lands (CLAUDE.md section 6: the
 * server revalidates, the client never decides).
 */

import type { FoodIconName } from '@/components/admin/icons';
import type { MenuProduct } from '@/lib/menu-api';
import { money } from '@/lib/money';

/** Thumbnail choices, labelled for the dialog's select. */
export const FOOD_ICON_CHOICES: readonly {
  readonly value: FoodIconName;
  readonly label: string;
}[] = [
  { value: 'banh-mi', label: 'Bánh mì' },
  { value: 'xoi', label: 'Xôi' },
  { value: 'pho', label: 'Bún / Phở' },
  { value: 'drink', label: 'Đồ uống' },
  { value: 'combo', label: 'Combo' },
  { value: 'gift', label: 'Khác' },
];

/** What the inputs hold: strings, because that is what an `<input>` gives. */
export interface ProductFormValues {
  readonly name: string;
  readonly cat: string;
  readonly icon: FoodIconName;
  readonly price: string;
  readonly stock: string;
  readonly capacity: string;
}

/** A menu item that passed validation, ready to be stored. */
export type ProductDraft = Omit<MenuProduct, 'id'>;

export type ProductFieldErrors = Partial<Record<keyof ProductFormValues, string>>;

export type ProductFormResult =
  | { readonly ok: true; readonly draft: ProductDraft }
  | { readonly ok: false; readonly errors: ProductFieldErrors };

export const MAX_PRICE = 100_000_000;
export const MAX_PORTIONS = 10_000;
export const MAX_NAME_LENGTH = 120;
export const MAX_CATEGORY_LENGTH = 60;

export const EMPTY_PRODUCT_FORM: ProductFormValues = {
  name: '',
  cat: '',
  icon: 'banh-mi',
  price: '',
  stock: '',
  capacity: '',
};

export function productFormValues(product: MenuProduct): ProductFormValues {
  return {
    name: product.name,
    cat: product.cat,
    icon: product.icon,
    price: String(product.price),
    stock: String(product.stock),
    capacity: String(product.capacity),
  };
}

/** Collapses the whitespace a pasted name usually carries. */
function normalise(value: string): string {
  return value.trim().replace(/\s+/g, ' ');
}

/**
 * Digits only, on purpose.
 *
 * `Number()` is too generous to validate with: it reads `''` and `'  '` as 0,
 * `'1e3'` as 1000 and `'12.00'` as 12, and `Number.isInteger` then waves all
 * three through. The previous edit dialog did exactly that, so submitting an
 * empty price field silently saved a price of 0đ.
 */
function parseWholeNumber(raw: string): number | undefined {
  const trimmed = raw.trim();

  return /^\d{1,9}$/.test(trimmed) ? Number(trimmed) : undefined;
}

/**
 * Validates one menu item against the rest of the menu.
 *
 * `editingId` is the item being changed, excluded from the duplicate-name
 * check so saving an item without renaming it does not collide with itself.
 */
export function validateProductForm(
  values: ProductFormValues,
  products: readonly MenuProduct[],
  editingId?: string,
): ProductFormResult {
  const errors: ProductFieldErrors = {};

  const name = normalise(values.name);
  const cat = normalise(values.cat);

  if (name === '') {
    errors.name = 'Vui lòng nhập tên món.';
  } else if (name.length > MAX_NAME_LENGTH) {
    errors.name = `Tên món tối đa ${MAX_NAME_LENGTH} ký tự.`;
  } else if (
    products.some(
      (product) =>
        product.id !== editingId &&
        normalise(product.name).toLocaleLowerCase('vi') === name.toLocaleLowerCase('vi'),
    )
  ) {
    errors.name = 'Thực đơn đã có món khác cùng tên.';
  }

  if (cat === '') {
    errors.cat = 'Vui lòng chọn hoặc nhập danh mục.';
  } else if (cat.length > MAX_CATEGORY_LENGTH) {
    errors.cat = `Tên danh mục tối đa ${MAX_CATEGORY_LENGTH} ký tự.`;
  }

  const price = parseWholeNumber(values.price);
  if (price === undefined) {
    errors.price = 'Giá bán phải là số nguyên đồng, không âm.';
  } else if (price > MAX_PRICE) {
    errors.price = `Giá bán tối đa ${money(MAX_PRICE)}.`;
  }

  const capacity = parseWholeNumber(values.capacity);
  if (capacity === undefined) {
    errors.capacity = 'Số suất chuẩn bị phải là số nguyên, không âm.';
  } else if (capacity > MAX_PORTIONS) {
    errors.capacity = `Số suất chuẩn bị tối đa ${MAX_PORTIONS}.`;
  }

  const stock = parseWholeNumber(values.stock);
  if (stock === undefined) {
    errors.stock = 'Số suất còn lại phải là số nguyên, không âm.';
  } else if (capacity !== undefined && stock > capacity) {
    errors.stock = 'Số suất còn lại không thể nhiều hơn số suất đã chuẩn bị.';
  }

  if (
    Object.keys(errors).length > 0 ||
    price === undefined ||
    stock === undefined ||
    capacity === undefined
  ) {
    return { ok: false, errors };
  }

  return { ok: true, draft: { name, cat, icon: values.icon, price, stock, capacity } };
}
