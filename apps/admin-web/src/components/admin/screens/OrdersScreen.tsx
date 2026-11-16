'use client';

import { useState } from 'react';

import { ORDER_STATUS_LABELS } from '@/data/admin-demo';

import { useAdminData } from '../AdminDataProvider';
import { OrdersTable, PageHeading } from '../primitives';

const STATUS_FILTERS = ['all', 'waiting', 'preparing', 'ready', 'done', 'cancelled'] as const;
type StatusFilter = (typeof STATUS_FILTERS)[number];

/** Narrow the select value without an unsafe cast (CLAUDE.md section 12). */
function toStatusFilter(value: string): StatusFilter {
  return STATUS_FILTERS.find((candidate) => candidate === value) ?? 'all';
}

export function OrdersScreen() {
  const { orders, notify } = useAdminData();
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

  const needle = query.toLowerCase();
  const visible = orders.filter(
    (order) =>
      (statusFilter === 'all' || order.status === statusFilter) &&
      `${order.id} ${order.name} ${order.items}`.toLowerCase().includes(needle),
  );

  return (
    <>
      <PageHeading
        title="Quản lý đơn hàng"
        description="Xác nhận, chuẩn bị và hoàn tất các đơn đặt đồ ăn sáng."
        actions={
          <button
            type="button"
            className="btn"
            onClick={() => notify('Xuất file sẽ được triển khai khi kết nối API Laravel.')}
          >
            ↓ Xuất danh sách
          </button>
        }
      />

      <div className="search-row">
        <input
          className="search"
          placeholder="Tìm mã đơn, khách hàng, món ăn..."
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <select
          className="filter"
          value={statusFilter}
          onChange={(event) => setStatusFilter(toStatusFilter(event.target.value))}
        >
          <option value="all">Tất cả trạng thái</option>
          {Object.entries(ORDER_STATUS_LABELS).map(([status, label]) => (
            <option key={status} value={status}>
              {label}
            </option>
          ))}
        </select>
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>Danh sách đơn ({visible.length})</h3>
          <span style={{ color: '#a0aaba', fontSize: 11 }}>
            Nhấn “Chi tiết” để cập nhật trạng thái
          </span>
        </div>
        <OrdersTable orders={visible} />
      </section>
    </>
  );
}
