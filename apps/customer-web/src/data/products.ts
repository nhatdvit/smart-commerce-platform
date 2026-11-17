/**
 * Demo catalogue for the storefront design.
 *
 * Phase 01 has no catalogue endpoint yet - `GET /api/v1/products` is designed
 * but not built - so the screens render this static sample. It mirrors the
 * delivered design one for one and is the single place to replace once the
 * Catalog module exposes real data.
 */

export type ProductCategory = 'breakfast' | 'special' | 'main' | 'drink' | 'combo' | 'snack';

/** A packaged weight for the house-made chả products: 250g / 500g / 1kg. */
export interface ProductVariant {
  readonly label: string;
  /** Integer đồng. */
  readonly price: number;
}

export interface Product {
  readonly id: number;
  readonly name: string;
  /** Integer đồng; the base price when the product has no variants. */
  readonly price: number;
  readonly category: ProductCategory;
  /** Index into `/assets/food-<n>.jpg`. */
  readonly image: number;
  readonly unit: string;
  readonly variants?: readonly ProductVariant[];
}

export const PRODUCTS: readonly Product[] = [
  {
    id: 1,
    name: 'Bánh mì pate trứng',
    price: 20000,
    category: 'breakfast',
    image: 1,
    unit: 'phần',
  },
  {
    id: 2,
    name: 'Bánh mì pate xá xíu',
    price: 28000,
    category: 'breakfast',
    image: 2,
    unit: 'phần',
  },
  { id: 3, name: 'Bún bò Huế', price: 45000, category: 'main', image: 3, unit: 'tô' },
  { id: 4, name: 'Xôi gà xé', price: 30000, category: 'breakfast', image: 4, unit: 'phần' },
  { id: 5, name: 'Cà phê sữa đá', price: 18000, category: 'drink', image: 5, unit: 'ly' },
  { id: 6, name: 'Trà tắc mật ong', price: 22000, category: 'drink', image: 6, unit: 'ly' },
  {
    id: 7,
    name: 'Chả ốc',
    price: 250000,
    category: 'special',
    image: 7,
    unit: 'kg',
    variants: [
      { label: '250g', price: 62500 },
      { label: '500g', price: 125000 },
      { label: '1kg', price: 250000 },
    ],
  },
  {
    id: 8,
    name: 'Chả tôm',
    price: 280000,
    category: 'special',
    image: 8,
    unit: 'kg',
    variants: [
      { label: '250g', price: 70000 },
      { label: '500g', price: 140000 },
      { label: '1kg', price: 280000 },
    ],
  },
  {
    id: 9,
    name: 'Chả mực',
    price: 320000,
    category: 'special',
    image: 9,
    unit: 'kg',
    variants: [
      { label: '250g', price: 80000 },
      { label: '500g', price: 160000 },
      { label: '1kg', price: 320000 },
    ],
  },
  {
    id: 10,
    name: 'Chả cá thát lát',
    price: 260000,
    category: 'special',
    image: 10,
    unit: 'kg',
    variants: [
      { label: '250g', price: 65000 },
      { label: '500g', price: 130000 },
      { label: '1kg', price: 260000 },
    ],
  },
  {
    id: 11,
    name: 'Chả cua',
    price: 300000,
    category: 'special',
    image: 11,
    unit: 'kg',
    variants: [
      { label: '250g', price: 75000 },
      { label: '500g', price: 150000 },
      { label: '1kg', price: 300000 },
    ],
  },
  {
    id: 12,
    name: 'Chả cốm',
    price: 230000,
    category: 'special',
    image: 12,
    unit: 'kg',
    variants: [
      { label: '250g', price: 57500 },
      { label: '500g', price: 115000 },
      { label: '1kg', price: 230000 },
    ],
  },
  {
    id: 13,
    name: 'Combo bánh mì + cà phê',
    price: 35000,
    category: 'combo',
    image: 2,
    unit: 'combo',
  },
  { id: 14, name: 'Bánh mì gà nướng', price: 25000, category: 'breakfast', image: 1, unit: 'phần' },
  { id: 15, name: 'Chả ốc chiên ăn liền', price: 45000, category: 'snack', image: 7, unit: 'phần' },
];

export function findProduct(id: number): Product | undefined {
  return PRODUCTS.find((product) => product.id === id);
}

/** Demo product photo path. Replace with Storage-backed media per CLAUDE.md section 2. */
export function productImage(product: Product): string {
  return `/assets/food-${product.image}.jpg`;
}
