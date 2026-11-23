'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { apiErrorMessage as authErrorMessage } from '@/lib/api-error-message';
import {
  type CurrentCustomer,
  type LoyaltyStanding,
  changeOwnPassword,
  getCurrentCustomer,
  getOwnLoyalty,
  loginCustomer,
  logoutCustomer,
  registerCustomer,
  updateOwnProfile,
} from '@/lib/auth-api';
import { type CartState, readCart, writeCart } from '@/lib/cart-storage';
import {
  MenuApiError,
  groupIntoCards,
  loadTodayMenu,
  type MenuItem,
  type MenuProductCard,
} from '@/lib/menu-api';
import {
  type FulfillmentType as OrderFulfillmentType,
  type OrderConfirmation,
  type PaymentMethod as OrderPaymentMethod,
  createOrder,
  newIdempotencyKey,
} from '@/lib/order-api';

/**
 * Which section of the menu is showing.
 *
 * `'all'` and `'promo'` are reserved navigation values, not real categories;
 * any other string is a category id. One alias rather than a union, because a
 * category id is itself a string and TypeScript collapses `'all' | string` to
 * `string` anyway.
 */
export type CategoryFilter = string;

/** Cart key: the menu item id - the exact sellable unit a cart line references. */
export type CartKey = string;
export type { CartState };

export interface CartLine {
  readonly key: CartKey;
  readonly item: MenuItem;
  readonly quantity: number;
  /** Product name with the chosen variant appended, when the dish has more than one. */
  readonly displayName: string;
}

export interface CartTotals {
  readonly subtotal: number;
  readonly count: number;
  readonly discount: number;
  readonly total: number;
}

export type ModalView =
  | { readonly kind: 'variants'; readonly productId: string }
  | { readonly kind: 'checkout' }
  | { readonly kind: 'rewards' }
  | { readonly kind: 'login' }
  | { readonly kind: 'profile' }
  | { readonly kind: 'order-confirmed'; readonly confirmation: OrderConfirmation };

const FAVORITES_KEY = 'pq-v5-favorites';
const COUPON_CODES = ['SANG10', 'GIAM5K'] as const;

function readStored(key: string): unknown {
  try {
    const raw = window.localStorage.getItem(key);

    return raw === null ? undefined : (JSON.parse(raw) as unknown);
  } catch {
    // A private window, blocked site data or a corrupted value: fall back to defaults.
    return undefined;
  }
}

