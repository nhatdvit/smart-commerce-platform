'use client';

import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import Link from 'next/link';

import { apiErrorMessage } from '@/lib/api-error-message';
import {
  getCurrentCustomer,
  getOwnLoyalty,
  logoutCustomer,
  type CurrentCustomer,
  type LoyaltyStanding,
} from '@/lib/auth-api';
import { addToStoredCart } from '@/lib/cart-storage';
import { isSellable, loadTodayMenu, type MenuItem } from '@/lib/menu-api';
import { money } from '@/lib/money';
import {
  cancelOrder,
  getOrder,
  getOrderPayment,
  listMyOrders,
  type MyOrderSummary,
  type OrderPaymentInfo,
} from '@/lib/order-api';
import { formatOrderDate, ORDER_STATUS_LABELS } from '@/lib/order-status-labels';

import {
  FoodIcon,
  IconBox,
  IconCalendar,
  IconCheckCircle,
  IconChevronRight,
  IconClock,
  IconGift,
  IconGrid,
  IconLogout,
  IconMapPin,
  IconMedal,
  IconQrCode,
  IconRefresh,
  IconSearch,
  IconSort,
  IconStar,
  IconTag,
  IconUser,
  IconXCircle,
} from './icons';
import { BankTransferPanel, TransferReportForm } from './payment-widgets';

/** Mirrors `CancelOrder::CUSTOMER_CANCELLABLE` on the backend - a hint for the button, not the rule itself: the server is the one source of truth. */
const CUSTOMER_CANCELLABLE = new Set(['PENDING_PAYMENT', 'PLACED', 'CONFIRMED']);

type TabGroup = 'all' | 'pending' | 'confirmed' | 'completed' | 'cancelled';

const PENDING_STATUSES = new Set(['PENDING_PAYMENT', 'PLACED']);
const CONFIRMED_STATUSES = new Set(['CONFIRMED', 'PREPARING', 'READY']);

/** Which of the design's five buckets a real order status falls into - display grouping only, never the status itself. */
function groupFor(status: string): Exclude<TabGroup, 'all'> {
  if (PENDING_STATUSES.has(status)) {
    return 'pending';
  }
  if (CONFIRMED_STATUSES.has(status)) {
    return 'confirmed';
  }
  if (status === 'COMPLETED') {
    return 'completed';
  }

  return 'cancelled';
}

const TAB_LABELS: Readonly<Record<TabGroup, string>> = {
  all: 'Tất cả',
  pending: 'Chờ xác nhận',
  confirmed: 'Đã xác nhận',
  completed: 'Hoàn thành',
  cancelled: 'Đã hủy',
};

const TAB_ICON_SIZE = 16;
const TAB_ICONS: Readonly<Record<TabGroup, ReactNode>> = {
  all: <IconGrid size={TAB_ICON_SIZE} />,
  pending: <IconClock size={TAB_ICON_SIZE} />,
  confirmed: <IconCheckCircle size={TAB_ICON_SIZE} />,
  completed: <IconMedal size={TAB_ICON_SIZE} />,
  cancelled: <IconXCircle size={TAB_ICON_SIZE} />,
};

const STATUS_TILE_ICON_SIZE = 22;
const STATUS_TILE_ICONS: Readonly<Record<Exclude<TabGroup, 'all'>, ReactNode>> = {
  pending: <IconBox size={STATUS_TILE_ICON_SIZE} />,
  confirmed: <IconCheckCircle size={STATUS_TILE_ICON_SIZE} />,
  completed: <IconCheckCircle size={STATUS_TILE_ICON_SIZE} />,
  cancelled: <IconXCircle size={STATUS_TILE_ICON_SIZE} />,
};

type DateFilter = 'all' | 'today' | 'older';
type SortMode = 'new' | 'old' | 'price';
const PAGE_SIZE = 5;

const PAYMENT_LABELS: Readonly<Record<string, string>> = {
  PENDING: 'Chưa thanh toán',
  PROCESSING: 'Chờ xác nhận thanh toán',
  PAID: 'Đã thanh toán',
  FAILED: 'Thanh toán thất bại',
  PARTIALLY_REFUNDED: 'Hoàn tiền một phần',
  REFUNDED: 'Đã hoàn tiền',
};

