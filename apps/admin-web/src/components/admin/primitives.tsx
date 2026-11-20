'use client';

import { useEffect, useState, type ReactNode } from 'react';

import { money } from '@/lib/money';
import {
  ORDER_STATUS_LABELS,
  PAYMENT_METHOD_LABELS,
  itemsSummary,
  statusTone,
  timeLabel,
  type AdminOrder,
  type OrderStatus,
} from '@/lib/orders-api';

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
  /** Line icon for the tinted square; see ./icons. */
  readonly icon: ReactNode;
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

/**
 * The status badge.
 *
 * The tone is chosen by `statusTone`, not by the status name: eight server
 * statuses share five colours, because what a glance needs to know is
 * "waiting, working, ready, done, or gone wrong".
 */
export function StatusBadge({ status }: { status: OrderStatus }) {
  return <span className={`status ${statusTone(status)}`}>{ORDER_STATUS_LABELS[status]}</span>;
}

/**
 * How this order is paid, under the total.
 *
 * Shown because it is what tells a cashier whether there is money to collect
 * at handover, and it is NOT inferred from the order's status: an order can be
 * complete with its payment still pending (CLAUDE.md section 6).
 */
function PaymentHint({ order }: { order: AdminOrder }) {
  if (order.paymentMethod === null) {
    return <span className="cell-sub warn">Chưa ghi nhận thanh toán</span>;
  }

  const method = PAYMENT_METHOD_LABELS[order.paymentMethod];

  return (
    <span className={order.paymentStatus === 'PAID' ? 'cell-sub paid' : 'cell-sub'}>
      {order.paymentStatus === 'PAID' ? `${method} · đã thu` : `${method} · chưa thu`}
    </span>
  );
}

/** The order list, shared by the dashboard and the orders screen. */
export function OrdersTable({ orders }: { orders: readonly AdminOrder[] }) {
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
                  <strong>{order.code}</strong>
                </td>
                <td>
                  <strong>{order.contactName}</strong>
                  <br />
                  <span className="cell-sub">{itemsSummary(order.items)}</span>
                </td>
                {/*
                 * The time the customer is coming for it, which is what the
                 * counter works to. An order with no requested time falls back
                 * to when it was placed.
                 */}
                <td>{timeLabel(order.scheduledAt ?? order.placedAt ?? order.createdAt)}</td>
                <td>
                  <strong>{money(order.total)}</strong>
                  <br />
                  <PaymentHint order={order} />
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
