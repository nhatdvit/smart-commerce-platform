'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

import {
  INITIAL_COUPONS,
  INITIAL_REWARDS,
  type DemoCoupon,
  type DemoReward,
} from '@/data/admin-demo';
import { ApiError, api } from '@/lib/api-client';
import {
  archiveMenuProduct,
  createMenuProduct,
  loadMenu,
  saveMenuProductStock,
  updateMenuProduct,
  type MenuProduct,
  type MenuSnapshot,
} from '@/lib/menu-api';
import {
  DEFAULT_ORDER_QUERY,
  ORDER_STATUS_LABELS,
  loadOrders,
  orderQueryKey,
  transitionOrder,
  type AdminOrder,
  type OrderQuery,
  type OrderStatus,
  type OrderStatusCounts,
} from '@/lib/orders-api';
import type { ProductDraft } from '@/lib/product-form';

export type AdminModal =
  | { readonly kind: 'order'; readonly orderId: string }
  | { readonly kind: 'product'; readonly productId: string }
  | { readonly kind: 'stock'; readonly productId: string }
  | { readonly kind: 'add-coupon' }
  | { readonly kind: 'add-reward' }
  | { readonly kind: 'loyalty-settings' }
  | { readonly kind: 'add-product' }
  | { readonly kind: 'delete-product'; readonly productId: string }
  | { readonly kind: 'coupon-detail'; readonly code: string };

/** Whether the dashboard has a session yet. */
export type SessionState = 'checking' | 'signed-in' | 'signed-out';

interface AdminContextValue {
  readonly orders: readonly AdminOrder[];
  /** Open orders per status, counted by the server across the whole store. */
  readonly orderCounts: OrderStatusCounts;
  readonly products: readonly MenuProduct[];
  readonly coupons: readonly DemoCoupon[];
  readonly rewards: readonly DemoReward[];
  readonly pendingCount: number;
  readonly modal: AdminModal | null;
  readonly toastMessage: string;
  readonly sidebarOpen: boolean;
  readonly session: SessionState;
  /** True while the menu is being loaded or saved, for disabling a submit. */
  readonly menuBusy: boolean;
  /** Set when the menu could not be loaded at all, so a screen can say so. */
  readonly menuError: string;
  readonly ordersBusy: boolean;
  readonly ordersError: string;
  readonly hasMoreOrders: boolean;
  readonly openModal: (modal: AdminModal) => void;
  readonly closeModal: () => void;
  readonly setSidebarOpen: (open: boolean) => void;
  readonly notify: (message: string) => void;
  /** The design's catch-all for controls that are not wired to an API yet. */
  readonly notifyAction: (action: string) => void;
  readonly signIn: (email: string, password: string) => Promise<boolean>;
  readonly signOut: () => void;
  /** Asks for the list a screen shows. Identical repeats are not re-fetched. */
  readonly loadOrders: (query: OrderQuery) => void;
  readonly loadMoreOrders: () => void;
  readonly moveOrder: (orderId: string, toStatus: OrderStatus, reason?: string) => void;
  readonly createProduct: (draft: ProductDraft) => void;
  readonly updateProduct: (productId: string, draft: ProductDraft) => void;
  readonly deleteProduct: (productId: string) => void;
  readonly saveStock: (productId: string, stock: number) => void;
  readonly addCoupon: (code: string) => void;
  readonly addReward: (name: string, points: number) => void;
}

const AdminContext = createContext<AdminContextValue | null>(null);

/**
 * Holds the dashboard's state for the whole admin shell.
 *
 * The menu and the orders are real: both come from the API and survive a
 * reload. Coupons and rewards are still the design's sample data, because
 * those endpoints are designed but not built - each one leaves this file as
 * its module lands.
 *
 * Orders are listed per screen rather than once globally: each screen says
 * what it wants to see through `loadOrders`, and an identical request is not
 * repeated. One default page is loaded on mount anyway, because the sidebar
 * badge counts waiting orders on every screen, not just on the orders one.
 */
