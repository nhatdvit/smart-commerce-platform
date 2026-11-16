'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { PRODUCTS, findProduct, type Product, type ProductCategory } from '@/data/products';

/** `all` and `promo` are navigation filters, not product categories. */
export type CategoryFilter = 'all' | 'promo' | ProductCategory;

/** Cart key: the product id, plus the variant index when the product has variants. */
export type CartKey = string;
export type CartState = Readonly<Record<CartKey, number>>;

export interface CartLine {
  readonly key: CartKey;
  readonly product: Product;
  readonly quantity: number;
  /** Product name with the chosen variant label appended, as the design shows it. */
  readonly displayName: string;
  /** Unit price of the chosen variant, in integer đồng. */
  readonly unitPrice: number;
  /** The noun after the price: the product unit, or "quy cách" for variant products. */
  readonly unitLabel: string;
}

export interface CartTotals {
  readonly subtotal: number;
  readonly count: number;
  readonly discount: number;
  readonly total: number;
}

export type ModalView =
  | { readonly kind: 'variants'; readonly productId: number }
  | { readonly kind: 'checkout' }
  | { readonly kind: 'rewards' }
  | { readonly kind: 'login' }
  | {
      readonly kind: 'order-placed';
      readonly customerName: string;
      readonly method: 'cash' | 'bank';
      readonly earnedPoints: number;
    };

const CART_KEY = 'pq-v5-cart';
const POINTS_KEY = 'pq-v5-points';
const FAVORITES_KEY = 'pq-v5-favorites';
const STARTING_POINTS = 185;
const COUPON_CODES = ['SANG10', 'GIAM5K'] as const;

export function cartKeyFor(productId: number, variantIndex?: number): CartKey {
  return variantIndex === undefined ? String(productId) : `${productId}_${variantIndex}`;
}

