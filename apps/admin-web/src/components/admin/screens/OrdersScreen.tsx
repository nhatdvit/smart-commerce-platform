'use client';

import { useEffect, useMemo, useState } from 'react';

import {
  DEFAULT_ORDER_QUERY,
  ORDER_STATUSES,
  ORDER_STATUS_LABELS,
  type OrderStatus,
} from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import { IconDownload } from '../icons';
import { OrdersTable, PageHeading } from '../primitives';

type StatusFilter = OrderStatus | 'all';

/** Narrow the select value without an unsafe cast (CLAUDE.md section 12). */
function toStatusFilter(value: string): StatusFilter {
  return ORDER_STATUSES.find((candidate) => candidate === value) ?? 'all';
}

/**
 * Milliseconds to wait before searching.
 *
 * Long enough that typing "bánh mì" is one request rather than seven, short
 * enough that it still feels like typing.
 */
const SEARCH_DEBOUNCE_MS = 300;

export function OrdersScreen() {
  const {
    orders,
    orderCounts,
    ordersBusy,
    ordersError,
    hasMoreOrders,
    loadOrders,
    loadMoreOrders,
    notify,
  } = useAdminData();

  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  /** The debounced copy of `query`; only this one reaches the server. */
  const [search, setSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  // Filtering happens on the server, not over the loaded page: a busy morning
  // is more orders than one page holds, and a filter that only searched what
  // had already been fetched would quietly hide the rest.
  const statuses = useMemo<readonly OrderStatus[] | undefined>(
    () => (statusFilter === 'all' ? undefined : [statusFilter]),
    [statusFilter],
  );

  useEffect(() => {
    loadOrders({ ...DEFAULT_ORDER_QUERY, statuses, search });
  }, [loadOrders, statuses, search]);

  const openTotal = Object.values(orderCounts).reduce((total, count) => total + count, 0);

  return (
    <>
      <PageHeading
        title="Quản lý đơn hàng"
        description="Xác nhận, chuẩn bị và hoàn tất các đơn đặt đồ ăn sáng."
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

      <div className="search-row">
        <input
          className="search"
          placeholder="Tìm mã đơn, khách hàng, món ăn..."
          aria-label="Tìm mã đơn, khách hàng, món ăn"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <select
          className="filter"
          aria-label="Lọc theo trạng thái"
          value={statusFilter}
          onChange={(event) => setStatusFilter(toStatusFilter(event.target.value))}
        >
          <option value="all">Tất cả trạng thái</option>
          {ORDER_STATUSES.map((status) => (
            <option key={status} value={status}>
              {ORDER_STATUS_LABELS[status]}
              {orderCounts[status] === undefined ? '' : ` (${orderCounts[status]})`}
            </option>
          ))}
        </select>
      </div>

      {/*
       * A list that could not be loaded is not an empty list. Saying so keeps
       * staff from concluding the morning has no orders in it.
       */}
      {ordersError === '' ? null : (
        <div className="panel empty">
          <strong>Không tải được danh sách đơn</strong>
          {ordersError}
        </div>
      )}

      {ordersError !== '' ? null : (
        <section className="panel">
          <div className="panel-title">
            <h3>
              Danh sách đơn ({orders.length}
              {hasMoreOrders ? '+' : ''})
            </h3>
            <span className="panel-note" aria-live="polite">
              {ordersBusy
                ? 'Đang tải...'
                : `${openTotal} đơn đang mở · nhấn “Chi tiết” để cập nhật trạng thái`}
            </span>
          </div>

          <OrdersTable orders={orders} />

          {hasMoreOrders ? (
            <div className="load-more">
              <button type="button" className="btn" onClick={loadMoreOrders} disabled={ordersBusy}>
                Tải thêm đơn
              </button>
            </div>
          ) : null}
        </section>
      )}
    </>
  );
}
