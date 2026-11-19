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
  INITIAL_ORDERS,
  INITIAL_REWARDS,
  nextOrderStatus,
  type DemoCoupon,
  type DemoOrder,
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
  readonly orders: readonly DemoOrder[];
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
  readonly openModal: (modal: AdminModal) => void;
  readonly closeModal: () => void;
  readonly setSidebarOpen: (open: boolean) => void;
  readonly notify: (message: string) => void;
  /** The design's catch-all for controls that are not wired to an API yet. */
  readonly notifyAction: (action: string) => void;
  readonly signIn: (email: string, password: string) => Promise<boolean>;
  readonly signOut: () => void;
  readonly advanceOrder: (orderId: string) => void;
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
 * The menu is real: products, prices and portions come from the API and
 * survive a reload. Orders, coupons and rewards are still the design's sample
 * data, because those endpoints are designed but not built - each one leaves
 * this file as its module lands.
 *
 * The four menu operations keep the shape the screens already called
 * (`createProduct`, `updateProduct`, `deleteProduct`, `saveStock`): they
 * return nothing and report through the toast, so a dialog stays unaware of
 * whether it is talking to memory or to PostgreSQL.
 */
export function AdminDataProvider({ children }: { children: ReactNode }) {
  const [orders, setOrders] = useState<readonly DemoOrder[]>(INITIAL_ORDERS);
  const [coupons, setCoupons] = useState<readonly DemoCoupon[]>(INITIAL_COUPONS);
  const [rewards, setRewards] = useState<readonly DemoReward[]>(INITIAL_REWARDS);

  const [snapshot, setSnapshot] = useState<MenuSnapshot | null>(null);
  const [menuBusy, setMenuBusy] = useState(false);
  const [menuError, setMenuError] = useState('');
  const [session, setSession] = useState<SessionState>('checking');

  const [modal, setModal] = useState<AdminModal | null>(null);
  const [toastMessage, setToastMessage] = useState('');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

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

  useEffect(() => {
    void refreshMenu();
  }, [refreshMenu]);

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

        return true;
      } catch (error) {
        // The API answers a wrong password and an unknown address alike, and
        // so does this: the message is the server's.
        notify(error instanceof Error ? error.message : 'Không đăng nhập được.');

        return false;
      }
    },
    [notify, refreshMenu],
  );

  const signOut = useCallback(() => {
    void api
      .post('/auth/logout')
      .catch(() => undefined)
      .finally(() => {
        setSession('signed-out');
        setSnapshot(null);
        setModal(null);
      });
  }, []);

  const advanceOrder = useCallback(
    (orderId: string) => {
      // Decide from the rendered orders, not from inside the state updater:
      // React runs the updater later, so a flag set in there is still false here.
      const order = orders.find((candidate) => candidate.id === orderId);
      const next = order === undefined ? undefined : nextOrderStatus(order.status);
      if (next === undefined) {
        return;
      }

      setOrders((current) =>
        current.map((candidate) =>
          candidate.id === orderId ? { ...candidate, status: next } : candidate,
        ),
      );
      notify(`Đã cập nhật trạng thái đơn ${orderId}`);
      setModal(null);
    },
    [orders, notify],
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

  const pendingCount = useMemo(
    () => orders.filter((order) => order.status === 'waiting').length,
    [orders],
  );

  const products = useMemo<readonly MenuProduct[]>(() => snapshot?.products ?? [], [snapshot]);

  const value = useMemo<AdminContextValue>(
    () => ({
      orders,
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
      openModal,
      closeModal,
      setSidebarOpen,
      notify,
      notifyAction,
      signIn,
      signOut,
      advanceOrder,
      createProduct,
      updateProduct,
      deleteProduct,
      saveStock,
      addCoupon,
      addReward,
    }),
    [
      orders,
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
      openModal,
      closeModal,
      notify,
      notifyAction,
      signIn,
      signOut,
      advanceOrder,
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
