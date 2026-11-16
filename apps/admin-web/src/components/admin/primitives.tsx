'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { ORDER_STATUS_LABELS, type DemoOrder, type OrderStatus } from '@/data/admin-demo';
import { money } from '@/lib/money';

import { useAdminData } from './AdminDataProvider';

/**
 * "Thứ Hai, 16 tháng 11, 2026" - the eyebrow above every page title.
 *
 * Resolved after mount on purpose: it depends on today's date in the viewer's
 * timezone, and formatting it during the server render would bake the build
 * date into the static HTML and then disagree with the browser.
 */
export function useTodayLabel(): string {
  const [label, setLabel] = useState('');

  useEffect(() => {
    setLabel(
      new Date().toLocaleDateString('vi-VN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }),
    );
  }, []);

  return label;
}

interface PageHeadingProps {
  readonly title: string;
  readonly description?: string;
  readonly actions?: ReactNode;
}

export function PageHeading({ title, description, actions }: PageHeadingProps) {
  const today = useTodayLabel();

  return (
    <div className="page-heading">
      <div>
        <div className="eyebrow">{today}</div>
        <h1>{title}</h1>
        {description === undefined ? null : <p>{description}</p>}
      </div>
      <div className="heading-actions">{actions}</div>
    </div>
  );
}

interface MetricProps {
  readonly title: string;
  readonly value: ReactNode;
  readonly icon: string;
  /** Tint behind the icon, from the design's metric palette. */
  readonly color: string;
  readonly foot: ReactNode;
}

export function Metric({ title, value, icon, color, foot }: MetricProps) {
  return (
    <article className="metric">
      <div className="metric-top">
        <span>{title}</span>
        <div className="metric-icon" style={{ background: color }}>
          {icon}
        </div>
      </div>
      <strong>{value}</strong>
      <div className="metric-foot">{foot}</div>
    </article>
  );
}

export function StatusBadge({ status }: { status: OrderStatus }) {
  return <span className={`status ${status}`}>{ORDER_STATUS_LABELS[status]}</span>;
}

/** The order list, shared by the dashboard and the orders screen. */
export function OrdersTable({ orders }: { orders: readonly DemoOrder[] }) {
  const { openModal } = useAdminData();

  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>MÃ ĐƠN</th>
            <th>KHÁCH HÀNG</th>
            <th>THỜI GIAN NHẬN</th>
            <th>TỔNG TIỀN</th>
            <th>TRẠNG THÁI</th>
            <th />
          </tr>
        </thead>
        <tbody>
          {orders.length === 0 ? (
            <tr>
              <td colSpan={6} className="empty">
                Không có đơn phù hợp
              </td>
            </tr>
          ) : (
            orders.map((order) => (
              <tr key={order.id}>
                <td>
                  <strong>{order.id}</strong>
                </td>
                <td>
                  <strong>{order.name}</strong>
                  <br />
                  <span style={{ color: '#9ca6b6', fontSize: 10 }}>{order.items}</span>
                </td>
                <td>{order.time}</td>
                <td>
                  <strong>{money(order.total)}</strong>
                </td>
                <td>
                  <StatusBadge status={order.status} />
                </td>
                <td>
                  <button
                    type="button"
                    className="table-action"
                    onClick={() => openModal({ kind: 'order', orderId: order.id })}
                  >
                    Chi tiết →
                  </button>
                </td>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
