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
  INITIAL_PRODUCTS,
  INITIAL_REWARDS,
  nextOrderStatus,
  type DemoCoupon,
  type DemoOrder,
  type DemoProduct,
  type DemoReward,
} from '@/data/admin-demo';
import type { ProductDraft } from '@/lib/product-form';

export type AdminModal =
  | { readonly kind: 'order'; readonly orderId: string }
  | { readonly kind: 'product'; readonly productId: number }
  | { readonly kind: 'stock'; readonly productId: number }
  | { readonly kind: 'add-coupon' }
  | { readonly kind: 'add-reward' }
  | { readonly kind: 'loyalty-settings' }
  | { readonly kind: 'add-product' }
  | { readonly kind: 'delete-product'; readonly productId: number }
  | { readonly kind: 'coupon-detail'; readonly code: string };

interface AdminContextValue {
  readonly orders: readonly DemoOrder[];
  readonly products: readonly DemoProduct[];
  readonly coupons: readonly DemoCoupon[];
  readonly rewards: readonly DemoReward[];
  readonly pendingCount: number;
  readonly modal: AdminModal | null;
  readonly toastMessage: string;
  readonly sidebarOpen: boolean;
  readonly openModal: (modal: AdminModal) => void;
  readonly closeModal: () => void;
  readonly setSidebarOpen: (open: boolean) => void;
  readonly notify: (message: string) => void;
  /** The design's catch-all for controls that are not wired to an API yet. */
  readonly notifyAction: (action: string) => void;
  readonly advanceOrder: (orderId: string) => void;
  readonly createProduct: (draft: ProductDraft) => void;
  readonly updateProduct: (productId: number, draft: ProductDraft) => void;
  readonly deleteProduct: (productId: number) => void;
  readonly saveStock: (productId: number, stock: number) => void;
  readonly addCoupon: (code: string) => void;
  readonly addReward: (name: string, points: number) => void;
}

const AdminContext = createContext<AdminContextValue | null>(null);

/**
 * Holds the dashboard's in-memory demo state for the whole admin shell.
 *
 * It lives above the router so edits survive navigation between the admin
 * screens, and resets on reload - the delivered prototype behaved the same
 * way, and nothing here is persisted or sent anywhere.
 */
export function AdminDataProvider({ children }: { children: ReactNode }) {
  const [orders, setOrders] = useState<readonly DemoOrder[]>(INITIAL_ORDERS);
  const [products, setProducts] = useState<readonly DemoProduct[]>(INITIAL_PRODUCTS);
  const [coupons, setCoupons] = useState<readonly DemoCoupon[]>(INITIAL_COUPONS);
  const [rewards, setRewards] = useState<readonly DemoReward[]>(INITIAL_REWARDS);

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
   * Adds a menu item. The draft is already valid - `validateProductForm`
   * owns the rules, including the duplicate-name check, so this only has to
   * mint an id and store it.
   */
  const createProduct = useCallback(
    (draft: ProductDraft) => {
      setProducts((current) => [
        ...current,
        // Max+1 rather than length+1: deleting an item would otherwise hand
        // the next one an id that is still in use.
        { ...draft, id: current.reduce((top, product) => Math.max(top, product.id), 0) + 1 },
      ]);
      setModal(null);
      notify(`Đã thêm "${draft.name}" vào thực đơn (dữ liệu demo)`);
    },
    [notify],
  );

  const updateProduct = useCallback(
    (productId: number, draft: ProductDraft) => {
      setProducts((current) =>
        current.map((product) => (product.id === productId ? { ...product, ...draft } : product)),
      );
      setModal(null);
      notify(`Đã lưu "${draft.name}" (dữ liệu demo)`);
    },
    [notify],
  );

  const deleteProduct = useCallback(
    (productId: number) => {
      // Read the name before the state updater runs, for the toast.
      const removed = products.find((product) => product.id === productId);
      if (removed === undefined) {
        return;
      }
      setProducts((current) => current.filter((product) => product.id !== productId));
      setModal(null);
      notify(`Đã xoá "${removed.name}" khỏi thực đơn (dữ liệu demo)`);
    },
    [products, notify],
  );

  const saveStock = useCallback(
    (productId: number, stock: number) => {
      if (!Number.isInteger(stock) || stock < 0) {
        notify('Số suất không hợp lệ');

        return;
      }
      setProducts((current) =>
        current.map((product) =>
          product.id === productId
            ? { ...product, stock, capacity: Math.max(product.capacity, stock) }
            : product,
        ),
      );
      setModal(null);
      notify('Đã cập nhật số suất (dữ liệu demo)');
    },
    [notify],
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
      openModal,
      closeModal,
      setSidebarOpen,
      notify,
      notifyAction,
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
      openModal,
      closeModal,
      notify,
      notifyAction,
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