const PAYMENT_TONE: Readonly<Record<string, string>> = {
  PENDING: 'unpaid',
  PROCESSING: 'processing',
  PAID: 'paid',
  FAILED: 'unpaid',
  PARTIALLY_REFUNDED: 'refund-pending',
  REFUNDED: 'refunded',
};

function isToday(iso: string): boolean {
  const date = new Date(iso);
  const now = new Date();

  return (
    date.getFullYear() === now.getFullYear() &&
    date.getMonth() === now.getMonth() &&
    date.getDate() === now.getDate()
  );
}

function timeLabel(iso: string): string {
  return new Date(iso).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
}

type ModalState =
  | { readonly kind: 'detail'; readonly order: MyOrderSummary }
  | { readonly kind: 'cancel'; readonly order: MyOrderSummary }
  | { readonly kind: 'pay'; readonly order: MyOrderSummary };

interface OrdersSidebarProps {
  readonly customer: CurrentCustomer;
  readonly loyalty: LoyaltyStanding | null;
  readonly onLogout: () => void;
}

/** Account card, nav list (mostly decorative - see DESIGN_PORT_NOTES.md) and the loyalty promo. */
function OrdersSidebar({ customer, loyalty, onLogout }: OrdersSidebarProps) {
  const initial = (customer.name.trim()[0] ?? '?').toLocaleUpperCase('vi');

  return (
    <aside className="orders-sidebar">
      <div className="orders-identity">
        <span className="orders-avatar" aria-hidden="true">
          {initial}
        </span>
        <div>
          <strong>{customer.name}</strong>
          {customer.phone === null ? null : <small>{customer.phone}</small>}
        </div>
      </div>

      <nav className="orders-menu">
        <span className="active">
          <IconBox size={18} /> Đơn hàng của tôi
        </span>
        <span>
          <IconUser size={18} /> Thông tin tài khoản
        </span>
        <span>
          <IconMapPin size={18} /> Sổ địa chỉ giao hàng
        </span>
        <span>
          <IconTag size={18} /> Mã giảm giá của tôi
        </span>
        <span>
          <IconStar size={18} /> Điểm thưởng
          {loyalty === null ? null : (
            <small className="orders-points">{loyalty.balance} điểm</small>
          )}
        </span>
        <button type="button" className="orders-logout" onClick={onLogout}>
          <IconLogout size={18} /> Đăng xuất
        </button>
      </nav>

      <div className="orders-promo">
        <span className="orders-promo-badge" aria-hidden="true">
          <IconGift size={26} />
        </span>
        <h2>
          Tích điểm
          <br />
          Nhận ưu đãi
        </h2>
        <p>Đặt hàng và tích điểm để nhận nhiều ưu đãi hấp dẫn!</p>
        <Link href="/" className="btn btn-yellow btn-centered">
          Xem ưu đãi <span>→</span>
        </Link>
      </div>
    </aside>
  );
}

interface OrderCardProps {
  readonly order: MyOrderSummary;
  readonly cancelingId: string | null;
  readonly reorderingId: string | null;
  readonly onDetail: (order: MyOrderSummary) => void;
  readonly onCancel: (order: MyOrderSummary) => void;
  readonly onPay: (order: MyOrderSummary) => void;
  readonly onReorder: (order: MyOrderSummary) => void;
}