export function AdminDataProvider({ children }: { children: ReactNode }) {
  const [coupons, setCoupons] = useState<readonly DemoCoupon[]>(INITIAL_COUPONS);
  const [rewards, setRewards] = useState<readonly DemoReward[]>(INITIAL_REWARDS);

  const [orders, setOrders] = useState<readonly AdminOrder[]>([]);
  const [orderCounts, setOrderCounts] = useState<OrderStatusCounts>({});
  const [orderCursor, setOrderCursor] = useState<string | null>(null);
  const [ordersBusy, setOrdersBusy] = useState(false);
  const [ordersError, setOrdersError] = useState('');

  const [snapshot, setSnapshot] = useState<MenuSnapshot | null>(null);
  const [menuBusy, setMenuBusy] = useState(false);
  const [menuError, setMenuError] = useState('');
  const [session, setSession] = useState<SessionState>('checking');

  const [modal, setModal] = useState<AdminModal | null>(null);
  const [toastMessage, setToastMessage] = useState('');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  /** The query currently on screen, for a refresh after a write. */
  const orderQuery = useRef<OrderQuery>(DEFAULT_ORDER_QUERY);
  /** What is already shown, so an identical request is not sent twice. */
  const shownQuery = useRef<string | null>(null);
  /**
   * The newest list request. A debounced search box can have two in flight,
   * and the slower one must not overwrite the newer answer.
   */
  const listTicket = useRef(0);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  const notify = useCallback((message: string) => {
    setToastMessage(message);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMessage(''), 3200);
  }, []);

  const notifyAction = useCallback(
    (action: string) => {
      notify(`Chức năng ${action} sẽ được kết nối API trong giai đoạn triển khai.`);
    },
    [notify],
  );

  const closeModal = useCallback(() => setModal(null), []);
  const openModal = useCallback((next: AdminModal) => setModal(next), []);

  /** Loads the menu, and decides from the answer whether there is a session. */
  const refreshMenu = useCallback(async (): Promise<void> => {
    setMenuBusy(true);
    try {
      setSnapshot(await loadMenu());
      setMenuError('');
      setSession('signed-in');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession('signed-out');
        setSnapshot(null);
        setMenuError('');

        return;
      }
      setSession('signed-in');
      setMenuError(error instanceof Error ? error.message : 'Không tải được thực đơn.');
    } finally {
      setMenuBusy(false);
    }
  }, []);

  /**
   * Runs one list request.
   *
   * With a cursor it appends the next page; without one it replaces the list.
   * The rows already on screen are left alone until the answer arrives, so
   * changing a filter does not blank the table and then fill it again.
   */
  const runOrderLoad = useCallback(async (query: OrderQuery, cursor?: string): Promise<void> => {
    const ticket = listTicket.current + 1;
    listTicket.current = ticket;
    setOrdersBusy(true);

    try {
      const page = await loadOrders(query, cursor);

      // A newer request has been sent since; its answer is the current one.
      if (ticket !== listTicket.current) {
        return;
      }

      setOrders((current) => (cursor === undefined ? page.orders : [...current, ...page.orders]));
      setOrderCounts(page.counts);
      setOrderCursor(page.nextCursor);
      setOrdersError('');
      setSession('signed-in');
    } catch (error) {
      if (ticket !== listTicket.current) {
        return;
      }

      if (error instanceof ApiError && error.status === 401) {
        setSession('signed-out');
        setOrders([]);
        setOrderCounts({});
        setOrdersError('');

        return;
      }

      setOrdersError(error instanceof Error ? error.message : 'Không tải được danh sách đơn.');
    } finally {
      if (ticket === listTicket.current) {
        setOrdersBusy(false);
      }
    }
  }, []);

  const requestOrders = useCallback(
    (query: OrderQuery): void => {
      const key = orderQueryKey(query);

      // Already showing exactly this list: a screen re-mounting is not a
      // reason to ask the server again.
      if (key === shownQuery.current) {
        return;
      }

      shownQuery.current = key;
      orderQuery.current = query;
      void runOrderLoad(query);
    },
    [runOrderLoad],
  );

  const loadMoreOrders = useCallback((): void => {
    if (orderCursor === null) {
      return;
    }

    void runOrderLoad(orderQuery.current, orderCursor);
  }, [orderCursor, runOrderLoad]);

  useEffect(() => {
    void refreshMenu();
    // The badge counts waiting orders on every screen, so one page is loaded
    // up front; a screen that wants a different list asks for it.
    requestOrders(DEFAULT_ORDER_QUERY);
  }, [refreshMenu, requestOrders]);

  /**
   * Runs one menu write.
   *
   * Everything a write can go wrong with is handled in one place: a 422 shows
   * the server's own message, because the server is the authority on what is
   * valid, and a lost session sends the operator back to the sign-in panel
   * rather than silently dropping their edit.
   */
  const runMenuWrite = useCallback(
    async (
      operation: (current: MenuSnapshot) => Promise<MenuSnapshot>,
      success: string,
    ): Promise<void> => {
      if (snapshot === null) {
        notify('Thực đơn chưa tải xong, vui lòng thử lại.');

        return;
      }

      setMenuBusy(true);
      try {
        setSnapshot(await operation(snapshot));
        setModal(null);
        notify(success);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }
        notify(error instanceof Error ? error.message : 'Không lưu được thay đổi.');
      } finally {
        setMenuBusy(false);
      }
    },
    [snapshot, notify],
  );

  const signIn = useCallback(
    async (email: string, password: string): Promise<boolean> => {
      try {
        await api.post('/auth/login', { email, password });
        await refreshMenu();
        // The session is new, so nothing on screen belongs to it yet.
        shownQuery.current = null;
        requestOrders(DEFAULT_ORDER_QUERY);

        return true;
      } catch (error) {
        // The API answers a wrong password and an unknown address alike, and
        // so does this: the message is the server's.
        notify(error instanceof Error ? error.message : 'Không đăng nhập được.');

        return false;
      }
    },
    [notify, refreshMenu, requestOrders],
  );

  const signOut = useCallback(() => {
    void api
      .post('/auth/logout')
      .catch(() => undefined)
      .finally(() => {
        setSession('signed-out');
        setSnapshot(null);
        setOrders([]);
        setOrderCounts({});
        shownQuery.current = null;
        setModal(null);
      });
  }, []);

  /**
   * Moves one order to the status the operator chose.
   *
   * The target status is sent, not "next": the server validates it against the
   * committed status under a row lock, so two staff members tapping at once
   * get a conflict they can see rather than an order two steps along. A
   * conflict reloads the list, because the screen was out of date and showing
   * it again is the useful response.
   */
  const moveOrder = useCallback(
    (orderId: string, toStatus: OrderStatus, reason?: string): void => {
      setOrdersBusy(true);

      void transitionOrder(orderId, toStatus, reason)
        .then((updated) => {
          setModal(null);
          notify(`Đơn ${updated.code}: ${ORDER_STATUS_LABELS[updated.status]}`);
          // Re-read rather than patch the row: the move can take the order out
          // of the current filter, and it always changes the open counts.
          void runOrderLoad(orderQuery.current);
        })
        .catch((error: unknown) => {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }

          notify(error instanceof Error ? error.message : 'Không cập nhật được đơn hàng.');

          if (error instanceof ApiError && error.status === 409) {
            setModal(null);
            void runOrderLoad(orderQuery.current);
          }
        })
        .finally(() => setOrdersBusy(false));
    },
    [notify, runOrderLoad],
  );

  /**
   * Adds a dish. The draft is already valid - `validateProductForm` owns the
   * client-side rules - and the server revalidates every one of them.
   */
  const createProduct = useCallback(
    (draft: ProductDraft) => {
      void runMenuWrite(
        (current) => createMenuProduct(current, draft),
        `Đã thêm "${draft.name}" vào thực đơn`,
      );
    },
    [runMenuWrite],
  );

  const updateProduct = useCallback(
    (productId: string, draft: ProductDraft) => {
      void runMenuWrite(
        (current) => updateMenuProduct(current, productId, draft),
        `Đã lưu "${draft.name}"`,
      );
    },
    [runMenuWrite],
  );

  const deleteProduct = useCallback(
    (productId: string) => {
      // Read the name before the write, for the toast.
      const removed = snapshot?.products.find((product) => product.id === productId);
      if (removed === undefined) {
        return;
      }

      void runMenuWrite(
        (current) => archiveMenuProduct(current, productId),
        `Đã ngừng bán "${removed.name}"`,
      );
    },
    [snapshot, runMenuWrite],
  );

  const saveStock = useCallback(
    (productId: string, stock: number) => {
      if (!Number.isInteger(stock) || stock < 0) {
        notify('Số suất không hợp lệ');

        return;
      }

      void runMenuWrite(
        (current) => saveMenuProductStock(current, productId, stock),
        'Đã cập nhật số suất',
      );
    },
    [notify, runMenuWrite],
  );

  const addCoupon = useCallback(
    (rawCode: string) => {
      const code = rawCode
        .trim()
        .toUpperCase()
        .replace(/[^A-Z0-9_-]/g, '');
      if (code === '' || coupons.some((coupon) => coupon.code === code)) {
        notify('Mã không hợp lệ hoặc đã tồn tại');

        return;
      }
      setCoupons((current) => [
        ...current,
        { code, desc: 'Giảm 5.000đ · Đơn từ 50.000đ', used: 0, limit: 100 },
      ]);
      setModal(null);
      notify(`Đã tạo mã demo ${code}`);
    },
    [coupons, notify],
  );

  const addReward = useCallback(
    (rawName: string, points: number) => {
      const name = rawName.trim();
      if (name === '' || !Number.isInteger(points) || points <= 0) {
        notify('Vui lòng nhập món và điểm hợp lệ');

        return;
      }
      setRewards((current) => [...current, { name, points, icon: 'gift', claimed: 0 }]);
      setModal(null);
      notify('Đã thêm quà demo');
    },
    [notify],
  );

  /**
   * Orders waiting for someone to act: a transfer not yet verified, or an
   * order the store has not acknowledged.
   *
   * Taken from the server's counts, not from the loaded page, so the badge is
   * the real number even when the screen is showing one filtered page.
   */
  const pendingCount = useMemo(
    () => (orderCounts.PENDING_PAYMENT ?? 0) + (orderCounts.PLACED ?? 0),
    [orderCounts],
  );

  const products = useMemo<readonly MenuProduct[]>(() => snapshot?.products ?? [], [snapshot]);

  const value = useMemo<AdminContextValue>(
    () => ({
      orders,
      orderCounts,
      products,
      coupons,
      rewards,
      pendingCount,
      modal,
      toastMessage,
      sidebarOpen,
      session,
      menuBusy,
      menuError,
      ordersBusy,
      ordersError,
      hasMoreOrders: orderCursor !== null,
      openModal,
      closeModal,
      setSidebarOpen,
      notify,
      notifyAction,
      signIn,
      signOut,
      loadOrders: requestOrders,
      loadMoreOrders,
      moveOrder,
      createProduct,
      updateProduct,
      deleteProduct,
      saveStock,
      addCoupon,
      addReward,
    }),
    [
      orders,
      orderCounts,
      products,
      coupons,
      rewards,
      pendingCount,
      modal,
      toastMessage,
      sidebarOpen,
      session,
      menuBusy,
      menuError,
      ordersBusy,
      ordersError,
      orderCursor,
      openModal,
      closeModal,
      notify,
      notifyAction,
      signIn,
      signOut,
      requestOrders,
      loadMoreOrders,
      moveOrder,
      createProduct,
      updateProduct,
      deleteProduct,
      saveStock,
      addCoupon,
      addReward,
    ],
  );

  return <AdminContext.Provider value={value}>{children}</AdminContext.Provider>;
}

export function useAdminData(): AdminContextValue {
  const value = useContext(AdminContext);
  if (value === null) {
    throw new Error('useAdminData must be used inside AdminDataProvider');
  }

  return value;
}
