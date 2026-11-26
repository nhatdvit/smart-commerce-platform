'use client';

import { useEffect, useMemo, useRef, useState } from 'react';

import { money } from '@/lib/money';
import {
  FULFILLMENT_LABELS,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  canCancel,
  confirmableStatus,
  dateTimeLabel,
  itemsSummary,
  loadOrderDetail,
  timeLabel,
  type AdminOrder,
  type AdminOrderDetail,
} from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import { IconAlert, IconCheckCircle, IconClock } from '../icons';
import { Metric, PageHeading, StatusBadge } from '../primitives';

type TypeFilter = 'all' | 'urgent' | 'pickup' | 'delivery';

function toTypeFilter(value: string): TypeFilter {
  return value === 'urgent' || value === 'pickup' || value === 'delivery' ? value : 'all';
}

/**
 * An order nobody has acted on for this long is the one the counter should
 * reach for next - a frontend-only reading, never sent to or stored by the
 * server: nothing in the API contract has a notion of urgency (most orders
 * are immediate, not scheduled, so there is no pickup-time deadline to
 * measure against instead).
 */
const URGENT_AFTER_MS = 10 * 60 * 1000;

function isUrgent(order: AdminOrder, now: number): boolean {
  const basis = order.placedAt ?? order.createdAt;
  if (basis === null) {
    return false;
  }
  const at = new Date(basis).getTime();

  return !Number.isNaN(at) && now - at >= URGENT_AFTER_MS;
}