function OrderCard({
  order,
  cancelingId,
  reorderingId,
  onDetail,
  onCancel,
  onPay,
  onReorder,
}: OrderCardProps) {
  const group = groupFor(order.status);
  const placedAt = order.placedAt ?? order.createdAt;
  const paymentLabel =
    order.paymentStatus === null
      ? '—'
      : (PAYMENT_LABELS[order.paymentStatus] ?? order.paymentStatus);
  const paymentTone =
    order.paymentStatus === null ? 'unpaid' : (PAYMENT_TONE[order.paymentStatus] ?? 'unpaid');
  const canPay =
    group !== 'completed' &&
    group !== 'cancelled' &&
    order.paymentMethod === 'BANK_TRANSFER' &&
    order.paymentStatus === 'PENDING';
  const canCancel = CUSTOMER_CANCELLABLE.has(order.status);
  const canReorder = group === 'completed' || group === 'cancelled';

  return (
    <article className="order-card">
      <div className={`order-status-tile tone-${group}`}>
        <span className="order-status-icon" aria-hidden="true">
          {STATUS_TILE_ICONS[group]}
        </span>
        {ORDER_STATUS_LABELS[order.status] ?? order.status}
      </div>

      <div className="order-info">
        <div className="order-id">{order.orderCode}</div>
        <small>
          <IconCalendar size={13} /> Đặt lúc {timeLabel(placedAt)} · {formatOrderDate(placedAt)}
        </small>
        <div className="order-payline">
          {order.paymentMethod === 'BANK_TRANSFER' ? 'VietQR' : 'Tiền mặt'}
          <span className={`badge tone-${paymentTone}`}>{paymentLabel}</span>
        </div>
      </div>

      {group === 'confirmed' ? (
        <div className="order-progress">
          <div className="order-step done">
            Đặt hàng
            <small>{timeLabel(order.createdAt)}</small>
          </div>
          <div className="order-step done current">
            {ORDER_STATUS_LABELS[order.status] ?? order.status}
          </div>
          <div className="order-step">Hoàn thành</div>
        </div>
      ) : (
        <div className="order-product">
          {order.items.slice(0, 1).map((item) => (
            <FoodIcon key={item.productName} name={null} size={28} />
          ))}
          <b>{order.items.map((item) => `${item.productName} ×${item.quantity}`).join(', ')}</b>
        </div>
      )}

      <div className="order-total">
        <small>Tổng tiền</small>
        <strong>{money(order.total)}</strong>
      </div>

      <div className="row-actions">
        {canPay ? (
          <button type="button" className="row-btn primary" onClick={() => onPay(order)}>
            <IconQrCode size={15} /> Thanh toán ngay <IconChevronRight size={14} />
          </button>
        ) : null}
        {canReorder ? (
          <button
            type="button"
            className="row-btn"
            disabled={reorderingId === order.id}
            onClick={() => onReorder(order)}
          >
            <IconRefresh size={14} /> {reorderingId === order.id ? 'Đang thêm...' : 'Đặt lại'}
          </button>
        ) : null}
        <button type="button" className="row-btn" onClick={() => onDetail(order)}>
          Xem chi tiết <IconChevronRight size={14} />
        </button>
        {canCancel && group === 'pending' ? (
          <button
            type="button"
            className="row-btn danger"
            disabled={cancelingId === order.id}
            onClick={() => onCancel(order)}
          >
            {cancelingId === order.id ? 'Đang hủy...' : 'Hủy đơn'}
          </button>
        ) : null}
      </div>
    </article>
  );
}

interface PayModalProps {
  readonly order: MyOrderSummary;
  readonly onClose: () => void;
  readonly onToast: (message: string) => void;
  readonly onReported: (orderId: string) => void;
}