function readStored(key: string): unknown {
  try {
    const raw = window.localStorage.getItem(key);

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

  const cart: Record<CartKey, number> = {};
  for (const [key, quantity] of Object.entries(value as Record<string, unknown>)) {
    if (typeof quantity === 'number' && Number.isFinite(quantity) && quantity > 0) {
      cart[key] = Math.floor(quantity);
    }
  }

  return cart;
}

function asFavorites(value: unknown): readonly number[] {
  return Array.isArray(value) ? value.filter((id): id is number => typeof id === 'number') : [];
}

function asPoints(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0
    ? Math.floor(value)
    : STARTING_POINTS;
}

export function cartLineFor(key: CartKey, quantity: number): CartLine | null {
  const [rawId, rawVariant] = key.split('_');
  const product = findProduct(Number(rawId));
  if (product === undefined) {
    return null;
  }

  const variant = rawVariant === undefined ? undefined : product.variants?.[Number(rawVariant)];

  return {
    key,
    product,
    quantity,
    displayName: variant === undefined ? product.name : `${product.name} · ${variant.label}`,
    unitPrice: variant?.price ?? product.price,
    unitLabel: product.variants === undefined ? product.unit : 'quy cách',
  };
}

function discountFor(code: string, subtotal: number): number {
  if (code === 'SANG10' && subtotal >= 100000) {
    return Math.round(subtotal * 0.1);
  }
  if (code === 'GIAM5K' && subtotal >= 50000) {
    return 5000;
  }

  return 0;
}

/**
 * All storefront interaction state.
 *
 * Cart, coupon and loyalty values live in the browser exactly as the delivered
 * prototype did. They are presentation state only: the server revalidates
 * price, availability and totals at checkout and never trusts these
 * (CLAUDE.md section 6).
 */
export function useStorefront() {
  const [cart, setCart] = useState<CartState>({});
  const [points, setPoints] = useState(STARTING_POINTS);
  const [favorites, setFavorites] = useState<readonly number[]>([]);
  const [hydrated, setHydrated] = useState(false);

  const [coupon, setCoupon] = useState('');
  const [couponInput, setCouponInput] = useState('');
  const [couponMessage, setCouponMessage] = useState('Thử mã SANG10 hoặc GIAM5K');

  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [search, setSearch] = useState('');

  const [modal, setModal] = useState<ModalView | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [toastMessage, setToastMessage] = useState('');
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  // Restore after mount: the server render has no localStorage, so reading it
  // during render would make the first client paint disagree with the markup.
  useEffect(() => {
    setCart(asCart(readStored(CART_KEY)));
    setPoints(asPoints(readStored(POINTS_KEY)));
    setFavorites(asFavorites(readStored(FAVORITES_KEY)));
    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) {
      return;
    }
    try {
      window.localStorage.setItem(CART_KEY, JSON.stringify(cart));
      window.localStorage.setItem(POINTS_KEY, JSON.stringify(points));
      window.localStorage.setItem(FAVORITES_KEY, JSON.stringify(favorites));
    } catch {
      // Storage unavailable: the session keeps working in memory.
    }
  }, [cart, points, favorites, hydrated]);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  const toast = useCallback((message: string) => {
    setToastMessage(message);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMessage(''), 2500);
  }, []);

  const lines = useMemo(
    () =>
      Object.entries(cart)
        .map(([key, quantity]) => cartLineFor(key, quantity))
        .filter((line): line is CartLine => line !== null && line.quantity > 0),
    [cart],
  );

  const totals = useMemo<CartTotals>(() => {
    let subtotal = 0;
    let count = 0;
    for (const line of lines) {
      subtotal += line.unitPrice * line.quantity;
      count += line.quantity;
    }
    const discount = discountFor(coupon, subtotal);

    return { subtotal, count, discount, total: Math.max(0, subtotal - discount) };
  }, [lines, coupon]);

  const addToCart = useCallback(
    (productId: number, variantIndex?: number) => {
      const product = findProduct(productId);
      if (product === undefined) {
        return;
      }
      if (product.variants !== undefined && variantIndex === undefined) {
        setModal({ kind: 'variants', productId });

        return;
      }

      const key = cartKeyFor(productId, variantIndex);
      setCart((current) => ({ ...current, [key]: (current[key] ?? 0) + 1 }));
      toast(`Đã thêm ${product.name} vào giỏ hàng`);
    },
    [toast],
  );

  const changeQuantity = useCallback((key: CartKey, delta: number) => {
    setCart((current) => {
      const next = { ...current };
      const quantity = Math.max(0, (next[key] ?? 0) + delta);
      if (quantity === 0) {
        delete next[key];
      } else {
        next[key] = quantity;
      }

      return next;
    });
  }, []);

  const clearCart = useCallback(() => {
    if (totals.count === 0) {
      return;
    }
    if (!window.confirm('Xóa toàn bộ giỏ hàng?')) {
      return;
    }
    setCart({});
    setCoupon('');
  }, [totals.count]);

  const toggleFavorite = useCallback((productId: number) => {
    setFavorites((current) =>
      current.includes(productId)
        ? current.filter((id) => id !== productId)
        : [...current, productId],
    );
  }, []);

  const applyCoupon = useCallback(
    (rawCode?: string) => {
      const code = (rawCode ?? couponInput).trim().toUpperCase();
      if (rawCode !== undefined) {
        setCouponInput(code);
      }

      if (code === '') {
        setCoupon('');
        setCouponMessage('Đã bỏ mã ưu đãi');

        return;
      }
      if (!COUPON_CODES.some((valid) => valid === code)) {
        setCoupon('');
        setCouponMessage('Mã không hợp lệ');

        return;
      }

      setCoupon(code);
      const applies = discountFor(code, totals.subtotal) > 0;
      setCouponMessage(
        applies ? `Đã áp dụng ${code}` : 'Đã nhập mã, nhưng đơn chưa đủ giá trị tối thiểu',
      );
      toast(applies ? 'Áp dụng ưu đãi thành công' : 'Chưa đủ điều kiện áp dụng');
    },
    [couponInput, totals.subtotal, toast],
  );

  const openCheckout = useCallback(() => {
    if (totals.count === 0) {
      toast('Hãy chọn món trước khi đặt hàng');

      return;
    }
    setDrawerOpen(false);
    setModal({ kind: 'checkout' });
  }, [totals.count, toast]);

  /** Demo order: clears the cart and credits simulated points. No server call. */
  const placeDemoOrder = useCallback(
    (customerName: string, method: 'cash' | 'bank') => {
      const earnedPoints = Math.floor(totals.total / 10000);
      setPoints((current) => current + earnedPoints);
      setCart({});
      setCoupon('');
      setCouponInput('');
      setModal({ kind: 'order-placed', customerName, method, earnedPoints });
      toast('Đã tạo đơn hàng demo');
    },
    [totals.total, toast],
  );

  const redeemReward = useCallback(
    (productId: number, cost: number) => {
      const product = findProduct(productId);
      if (product === undefined || points < cost) {
        return;
      }
      if (!window.confirm(`Đổi ${cost} điểm lấy ${product.name}? (demo)`)) {
        return;
      }
      setPoints((current) => current - cost);
      toast(`Đổi thưởng demo thành công: ${product.name}`);
    },
    [points, toast],
  );

  const closeLayers = useCallback(() => {
    setModal(null);
    setDrawerOpen(false);
  }, []);

  const visibleProducts = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase('vi');

    return PRODUCTS.filter(
      (product) =>
        (categoryFilter === 'all' ||
          categoryFilter === 'promo' ||
          product.category === categoryFilter ||
          (categoryFilter === 'breakfast' && product.category === 'main')) &&
        product.name.toLocaleLowerCase('vi').includes(needle),
    );
  }, [categoryFilter, search]);

  return {
    lines,
    totals,
    points,
    favorites,
    couponInput,
    couponMessage,
    categoryFilter,
    search,
    modal,
    drawerOpen,
    toastMessage,
    visibleProducts,
    setCouponInput,
    setCategoryFilter,
    setSearch,
    setModal,
    setDrawerOpen,
    addToCart,
    changeQuantity,
    clearCart,
    toggleFavorite,
    applyCoupon,
    openCheckout,
    placeDemoOrder,
    redeemReward,
    closeLayers,
    toast,
  };
}

export type Storefront = ReturnType<typeof useStorefront>;
