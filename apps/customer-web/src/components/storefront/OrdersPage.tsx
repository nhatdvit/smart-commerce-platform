'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';

import { apiErrorMessage } from '@/lib/api-error-message';
import { type CurrentCustomer, getCurrentCustomer } from '@/lib/auth-api';
import { addToStoredCart } from '@/lib/cart-storage';
import { loadTodayMenu, type MenuItem } from '@/lib/menu-api';
import { money } from '@/lib/money';
import { cancelOrder, getOrder, listMyOrders, type MyOrderSummary } from '@/lib/order-api';
import { formatOrderDate, ORDER_STATUS_LABELS } from '@/lib/order-status-labels';

/** Mirrors `CancelOrder::CUSTOMER_CANCELLABLE` on the backend - a hint for the button, not the rule itself: the server is the one source of truth. */
const CUSTOMER_CANCELLABLE = new Set(['PENDING_PAYMENT', 'PLACED', 'CONFIRMED']);

/**
 * A signed-in customer's own order history, with self-service cancel and
 * reorder - its own route rather than a modal, so it can be opened, shared
 * and come back to directly.
 *
 * A fresh `useStorefront()`-free component on purpose: the cart it writes to
 * for "Đặt lại" is the same browser-stored cart the home page reads
 * (`cart-storage.ts`), so no state needs to be shared between the two pages -
 * only the storage key.
 */
export function OrdersPage() {
  const [customer, setCustomer] = useState<CurrentCustomer | null | undefined>(undefined);
  const [orders, setOrders] = useState<readonly MyOrderSummary[]>([]);
  const [ordersBusy, setOrdersBusy] = useState(false);
  const [ordersError, setOrdersError] = useState('');
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [todayItems, setTodayItems] = useState<readonly MenuItem[]>([]);
  const [cancelingId, setCancelingId] = useState<string | null>(null);
  const [reorderingId, setReorderingId] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState('');
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

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

  // The first page of orders, and today's menu - needed to know which lines
  // of a past order can actually be re-added right now.
  useEffect(() => {
    if (customer === null || customer === undefined) {
      return;
    }

    let cancelled = false;
    setOrdersBusy(true);
    setOrdersError('');

    Promise.all([listMyOrders(), loadTodayMenu()])
      .then(([page, menu]) => {
        if (cancelled) {
          return;
        }
        setOrders(page.items);
        setCursor(page.nextCursor);
        setHasMore(page.hasMore);
        setTodayItems(menu?.items ?? []);
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

  const loadMore = useCallback(() => {
    if (ordersBusy || !hasMore || cursor === null) {
      return;
    }
    setOrdersBusy(true);
    listMyOrders(cursor)
      .then((page) => {
        setOrders((current) => [...current, ...page.items]);
        setCursor(page.nextCursor);
        setHasMore(page.hasMore);
      })
      .catch((error: unknown) => setOrdersError(apiErrorMessage(error)))
      .finally(() => setOrdersBusy(false));
  }, [ordersBusy, hasMore, cursor]);

  const handleCancel = useCallback(
    async (order: MyOrderSummary) => {
      if (!window.confirm(`Hủy đơn ${order.orderCode}?`)) {
        return;
      }
      setCancelingId(order.id);
      try {
        const status = await cancelOrder(order.id);
        setOrders((current) =>
          current.map((existing) =>
            existing.id === order.id ? { ...existing, status } : existing,
          ),
        );
        showToast('Đã hủy đơn hàng.');
      } catch (error) {
        showToast(apiErrorMessage(error));
      } finally {
        setCancelingId(null);
      }
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
    async (order: MyOrderSummary) => {
      setReorderingId(order.id);
      try {
        const detail = await getOrder(order.id);
        const byVariant = new Map(
          todayItems.map((item) => [`${item.productId}:${item.variantId}`, item]),
        );

        let added = 0;
        let unavailable = 0;

        for (const line of detail.items) {
          const match = byVariant.get(`${line.productId}:${line.variantId}`);

          if (match === undefined || !match.isAvailable) {
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
      } catch (error) {
        showToast(apiErrorMessage(error));
      } finally {
        setReorderingId(null);
      }
    },
    [todayItems, showToast],
  );

  return (
    <main className="orders-page">
      <div className="orders-page-head">
        <Link href="/" className="brand-link">
          ← Bếp nhà mình
        </Link>
        <h1>Đơn hàng của tôi</h1>
      </div>

      {customer === undefined ? <p className="modal-note">Đang tải...</p> : null}

      {customer === null ? (
        <>
          <p className="notice">Vui lòng đăng nhập để xem đơn hàng của bạn.</p>
          <Link href="/" className="btn btn-yellow btn-centered">
            Về trang chủ để đăng nhập →
          </Link>
        </>
      ) : null}

      {customer === null || customer === undefined ? null : (
        <>
          {ordersError !== '' ? <p className="notice">{ordersError}</p> : null}
          {ordersBusy && orders.length === 0 ? (
            <p className="modal-note">Đang tải đơn hàng...</p>
          ) : null}
          {!ordersBusy && orders.length === 0 && ordersError === '' ? (
            <p className="modal-note">Bạn chưa đặt đơn hàng nào.</p>
          ) : null}

          {orders.map((order) => (
            <div className="profile-section order-row" key={order.id}>
              <div className="order-row-head">
                <strong>{order.orderCode}</strong>
                <span>{ORDER_STATUS_LABELS[order.status] ?? order.status}</span>
              </div>
              <p className="modal-note">
                {order.items.map((item) => `${item.productName} ×${item.quantity}`).join(', ')}
              </p>
              <div className="order-row-foot">
                <small>{formatOrderDate(order.placedAt ?? order.createdAt)}</small>
                <strong>{money(order.total)}</strong>
              </div>
              <div className="order-row-actions">
                {CUSTOMER_CANCELLABLE.has(order.status) ? (
                  <button
                    type="button"
                    className="btn btn-danger-outline btn-centered"
                    disabled={cancelingId === order.id}
                    onClick={() => void handleCancel(order)}
                  >
                    {cancelingId === order.id ? 'Đang hủy...' : 'Hủy đơn'}
                  </button>
                ) : null}
                <button
                  type="button"
                  className="btn btn-outline btn-centered"
                  disabled={reorderingId === order.id}
                  onClick={() => void handleReorder(order)}
                >
                  {reorderingId === order.id ? 'Đang thêm...' : 'Đặt lại'}
                </button>
              </div>
            </div>
          ))}

          {hasMore ? (
            <button
              type="button"
              className="btn btn-outline btn-centered"
              disabled={ordersBusy}
              onClick={loadMore}
            >
              {ordersBusy ? 'Đang tải...' : 'Tải thêm'}
            </button>
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
    </main>
  );
}