/** The real `GET /orders/{id}/payment` + "I have transferred" flow, re-opened from order history. */
function PayModal({ order, onClose, onToast, onReported }: PayModalProps) {
  const [payment, setPayment] = useState<OrderPaymentInfo | null>(null);
  const [loadError, setLoadError] = useState('');

  useEffect(() => {
    let current = true;

    getOrderPayment(order.id)
      .then((info) => {
        if (current) {
          setPayment(info);
        }
      })
      .catch((error: unknown) => {
        if (current) {
          setLoadError(apiErrorMessage(error));
        }
      });

    return () => {
      current = false;
    };
  }, [order.id]);

  return (
    <div
      className="modal confirm-modal"
      role="dialog"
      aria-modal="true"
      aria-label="Thanh toán chuyển khoản"
    >
      <button type="button" className="close confirm-close" aria-label="Đóng" onClick={onClose}>
        ✕
      </button>
      <header className="confirm-head">
        <div className="confirm-badge" aria-hidden="true">
          <IconQrCode size={28} />
        </div>
        <div>
          <h3>Thanh toán chuyển khoản</h3>
          <p>
            Đơn <strong>{order.orderCode}</strong> · {money(order.total)}
          </p>
        </div>
      </header>
      <div className="confirm-body">
        {loadError !== '' ? <p className="notice">{loadError}</p> : null}
        {payment === null && loadError === '' ? <p className="modal-note">Đang tải...</p> : null}
        {payment === null || payment.bankTransfer === null ? null : (
          <section className="confirm-section">
            <p className="modal-note">Quét mã bằng app ngân hàng, hoặc chuyển khoản thủ công:</p>
            <BankTransferPanel
              bankTransfer={payment.bankTransfer}
              orderCode={order.orderCode}
              onToast={onToast}
            />
            <p className="confirm-info">
              Sau khi chuyển khoản, vui lòng xác nhận để cửa hàng kiểm tra và xác nhận thanh toán -
              mã QR không phải là bằng chứng đã thanh toán.
            </p>
            <div className="confirm-section-label">XÁC NHẬN THANH TOÁN</div>
            <TransferReportForm
              orderId={order.id}
              guestOrderToken={null}
              onReported={() => onReported(order.id)}
            />
          </section>
        )}
        {payment !== null && payment.bankTransfer === null ? (
          <p className="modal-note">Đơn này không còn chờ chuyển khoản nữa.</p>
        ) : null}
      </div>
    </div>
  );
}

interface CancelModalProps {
  readonly order: MyOrderSummary;
  readonly busy: boolean;
  readonly onClose: () => void;
  readonly onConfirm: (order: MyOrderSummary, reason: string) => void;
}

function CancelModal({ order, busy, onClose, onConfirm }: CancelModalProps) {
  const [reason, setReason] = useState('');

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Hủy đơn hàng">
      <div className="modal-head">
        <h3>Hủy đơn hàng</h3>
        <button type="button" className="close" aria-label="Đóng" onClick={onClose}>
          ✕
        </button>
      </div>
      <p>
        Bạn có chắc muốn hủy đơn <strong>{order.orderCode}</strong>?
      </p>
      <div className="form-row">
        <label htmlFor="cancelReason">Lý do hủy (bắt buộc)</label>
        <textarea
          id="cancelReason"
          rows={3}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />
      </div>
      <div className="modal-actions">
        <button type="button" className="btn" onClick={onClose}>
          Đóng
        </button>
        <button
          type="button"
          className="btn btn-danger-outline"
          disabled={busy || reason.trim() === ''}
          onClick={() => onConfirm(order, reason)}
        >
          {busy ? 'Đang hủy...' : 'Xác nhận hủy'}
        </button>
      </div>
    </div>
  );
}

interface DetailModalProps {
  readonly order: MyOrderSummary;
  readonly onClose: () => void;
}

function DetailModal({ order, onClose }: DetailModalProps) {
  const paymentLabel =
    order.paymentStatus === null
      ? '—'
      : (PAYMENT_LABELS[order.paymentStatus] ?? order.paymentStatus);

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Chi tiết đơn hàng">
      <div className="modal-head">
        <h3>Chi tiết đơn hàng</h3>
        <button type="button" className="close" aria-label="Đóng" onClick={onClose}>
          ✕
        </button>
      </div>
      <p>
        <strong>{order.orderCode}</strong> · {ORDER_STATUS_LABELS[order.status] ?? order.status}
      </p>
      <p className="modal-note">{formatOrderDate(order.placedAt ?? order.createdAt)}</p>
      <p>{order.items.map((item) => `${item.productName} ×${item.quantity}`).join(', ')}</p>
      <p>Phương thức: {order.paymentMethod === 'BANK_TRANSFER' ? 'VietQR' : 'Tiền mặt'}</p>
      <p>
        Tổng tiền: <strong>{money(order.total)}</strong>
      </p>
      <p>Thanh toán: {paymentLabel}</p>
      <div className="modal-actions">
        <button type="button" className="btn" onClick={onClose}>
          Đóng
        </button>
      </div>
    </div>
  );
}

