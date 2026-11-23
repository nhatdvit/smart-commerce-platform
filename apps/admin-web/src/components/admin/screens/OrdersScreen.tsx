'use client';

import { useEffect, useMemo, useState, type ReactNode } from 'react';

import { money } from '@/lib/money';
import { itemsSummary, timeLabel, type AdminOrder, type OrderStatus } from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import { IconCheckCircle, IconClock, IconDownload, IconReceipt, IconXCircle } from '../icons';
import { PageHeading, StatusBadge } from '../primitives';

type StatusGroup = 'all' | 'placed' | 'working' | 'completed' | 'cancelled';

/**
 * The design's four buckets, mapped onto the real eight-status machine.
 *
 * `PENDING_PAYMENT` is deliberately absent from every bucket here - it is not
 * something staff confirm from this screen, it is settled on the Payments
 * screen against the real bank credit (CLAUDE.md section 6). A transfer order
 * only joins "Chờ xác nhận" once that happens and it becomes `PLACED`.
 */
const GROUP_STATUSES: Readonly<Record<Exclude<StatusGroup, 'all'>, readonly OrderStatus[]>> = {
  placed: ['PLACED'],
  working: ['CONFIRMED', 'PREPARING', 'READY'],
  completed: ['COMPLETED'],
  cancelled: ['CANCELLED', 'EXPIRED'],
};

const GROUP_LABELS: Readonly<Record<StatusGroup, string>> = {
  all: 'Tất cả',
  placed: 'Chờ xác nhận',
  working: 'Đang xử lý',
  completed: 'Hoàn thành',
  cancelled: 'Đã hủy',
};

type SortOption = 'newest' | 'pickup' | 'oldest';

function toSort(value: string): SortOption {
  return value === 'pickup' || value === 'oldest' ? value : 'newest';
}

function pickupTime(order: AdminOrder): number {
  const iso = order.scheduledAt ?? order.placedAt ?? order.createdAt;
  if (iso === null) {
    return Number.POSITIVE_INFINITY;
  }
  const at = new Date(iso).getTime();

  return Number.isNaN(at) ? Number.POSITIVE_INFINITY : at;
}

/**
 * Milliseconds to wait before searching.
 *
 * Long enough that typing "bánh mì" is one request rather than seven, short
 * enough that it still feels like typing.
 */
const SEARCH_DEBOUNCE_MS = 300;

interface StatProps {
  readonly group: Exclude<StatusGroup, 'all'>;
  readonly tone: string;
  readonly icon: ReactNode;
  readonly value: ReactNode;
  readonly active: boolean;
  readonly onToggle: (group: StatusGroup) => void;
}

function OrderStat({ group, tone, icon, value, active, onToggle }: StatProps) {
  return (
    <button
      type="button"
      className={active ? 'order-stat is-active' : 'order-stat'}
      onClick={() => onToggle(active ? 'all' : group)}
    >
      <span className={tone === '' ? 'order-stat-icon' : `order-stat-icon ${tone}`}>{icon}</span>
      <span className="order-stat-info">
        <span className="order-stat-name">{GROUP_LABELS[group]}</span>
        <strong>{value}</strong>
      </span>
      <span className="order-stat-arrow">›</span>
    </button>
  );
}