/** A short beep for a new arrival - opt-in, since a counter is not always somewhere a sound is welcome. */
function beep(): void {
  try {
    const Ctor =
      window.AudioContext ??
      (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (Ctor === undefined) {
      return;
    }
    const ctx = new Ctor();
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.connect(gain);
    gain.connect(ctx.destination);
    oscillator.frequency.value = 740;
    gain.gain.value = 0.06;
    oscillator.start();
    oscillator.stop(ctx.currentTime + 0.14);
  } catch {
    // Audio unavailable or blocked by the browser's autoplay policy: the
    // toast and the row highlight still announce the arrival silently.
  }
}

/**
 * Milliseconds to wait before searching.
 *
 * Long enough that typing "bánh mì" is one request rather than seven, short
 * enough that it still feels like typing. Mirrors OrdersScreen's own debounce.
 */
const SEARCH_DEBOUNCE_MS = 300;

/** One row of the waiting list. */
function QueueRow({
  order,
  urgent,
  selected,
  onSelect,
}: {
  readonly order: AdminOrder;
  readonly urgent: boolean;
  readonly selected: boolean;
  readonly onSelect: () => void;
}) {
  return (
    <button
      type="button"
      className={selected ? 'order-row selected' : 'order-row'}
      onClick={onSelect}
    >
      <div className="order-row-top">
        <strong>{order.code}</strong>
        {urgent ? (
          <span className="tag tag-red">⚡ Ưu tiên</span>
        ) : (
          <StatusBadge status={order.status} />
        )}
      </div>
      <div className="order-row-customer">{order.contactName}</div>
      <div className="order-row-sub">
        🕒 Nhận {timeLabel(order.placedAt ?? order.createdAt)} ·{' '}
        {FULFILLMENT_LABELS[order.fulfillment]}
        <br />
        {itemsSummary(order.items)}
      </div>
      <div className="order-row-foot">
        <span className="price">{money(order.total)}</span>
        <span className={order.paymentStatus === 'PAID' ? 'tag tag-green' : 'tag'}>
          {order.paymentStatus === null
            ? 'Chưa thanh toán'
            : PAYMENT_STATUS_LABELS[order.paymentStatus]}
        </span>
      </div>
    </button>
  );
}

/** The selected order's full detail, with the two actions this screen exists for. */
function QueueDetail({ orderId }: { readonly orderId: string }) {
  const { moveOrder, ordersBusy } = useAdminData();
  const [order, setOrder] = useState<AdminOrderDetail | null>(null);
  const [loadError, setLoadError] = useState('');
  const [askingReason, setAskingReason] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState('');

  useEffect(() => {
    let current = true;
    setOrder(null);
    setLoadError('');
    setAskingReason(false);
    setReason('');
    setReasonError('');

    void loadOrderDetail(orderId)
      .then((detail) => {
        if (current) {
          setOrder(detail);
        }
      })
      .catch((error: unknown) => {
        if (current) {
          setLoadError(error instanceof Error ? error.message : 'Không tải được đơn hàng.');
        }
      });

    return () => {
      current = false;
    };
  }, [orderId]);

  if (loadError !== '') {
    return <p role="alert">{loadError}</p>;
  }

  if (order === null) {
    return <p>Đang tải chi tiết đơn...</p>;
  }

  function reject() {
    if (order === null) {
      return;
    }
    if (reason.trim() === '') {
      setReasonError('Vui lòng nhập lý do từ chối.');

      return;
    }
    moveOrder(order.id, 'CANCELLED', reason.trim());
  }

  const next = confirmableStatus(order);

  return (
    <div className="order-detail">
      <div className="order-detail-row">
        <StatusBadge status={order.status} />
        <span>{FULFILLMENT_LABELS[order.fulfillment]}</span>
        <span>Đặt lúc {dateTimeLabel(order.placedAt ?? order.createdAt)}</span>
      </div>

      <dl className="order-facts">
        <dt>Khách</dt>
        <dd>
          {order.contactName} · {order.contactPhone}
        </dd>
        {order.deliveryAddress === null ? null : (
          <>
            <dt>Giao tới</dt>
            <dd>{order.deliveryAddress}</dd>
          </>
        )}
        <dt>Thanh toán</dt>
        <dd>
          {order.paymentMethod === null
            ? 'Chưa ghi nhận thanh toán'
            : `${PAYMENT_METHOD_LABELS[order.paymentMethod]} · ${
                order.paymentStatus === null
                  ? 'không rõ'
                  : PAYMENT_STATUS_LABELS[order.paymentStatus]
              }`}
        </dd>
        {order.note === null ? null : (
          <>
            <dt>Ghi chú</dt>
            <dd>{order.note}</dd>
          </>
        )}
      </dl>

      <table className="order-lines">
        <tbody>
          {order.lines.map((line) => (
            <tr key={line.id}>
              <td>
                {line.quantity} × {line.name}
                {line.note === null ? null : <em> ({line.note})</em>}
              </td>
              <td>{money(line.lineTotal)}</td>
            </tr>
          ))}
          <tr className="order-total">
            <td>Tổng đơn hàng</td>
            <td>{money(order.total)}</td>
          </tr>
        </tbody>
      </table>

      {askingReason ? (
        <label className="form-field">
          <span>Lý do từ chối</span>
          <input
            autoFocus
            maxLength={255}
            placeholder="VD: Hết món"
            value={reason}
            onChange={(event) => {
              setReason(event.target.value);
              setReasonError('');
            }}
          />
          {reasonError === '' ? null : (
            <em className="field-error" role="alert">
              {reasonError}
            </em>
          )}
        </label>
      ) : null}

      <div className="order-board-actions">
        {askingReason ? (
          <button
            type="button"
            className="btn"
            disabled={ordersBusy}
            onClick={() => setAskingReason(false)}
          >
            Quay lại
          </button>
        ) : canCancel(order.status) ? (
          <button
            type="button"
            className="btn btn-danger"
            disabled={ordersBusy}
            onClick={() => setAskingReason(true)}
          >
            ✕ Từ chối
          </button>
        ) : null}

        {askingReason ? (
          <button type="button" className="btn btn-danger" disabled={ordersBusy} onClick={reject}>
            Xác nhận từ chối
          </button>
        ) : next === 'CONFIRMED' ? (
          <button
            type="button"
            className="btn btn-primary"
            disabled={ordersBusy}
            onClick={() => moveOrder(order.id, 'CONFIRMED')}
          >
            ✓ Xác nhận đơn
          </button>
        ) : null}
      </div>
    </div>
  );
}

/**
 * Đơn hàng mới: the counter's triage board.
 *
 * `PLACED` - a cash order that has just come in, or a bank-transfer order
 * whose payment staff already verified on the Payments screen - plus a
 * `PENDING_PAYMENT` one once a customer has reported the transfer: genuinely
 * waiting on staff, as opposed to one nobody has acted on. That second group
 * gets its own "✓ Xác nhận đơn" (via `confirmableStatus`, not `forwardStatus`
 * - CLAUDE.md section 6, "Order confirmation independent of payment
 * confirmation"): staff may confirm the order right away, before anyone
 * checks the real bank credit on the Payments screen. An unreported
 * `PENDING_PAYMENT` order is still excluded; there is nothing to triage about
 * it yet.
 */
export function NewOrdersScreen() {
  const {
    orders,
    orderCounts,
    awaitingPaymentConfirmationCount,
    ordersBusy,
    ordersError,
    loadOrders,
    newOrderIds,
  } = useAdminData();

  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [soundOn, setSoundOn] = useState(false);
  const [handledCount, setHandledCount] = useState(0);

  const knownIds = useRef<ReadonlySet<string> | null>(null);
  const lastSearch = useRef(search);
  const lastNewOrderIds = useRef(newOrderIds);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    loadOrders({
      statuses: ['PLACED', 'PENDING_PAYMENT'],
      search,
      newestFirst: false,
      limit: 50,
    });
  }, [loadOrders, search]);

  // The query above over-fetches PENDING_PAYMENT (the API has no payment-status
  // filter - see ListOrdersRequest): keep only the ones a customer has already
  // reported, same rule the sidebar badge counts by.
  const queue = useMemo(
    () =>
      orders.filter((order) => order.status === 'PLACED' || order.paymentStatus === 'PROCESSING'),
    [orders],
  );

  /**
   * "Đã xử lý trong phiên": how many orders left this fixed-status list since
   * the screen opened. Accurate for a confirm/reject (the order leaves
   * `PLACED`) without the move itself having to report back; a search change
   * resets the baseline first, so narrowing the list is never counted as
   * processing the orders it hides.
   */
  useEffect(() => {
    const currentIds = new Set(queue.map((order) => order.id));
    const searchChanged = lastSearch.current !== search;
    lastSearch.current = search;

    if (knownIds.current === null || searchChanged) {
      knownIds.current = currentIds;

      return;
    }

    let left = 0;
    for (const id of knownIds.current) {
      if (!currentIds.has(id)) {
        left += 1;
      }
    }
    if (left > 0) {
      setHandledCount((count) => count + left);
    }
    knownIds.current = currentIds;
  }, [queue, search]);

  // One beep per genuinely new arrival, never on the first load of the screen.
  useEffect(() => {
    if (soundOn) {
      for (const id of newOrderIds) {
        if (!lastNewOrderIds.current.has(id)) {
          beep();
          break;
        }
      }
    }
    lastNewOrderIds.current = newOrderIds;
  }, [newOrderIds, soundOn]);

  const now = Date.now();
  const visible = queue.filter((order) => {
    if (typeFilter === 'urgent') {
      return isUrgent(order, now);
    }
    if (typeFilter === 'pickup') {
      return order.fulfillment === 'PICKUP';
    }
    if (typeFilter === 'delivery') {
      return order.fulfillment === 'DELIVERY';
    }

    return true;
  });
  const urgentCount = queue.filter((order) => isUrgent(order, now)).length;
  const firstVisibleId = visible[0]?.id ?? null;
  const selectionVisible = selectedId !== null && visible.some((order) => order.id === selectedId);

  useEffect(() => {
    if (!selectionVisible) {
      setSelectedId(firstVisibleId);
    }
  }, [selectionVisible, firstVisibleId]);

  return (
    <>
      <PageHeading
        title="Đơn hàng mới"
        description="Tiếp nhận nhanh, ưu tiên đơn chờ xác nhận lâu nhất."
      />

      <div className="metrics">
        <Metric
          title="Chờ xác nhận"
          value={(orderCounts.PLACED ?? 0) + awaitingPaymentConfirmationCount}
          icon={<IconClock size={18} />}
          color="#fff3d9"
          foot="Trên toàn cửa hàng"
        />
        <Metric
          title="Cần ưu tiên"
          value={urgentCount}
          icon={<IconAlert size={18} />}
          color="#ffe8e8"
          foot="Chờ xác nhận quá 10 phút"
        />
        <Metric
          title="Đã xử lý trong phiên"
          value={handledCount}
          icon={<IconCheckCircle size={18} />}
          color="#e3f7ec"
          foot="Kể từ khi mở màn hình này"
        />
      </div>

      <div className="search-row">
        <input
          className="search"
          placeholder="🔎 Tìm mã đơn, tên khách, món ăn..."
          aria-label="Tìm mã đơn, tên khách, món ăn"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <select
          className="filter"
          aria-label="Lọc theo loại đơn"
          value={typeFilter}
          onChange={(event) => setTypeFilter(toTypeFilter(event.target.value))}
        >
          <option value="all">Tất cả đơn</option>
          <option value="urgent">Cần ưu tiên</option>
          <option value="pickup">Tự đến lấy</option>
          <option value="delivery">Giao hàng</option>
        </select>
        <button type="button" className="btn" onClick={() => setSoundOn((on) => !on)}>
          {soundOn ? '🔔 Âm báo: Bật' : '🔕 Âm báo: Tắt'}
        </button>
      </div>

      {ordersError !== '' ? (
        <div className="panel empty">
          <strong>Không tải được danh sách đơn</strong>
          {ordersError}
        </div>
      ) : (
        <div className="order-board">
          <section className="panel order-board-list">
            <div className="panel-title">
              <h3>Danh sách chờ</h3>
              <span className="panel-note" aria-live="polite">
                {ordersBusy ? 'Đang tải...' : 'Ưu tiên theo thời gian chờ · tự làm mới mỗi 5 giây'}
              </span>
            </div>
            <div className="order-list">
              {visible.length === 0 ? (
                <div className="empty">🎉 Không có đơn phù hợp</div>
              ) : (
                visible.map((order) => (
                  <QueueRow
                    key={order.id}
                    order={order}
                    urgent={isUrgent(order, now)}
                    selected={selectedId === order.id}
                    onSelect={() => setSelectedId(order.id)}
                  />
                ))
              )}
            </div>
          </section>

          <section className="panel order-board-detail">
            <div className="panel-title">
              <h3>Chi tiết đơn hàng</h3>
              {selectedId === null ? (
                <span className="panel-note">Chọn một đơn bên trái</span>
              ) : null}
            </div>
            {selectedId === null ? (
              <div className="empty">✓ Đã xử lý hết đơn chờ xác nhận.</div>
            ) : (
              <QueueDetail orderId={selectedId} />
            )}
          </section>
        </div>
      )}
    </>
  );
}