function asFavorites(value: unknown): readonly string[] {
  return Array.isArray(value) ? value.filter((id): id is string => typeof id === 'string') : [];
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
 * The menu, checkout and a signed-in customer's own profile/loyalty are
 * real. The cart itself (which lines, what quantity) stays in the browser -
 * there is no persisted Cart module yet - and the demo coupon still applies
 * only to the number shown here, never to what checkout actually charges:
 * the server reprices every line from the live menu and knows nothing of a
 * coupon yet (CLAUDE.md section 6, and `CreateOrder`'s own doc comment on
 * the backend).
 */
export function useStorefront() {
  const [items, setItems] = useState<readonly MenuItem[]>([]);
  const [menuBusy, setMenuBusy] = useState(true);
  const [menuError, setMenuError] = useState('');

  useEffect(() => {
    let cancelled = false;

    setMenuBusy(true);
    loadTodayMenu()
      .then((menu) => {
        if (cancelled) {
          return;
        }
        setItems(menu?.items ?? []);
        setMenuError('');
      })
      .catch((error: unknown) => {
        if (cancelled) {
          return;
        }
        setMenuError(error instanceof MenuApiError ? error.message : 'Không tải được thực đơn.');
      })
      .finally(() => {
        if (!cancelled) {
          setMenuBusy(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const cards = useMemo(() => groupIntoCards(items), [items]);
  const itemsById = useMemo(() => new Map(items.map((item) => [item.id, item])), [items]);
  const cardsByProductId = useMemo(
    () => new Map(cards.map((card) => [card.productId, card])),
    [cards],
  );

  const categories = useMemo(() => {
    const seen = new Map<
      string,
      { readonly id: string; readonly name: string; readonly slug: string }
    >();

    for (const card of cards) {
      if (card.category !== null && !seen.has(card.category.id)) {
        seen.set(card.category.id, card.category);
      }
    }

    return Array.from(seen.values());
  }, [cards]);

  const [currentCustomer, setCurrentCustomer] = useState<CurrentCustomer | null>(null);
  const [authBusy, setAuthBusy] = useState(false);
  const [authError, setAuthError] = useState('');
  const [profileBusy, setProfileBusy] = useState(false);
  const [profileError, setProfileError] = useState('');
  const [passwordBusy, setPasswordBusy] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [loyaltyBalance, setLoyaltyBalance] = useState<LoyaltyStanding | null>(null);

  // Whether anyone is already signed in, revalidated against the session
  // cookie rather than assumed from anything stored in the browser.
  useEffect(() => {
    let cancelled = false;

    getCurrentCustomer()
      .then((customer) => {
        if (!cancelled) {
          setCurrentCustomer(customer);
        }
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, []);

  // The real points/coupon panel only makes sense for a signed-in customer -
  // fetched fresh whenever that changes, never assumed to persist across one.
  useEffect(() => {
    if (currentCustomer === null) {
      setLoyaltyBalance(null);

      return;
    }

    let cancelled = false;

    getOwnLoyalty()
      .then((standing) => {
        if (!cancelled) {
          setLoyaltyBalance(standing);
        }
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
    };
  }, [currentCustomer]);

  const [cart, setCart] = useState<CartState>({});
  const [favorites, setFavorites] = useState<readonly string[]>([]);
  const [hydrated, setHydrated] = useState(false);

  const [coupon, setCoupon] = useState('');
  const [couponInput, setCouponInput] = useState('');
  const [couponMessage, setCouponMessage] = useState('Thử mã SANG10 hoặc GIAM5K');

  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all');
  const [search, setSearch] = useState('');

  const [modal, setModal] = useState<ModalView | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [checkoutBusy, setCheckoutBusy] = useState(false);
  const [checkoutError, setCheckoutError] = useState('');
  const [toastMessage, setToastMessage] = useState('');
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  // Restore after mount: the server render has no localStorage, so reading it
  // during render would make the first client paint disagree with the markup.
  useEffect(() => {
    setCart(readCart());
    setFavorites(asFavorites(readStored(FAVORITES_KEY)));
    setHydrated(true);
  }, []);

  useEffect(() => {
    if (!hydrated) {
      return;
    }
    writeCart(cart);
    try {
      window.localStorage.setItem(FAVORITES_KEY, JSON.stringify(favorites));
    } catch {
      // Storage unavailable: the session keeps working in memory.
    }
  }, [cart, favorites, hydrated]);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  const toast = useCallback((message: string) => {
    setToastMessage(message);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMessage(''), 2500);
  }, []);

  const lines = useMemo<readonly CartLine[]>(() => {
    const result: CartLine[] = [];

    for (const [key, quantity] of Object.entries(cart)) {
      const item = itemsById.get(key);

      if (item === undefined || quantity <= 0) {
        continue;
      }

      const card = cardsByProductId.get(item.productId);
      const displayName =
        card === undefined || card.options.length <= 1
          ? item.productName
          : `${item.productName} · ${item.variantName}`;

      result.push({ key, item, quantity, displayName });
    }

    return result;
  }, [cart, itemsById, cardsByProductId]);

  const totals = useMemo<CartTotals>(() => {
    let subtotal = 0;
    let count = 0;
    for (const line of lines) {
      subtotal += line.item.price * line.quantity;
      count += line.quantity;
    }
    const discount = discountFor(coupon, subtotal);

    return { subtotal, count, discount, total: Math.max(0, subtotal - discount) };
  }, [lines, coupon]);

  /** Adds a specific sellable variant directly - what the variant picker calls once a choice is made. */
  const addMenuItem = useCallback(
    (menuItemId: string) => {
      const item = itemsById.get(menuItemId);

      if (item === undefined) {
        return;
      }

      setCart((current) => ({ ...current, [menuItemId]: (current[menuItemId] ?? 0) + 1 }));
      toast(`Đã thêm ${item.productName} vào giỏ hàng`);
    },
    [itemsById, toast],
  );

  /** Adds a dish card: straight to the cart with one option, or opens the picker with more than one. */
  const addToCart = useCallback(
    (card: MenuProductCard) => {
      const [onlyOption] = card.options;

      if (onlyOption === undefined) {
        return;
      }
      if (card.options.length === 1) {
        addMenuItem(onlyOption.id);

        return;
      }
      setModal({ kind: 'variants', productId: card.productId });
    },
    [addMenuItem],
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

  const toggleFavorite = useCallback((productId: string) => {
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

  /**
   * Real checkout: `POST /orders`. The server reprices every line from the
   * live menu itself (CLAUDE.md section 6) - `expectedTotalAmount` is only
   * the subtotal, matching what the server will compute with no discount,
   * delivery fee or tax applied yet, so a stale cart surfaces as
   * PRICE_CHANGED rather than silently charging a different total. The
   * demo coupon above is not sent: there is no coupon-pricing action to
   * call yet (see CreateOrder's own doc comment on the backend).
   */
  const placeOrder = useCallback(
    async (
      contactName: string,
      contactPhone: string,
      fulfillmentType: OrderFulfillmentType,
      paymentMethod: OrderPaymentMethod,
      deliveryAddressLine?: string,
    ) => {
      setCheckoutBusy(true);
      setCheckoutError('');
      try {
        const confirmation = await createOrder(
          {
            fulfillmentType,
            paymentMethod,
            contactName,
            contactPhone,
            deliveryAddressLine,
            items: lines.map((line) => ({ menuItemId: line.key, quantity: line.quantity })),
            expectedTotalAmount: totals.subtotal,
          },
          newIdempotencyKey(),
        );

        setCart({});
        setCoupon('');
        setCouponInput('');
        setModal({ kind: 'order-confirmed', confirmation });
        toast('Đặt hàng thành công!');
      } catch (error) {
        setCheckoutError(authErrorMessage(error));
      } finally {
        setCheckoutBusy(false);
      }
    },
    [lines, totals.subtotal, toast],
  );

  /** Demo redemption: a local-only preview against the real balance, never sent to the server. */
  const redeemReward = useCallback(
    (name: string, cost: number) => {
      if (loyaltyBalance === null || loyaltyBalance.balance < cost) {
        return;
      }
      if (!window.confirm(`Đổi ${cost} điểm lấy ${name}? (demo)`)) {
        return;
      }
      setLoyaltyBalance((current) =>
        current === null ? current : { ...current, balance: current.balance - cost },
      );
      toast(`Đổi thưởng demo thành công: ${name}`);
    },
    [loyaltyBalance, toast],
  );

  const updateProfile = useCallback(
    async (name: string) => {
      setProfileBusy(true);
      setProfileError('');
      try {
        const customer = await updateOwnProfile(name);
        setCurrentCustomer(customer);
        toast('Đã cập nhật hồ sơ');
      } catch (error) {
        setProfileError(authErrorMessage(error));
      } finally {
        setProfileBusy(false);
      }
    },
    [toast],
  );

  const changePassword = useCallback(
    async (currentPassword: string, newPassword: string) => {
      setPasswordBusy(true);
      setPasswordError('');
      try {
        await changeOwnPassword(currentPassword, newPassword);
        toast('Đã đổi mật khẩu');
      } catch (error) {
        setPasswordError(authErrorMessage(error));
      } finally {
        setPasswordBusy(false);
      }
    },
    [toast],
  );

  const login = useCallback(
    async (phone: string, password: string) => {
      setAuthBusy(true);
      setAuthError('');
      try {
        const customer = await loginCustomer(phone, password);
        setCurrentCustomer(customer);
        setModal(null);
        toast(`Xin chào, ${customer.name}!`);
      } catch (error) {
        setAuthError(authErrorMessage(error));
      } finally {
        setAuthBusy(false);
      }
    },
    [toast],
  );

  const register = useCallback(
    async (fullName: string, phone: string, password: string) => {
      setAuthBusy(true);
      setAuthError('');
      try {
        const customer = await registerCustomer(fullName, phone, password);
        setCurrentCustomer(customer);
        setModal(null);
        toast(`Chào mừng ${customer.name} đến với Bếp nhà mình!`);
      } catch (error) {
        setAuthError(authErrorMessage(error));
      } finally {
        setAuthBusy(false);
      }
    },
    [toast],
  );

  const logout = useCallback(() => {
    logoutCustomer()
      .catch(() => undefined)
      .finally(() => {
        setCurrentCustomer(null);
        setModal(null);
        toast('Đã đăng xuất');
      });
  }, [toast]);

  const closeLayers = useCallback(() => {
    setModal(null);
    setDrawerOpen(false);
    setAuthError('');
    setProfileError('');
    setPasswordError('');
    setCheckoutError('');
  }, []);

  const visibleCards = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase('vi');

    return cards.filter(
      (card) =>
        (categoryFilter === 'all' ||
          categoryFilter === 'promo' ||
          card.category?.id === categoryFilter) &&
        card.name.toLocaleLowerCase('vi').includes(needle),
    );
  }, [cards, categoryFilter, search]);

  return {
    menuBusy,
    menuError,
    categories,
    cardsByProductId,
    lines,
    totals,
    favorites,
    couponInput,
    couponMessage,
    categoryFilter,
    search,
    modal,
    drawerOpen,
    toastMessage,
    visibleCards,
    currentCustomer,
    authBusy,
    authError,
    profileBusy,
    profileError,
    passwordBusy,
    passwordError,
    loyaltyBalance,
    checkoutBusy,
    checkoutError,
    setCouponInput,
    setCategoryFilter,
    setSearch,
    setModal,
    setDrawerOpen,
    addToCart,
    addMenuItem,
    changeQuantity,
    clearCart,
    toggleFavorite,
    applyCoupon,
    openCheckout,
    placeOrder,
    redeemReward,
    closeLayers,
    toast,
    login,
    register,
    logout,
    updateProfile,
    changePassword,
  };
}

export type Storefront = ReturnType<typeof useStorefront>;