export function OrdersScreen() {
  const {
    orders,
    orderCounts,
    ordersBusy,
    ordersError,
    hasMoreOrders,
    loadOrders,
    loadMoreOrders,
    refreshOrders,
    newOrderIds,
    markOrdersSeen,
    notify,
    openModal,
  } = useAdminData();

  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [group, setGroup] = useState<StatusGroup>('all');
  const [sort, setSort] = useState<SortOption>('newest');

  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  // Filtering happens on the server, not over the loaded page: a busy morning
  // is more orders than one page holds, and a filter that only searched what
  // had already been fetched would quietly hide the rest.
  useEffect(() => {
    loadOrders({
      statuses: group === 'all' ? undefined : GROUP_STATUSES[group],
      search,
      newestFirst: true,
      limit: 50,
    });
  }, [loadOrders, group, search]);

  // Sorting, unlike filtering, is only ever applied to the page already in
  // hand: "giờ nhận gần nhất" has no server-side equivalent to ask for
  // (CLAUDE.md section 12 - no undocumented capability is implied here).
  const displayed = useMemo(() => {
    const copy = [...orders];
    if (sort === 'oldest') {
      copy.reverse();
    } else if (sort === 'pickup') {
      copy.sort((a, b) => pickupTime(a) - pickupTime(b));
    }

    return copy;
  }, [orders, sort]);

  const placedCount = orderCounts.PLACED ?? 0;
  const workingCount = (orderCounts.CONFIRMED ?? 0) + (orderCounts.PREPARING ?? 0) + (orderCounts.READY ?? 0);
  // Completed and cancelled totals are not something the server counts
  // (CLAUDE.md: counting every order a store has ever served would grow
  // without bound and answer a question nobody asked) - this is what is
  // actually loaded under the current filter, with the same "+" the panel
  // title already uses to say there is more than this page shows.
  const completedCount = orders.filter((order) => order.status === 'COMPLETED').length;
  const cancelledCount = orders.filter(
    (order) => order.status === 'CANCELLED' || order.status === 'EXPIRED',
  ).length;
  const more = hasMoreOrders ? '+' : '';

  const newOnScreen = orders.filter((order) => newOrderIds.has(order.id)).length;

  return (
    <>
      <PageHeading
        title="Quản lý đơn hàng"
        description="Theo dõi và xử lý đơn hàng nhanh chóng, rõ ràng."
        actions={
          <button
            type="button"
            className="btn"
            onClick={() => notify('Xuất file sẽ được triển khai cùng màn hình báo cáo.')}
          >
            <IconDownload size={15} /> Xuất danh sách
          </button>
        }
      />

      <div className="order-stats">
        <OrderStat
          group="placed"
          tone=""
          icon={<IconClock size={22} />}
          value={placedCount}
          active={group === 'placed'}
          onToggle={setGroup}
        />
        <OrderStat
          group="working"
          tone="confirmed"
          icon={<IconReceipt size={22} />}
          value={workingCount}
          active={group === 'working'}
          onToggle={setGroup}
        />
        <OrderStat
          group="completed"
          tone="completed"
          icon={<IconCheckCircle size={22} />}
          value={`${completedCount}${more}`}
          active={group === 'completed'}
          onToggle={setGroup}
        />
        <OrderStat
          group="cancelled"
          tone="cancelled"
          icon={<IconXCircle size={22} />}
          value={`${cancelledCount}${more}`}
          active={group === 'cancelled'}
          onToggle={setGroup}
        />
      </div>

      <div className="order-workspace">
        <div className="order-filters">
          <div className="tabs" role="tablist" aria-label="Lọc theo trạng thái">
            {(['all', 'placed', 'working', 'completed', 'cancelled'] as const).map((option) => (
              <button
                key={option}
                type="button"
                className={group === option ? 'selected' : undefined}
                onClick={() => setGroup(option)}
              >
                {GROUP_LABELS[option]}
              </button>
            ))}
          </div>

          <div className="order-tools">
            <input
              className="search"
              placeholder="⌕  Mã đơn, tên khách, món ăn..."
              aria-label="Tìm mã đơn, tên khách, món ăn"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
            />
            <select
              aria-label="Sắp xếp đơn hàng"
              value={sort}
              onChange={(event) => setSort(toSort(event.target.value))}
            >
              <option value="newest">Mới nhất</option>
              <option value="pickup">Giờ nhận gần nhất</option>
              <option value="oldest">Cũ nhất</option>
            </select>
            <button
              type="button"
              className="refresh-orders"
              aria-label="Làm mới danh sách"
              title="Làm mới danh sách"
              onClick={refreshOrders}
              disabled={ordersBusy}
            >
              ↻
            </button>
          </div>
        </div>

        {ordersError !== '' ? (
          <div className="panel empty">
            <strong>Không tải được danh sách đơn</strong>
            {ordersError}
          </div>
        ) : (
          <div className="panel order-table-panel">
            {newOnScreen === 0 ? null : (
              <div className="new-banner" role="status">
                <span>
                  {newOnScreen === 1
                    ? 'Có 1 đơn mới chưa xử lý.'
                    : `Có ${newOnScreen} đơn mới chưa xử lý.`}
                </span>
                <button type="button" className="table-action" onClick={() => markOrdersSeen()}>
                  Đánh dấu đã xem
                </button>
              </div>
            )}
            <div className="table-wrap">
              <table className="orders-table">
                <thead>
                  <tr>
                    <th>ĐƠN HÀNG</th>
                    <th>KHÁCH HÀNG / GIỜ NHẬN</th>
                    <th>TỔNG TIỀN</th>
                    <th>TRẠNG THÁI ĐƠN</th>
                    <th>THANH TOÁN</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {displayed.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="orders-empty">
                        {ordersBusy ? 'Đang tải...' : 'Không có đơn hàng phù hợp với bộ lọc.'}
                      </td>
                    </tr>
                  ) : (
                    displayed.map((order) => {
                      const isNew = newOrderIds.has(order.id);

                      return (
                        <tr key={order.id} className={isNew ? 'is-new' : undefined}>
                          <td>
                            <div className="order-id-cell">
                              <span className="order-thumb">
                                <IconReceipt size={18} />
                              </span>
                              <span>
                                <strong>#{order.code}</strong>
                                {isNew ? (
                                  <span className="new-pill" role="status">
                                    Mới
                                  </span>
                                ) : null}
                                <small>{itemsSummary(order.items)}</small>
                              </span>
                            </div>
                          </td>
                          <td>
                            <strong>{order.contactName}</strong>
                            <small>{timeLabel(order.scheduledAt ?? order.placedAt ?? order.createdAt)} · Hôm nay</small>
                          </td>
                          <td className="amount">{money(order.total)}</td>
                          <td>
                            <StatusBadge status={order.status} />
                          </td>
                          <td>
                            {order.paymentMethod === null ? (
                              <span className="cell-sub warn">Chưa ghi nhận thanh toán</span>
                            ) : (
                              <>
                                <span className={order.paymentStatus === 'PAID' ? 'status done' : 'status waiting'}>
                                  {order.paymentStatus === 'PAID' ? 'Đã thanh toán' : 'Chưa thanh toán'}
                                </span>
                                <small className="cell-sub">
                                  {order.paymentMethod === 'BANK_TRANSFER' ? 'Chuyển khoản VietQR' : 'Tiền mặt'}
                                </small>
                              </>
                            )}
                          </td>
                          <td>
                            <button
                              type="button"
                              className="table-action detail-action"
                              onClick={() => openModal({ kind: 'order', orderId: order.id })}
                            >
                              Chi tiết <span>→</span>
                            </button>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            {hasMoreOrders ? (
              <div className="load-more">
                <button type="button" className="btn" onClick={loadMoreOrders} disabled={ordersBusy}>
                  Tải thêm đơn
                </button>
              </div>
            ) : null}
          </div>
        )}
      </div>
    </>
  );
}