/**
 * A signed-in customer's own order history, with self-service cancel,
 * reorder and (for an unpaid bank-transfer order) re-opening the real
 * VietQR/report-transfer flow - its own route rather than a modal, so it can
 * be opened, shared and come back to directly.
 *
 * Ported from the approved "my-orders-v2" design: the sidebar, hero, status
 * tabs, search/date/sort filters and five-per-page pager are new; cancel,
 * reorder and the bank-transfer payment flow stay wired to the same real
 * endpoints this page already used (see DESIGN_PORT_NOTES.md for the one
 * delivered feature - star ratings - left out because there is no review
 * module on the backend to persist them to).
 *
 * A fresh `useStorefront()`-free component on purpose: the cart it writes to
 * for "Đặt lại" is the same browser-stored cart the home page reads
 * (`cart-storage.ts`), so no state needs to be shared between the two pages -
 * only the storage key.
 */
export function OrdersPage() {
  const [customer, setCustomer] = useState<CurrentCustomer | null | undefined>(undefined);
  const [loyalty, setLoyalty] = useState<LoyaltyStanding | null>(null);
  const [orders, setOrders] = useState<readonly MyOrderSummary[]>([]);
  const [ordersBusy, setOrdersBusy] = useState(false);
  const [ordersError, setOrdersError] = useState('');
  const [todayItems, setTodayItems] = useState<readonly MenuItem[]>([]);
  const [cancelingId, setCancelingId] = useState<string | null>(null);
  const [reorderingId, setReorderingId] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState('');
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const [tab, setTab] = useState<TabGroup>('all');
  const [search, setSearch] = useState('');
  const [dateFilter, setDateFilter] = useState<DateFilter>('all');
  const [sort, setSort] = useState<SortMode>('new');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<ModalState | null>(null);

  const showToast = useCallback((message: string) => {
    setToastMessage(message);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMessage(''), 3000);
  }, []);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  // Revalidated against the session cookie, same as the home page - never
  // assumed from anything stored in the browser.
  useEffect(() => {
    let cancelled = false;

    getCurrentCustomer()
      .then((found) => {
        if (!cancelled) {
          setCustomer(found);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setCustomer(null);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  // The customer's whole order history (a real shop's is small - see
  // DESIGN_PORT_NOTES.md), today's menu, and the real loyalty balance for
  // the sidebar. Tab counts, search, date and sort all run client-side over
  // this one loaded list, exactly as the delivered design's own demo does.
  useEffect(() => {
    if (customer === null || customer === undefined) {
      return;
    }

    let cancelled = false;
    setOrdersBusy(true);
    setOrdersError('');

    async function loadAll(): Promise<readonly MyOrderSummary[]> {
      const items: MyOrderSummary[] = [];
      let cursor: string | undefined;

      for (;;) {
        const loaded = await listMyOrders(cursor);
        items.push(...loaded.items);
        if (!loaded.hasMore || loaded.nextCursor === null) {
          break;
        }
        cursor = loaded.nextCursor;
      }

      return items;
    }

    Promise.all([loadAll(), loadTodayMenu(), getOwnLoyalty().catch(() => null)])
      .then(([items, menu, standing]) => {
        if (cancelled) {
          return;
        }
        setOrders(items);
        setTodayItems(menu?.items ?? []);
        setLoyalty(standing);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setOrdersError(apiErrorMessage(error));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setOrdersBusy(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [customer]);

  const counts = useMemo(() => {
    const result: Record<TabGroup, number> = {
      all: orders.length,
      pending: 0,
      confirmed: 0,
      completed: 0,
      cancelled: 0,
    };
    for (const order of orders) {
      result[groupFor(order.status)] += 1;
    }

    return result;
  }, [orders]);

  const filtered = useMemo(() => {
    const query = search.trim().toLocaleLowerCase('vi');
    let list = orders.filter((order) => {
      if (tab !== 'all' && groupFor(order.status) !== tab) {
        return false;
      }
      const placedAt = order.placedAt ?? order.createdAt;
      if (dateFilter === 'today' && !isToday(placedAt)) {
        return false;
      }
      if (dateFilter === 'older' && isToday(placedAt)) {
        return false;
      }
      if (query === '') {
        return true;
      }
      const haystack = (
        order.orderCode +
        ' ' +
        order.items.map((item) => item.productName).join(' ')
      ).toLocaleLowerCase('vi');

      return haystack.includes(query);
    });

    if (sort === 'old') {
      list = [...list].reverse();
    } else if (sort === 'price') {
      list = [...list].sort((a, b) => b.total - a.total);
    }

    return list;
  }, [orders, tab, search, dateFilter, sort]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const currentPage = Math.min(page, pageCount);
  const paged = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

  const changeTab = useCallback((next: TabGroup) => {
    setTab(next);
    setPage(1);
  }, []);

  const handleCancel = useCallback(
    (order: MyOrderSummary, reason: string) => {
      setCancelingId(order.id);
      cancelOrder(order.id, reason)
        .then((status) => {
          setOrders((current) =>
            current.map((existing) =>
              existing.id === order.id ? { ...existing, status } : existing,
            ),
          );
          setModal(null);
          showToast('Đã hủy đơn hàng.');
        })
        .catch((error: unknown) => showToast(apiErrorMessage(error)))
        .finally(() => setCancelingId(null));
    },
    [showToast],
  );

  /**
   * Adds every line of a past order back to the cart that is still on
   * today's menu and available - checkout itself revalidates every line
   * again anyway (CLAUDE.md section 6), so this only has to be a useful
   * prefill, not a second source of truth for price or availability.
   */
  const handleReorder = useCallback(
    (order: MyOrderSummary) => {
      setReorderingId(order.id);
      getOrder(order.id)
        .then((detail) => {
          const byVariant = new Map(
            todayItems.map((item) => [`${item.productId}:${item.variantId}`, item]),
          );

          let added = 0;
          let unavailable = 0;

          for (const line of detail.items) {
            const match = byVariant.get(`${line.productId}:${line.variantId}`);

            if (match === undefined || !isSellable(match)) {
              unavailable += 1;
              continue;
            }
            addToStoredCart(match.id, line.quantity);
            added += 1;
          }

          if (added === 0) {
            showToast('Các món trong đơn này hiện không còn bán.');
          } else if (unavailable > 0) {
            showToast(`Đã thêm ${added} món vào giỏ hàng, ${unavailable} món hiện không còn bán.`);
          } else {
            showToast(`Đã thêm ${added} món vào giỏ hàng.`);
          }
        })
        .catch((error: unknown) => showToast(apiErrorMessage(error)))
        .finally(() => setReorderingId(null));
    },
    [todayItems, showToast],
  );

  const handleReported = useCallback((orderId: string) => {
    setOrders((current) =>
      current.map((existing) =>
        existing.id === orderId ? { ...existing, paymentStatus: 'PROCESSING' } : existing,
      ),
    );
  }, []);

  const handleLogout = useCallback(() => {
    void logoutCustomer().finally(() => {
      window.location.href = '/';
    });
  }, []);

  return (
    <div className="orders-layout">
      <header className="orders-top">
        <Link href="/" className="brand">
          <img className="brand-logo" src="/brand/logo-mark.webp" alt="Bếp nhà mình" />
          <span>
            <strong>Bếp nhà mình</strong>
            <small>Món ngon nhà làm</small>
          </span>
        </Link>
        <nav className="topnav orders-topnav">
          <Link href="/">Trang chủ</Link>
          <Link href="/">Thực đơn</Link>
          <Link href="/">Ưu đãi</Link>
        </nav>
      </header>

      {customer === undefined ? <p className="modal-note orders-page-note">Đang tải...</p> : null}

      {customer === null ? (
        <div className="orders-page-note">
          <p className="notice">Vui lòng đăng nhập để xem đơn hàng của bạn.</p>
          <Link href="/" className="btn btn-yellow btn-centered">
            Về trang chủ để đăng nhập →
          </Link>
        </div>
      ) : null}

      {customer === null || customer === undefined ? null : (
        <div className="orders-body">
          <OrdersSidebar customer={customer} loyalty={loyalty} onLogout={handleLogout} />

          <main className="orders-main">
            <section className="orders-hero">
              <div>
                <h1>
                  <IconBox size={28} /> Đơn hàng của tôi
                </h1>
                <p>Theo dõi, quản lý và xem chi tiết các đơn hàng đã đặt.</p>
              </div>
            </section>

            <div className="orders-tabs">
              {(['all', 'pending', 'confirmed', 'completed', 'cancelled'] as const).map((group) => (
                <button
                  key={group}
                  type="button"
                  className={group === tab ? 'active' : undefined}
                  onClick={() => changeTab(group)}
                >
                  {TAB_ICONS[group]} {TAB_LABELS[group]} ({counts[group]})
                </button>
              ))}
            </div>

            <div className="orders-filters">
              <label className="orders-search">
                <IconSearch size={17} />
                <input
                  placeholder="Tìm theo mã đơn, tên món..."
                  aria-label="Tìm đơn"
                  value={search}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                  }}
                />
              </label>
              <label className="orders-select">
                <IconCalendar size={16} />
                <select
                  aria-label="Thời gian"
                  value={dateFilter}
                  onChange={(event) => {
                    setDateFilter(event.target.value as DateFilter);
                    setPage(1);
                  }}
                >
                  <option value="all">Tất cả thời gian</option>
                  <option value="today">Hôm nay</option>
                  <option value="older">Trước hôm nay</option>
                </select>
              </label>
              <label className="orders-select">
                <IconSort size={16} />
                <select
                  aria-label="Sắp xếp"
                  value={sort}
                  onChange={(event) => setSort(event.target.value as SortMode)}
                >
                  <option value="new">Mới nhất</option>
                  <option value="old">Cũ nhất</option>
                  <option value="price">Giá cao nhất</option>
                </select>
              </label>
            </div>

            {ordersError !== '' ? <p className="notice">{ordersError}</p> : null}
            {ordersBusy ? <p className="modal-note">Đang tải đơn hàng...</p> : null}

            {!ordersBusy && filtered.length === 0 && ordersError === '' ? (
              <p className="modal-note">Không tìm thấy đơn hàng phù hợp.</p>
            ) : null}

            <div className="orders-list">
              {paged.map((order) => (
                <OrderCard
                  key={order.id}
                  order={order}
                  cancelingId={cancelingId}
                  reorderingId={reorderingId}
                  onDetail={(selected) => setModal({ kind: 'detail', order: selected })}
                  onCancel={(selected) => setModal({ kind: 'cancel', order: selected })}
                  onPay={(selected) => setModal({ kind: 'pay', order: selected })}
                  onReorder={handleReorder}
                />
              ))}
            </div>

            {!ordersBusy && filtered.length > 0 ? (
              <footer className="orders-foot">
                <span>
                  Hiển thị {(currentPage - 1) * PAGE_SIZE + 1} –{' '}
                  {Math.min(currentPage * PAGE_SIZE, filtered.length)} trên {filtered.length} đơn
                  hàng
                </span>
                <div className="orders-pages">
                  {Array.from({ length: pageCount }, (_, index) => (
                    <button
                      key={index}
                      type="button"
                      className={currentPage === index + 1 ? 'primary' : undefined}
                      onClick={() => setPage(index + 1)}
                    >
                      {index + 1}
                    </button>
                  ))}
                </div>
              </footer>
            ) : null}
          </main>
        </div>
      )}

      {modal === null ? null : (
        <>
          <div className="overlay" role="presentation" onClick={() => setModal(null)} />
          {modal.kind === 'detail' ? (
            <DetailModal order={modal.order} onClose={() => setModal(null)} />
          ) : null}
          {modal.kind === 'cancel' ? (
            <CancelModal
              order={modal.order}
              busy={cancelingId === modal.order.id}
              onClose={() => setModal(null)}
              onConfirm={handleCancel}
            />
          ) : null}
          {modal.kind === 'pay' ? (
            <PayModal
              order={modal.order}
              onClose={() => setModal(null)}
              onToast={showToast}
              onReported={handleReported}
            />
          ) : null}
        </>
      )}

      <div
        className={toastMessage === '' ? 'toast' : 'toast show'}
        role="status"
        aria-live="polite"
      >
        {toastMessage}
      </div>
    </div>
  );
}
