'use client';

import Link from 'next/link';

import { money } from '@/lib/money';
import {
  itemsSummary,
  timeLabel,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  type AdminOrder,
} from '@/lib/orders-api';

import { StatusBadge } from '../primitives';

export interface RecentOrdersProps {
  readonly orders: readonly AdminOrder[];
  /** Orders that arrived since this screen was opened. */
  readonly newOrderIds: ReadonlySet<string>;
  readonly onOpen: (orderId: string) => void;
  /** Kitchen accounts have no payment or pricing access at all. */
  readonly canSeeMoney: boolean;
}

/** Paid / waiting / refunding, as one word and one tone. */
function paymentTone(order: AdminOrder): string {
  switch (order.paymentStatus) {
    case 'PAID':
      return 'ok';
    case 'PROCESSING':
      return 'warn';
    case 'FAILED':
      return 'bad';
    case null:
      return 'bad';
    default:
      return 'idle';
  }
}

/**
 * The orders that just came in.
 *
 * Reads the list the shell is already polling every five seconds rather than
 * asking for its own - this is the live queue, not a figure for the window
 * the rest of the screen reports on, and saying so in the subtitle is clearer
 * than quietly mixing the two.
 *
 * One table, not two layouts: below 760px the CSS lays each row out as a
 * card, and the explicit ARIA roles keep it a table for a screen reader even
 * once `display` is no longer `table-row`.
 */
export function RecentOrders({ orders, newOrderIds, onOpen, canSeeMoney }: RecentOrdersProps) {
  return (
    <section className="panel orders-panel" aria-labelledby="recent-orders-title">
      <div className="panel-head">
        <div>
          <h2 id="recent-orders-title">Đơn hàng gần đây</h2>
          <p className="panel-sub">Cập nhật trực tiếp, không phụ thuộc bộ lọc thời gian</p>
        </div>
        <Link className="panel-link" href="/orders">
          Xem tất cả <span aria-hidden="true">→</span>
        </Link>
      </div>

      {orders.length === 0 ? (
        <p className="panel-empty">Chưa có đơn hàng nào.</p>
      ) : (
        <div className="orders-table-wrap">
          <table className="orders-table" role="table">
            <thead role="rowgroup">
              <tr role="row">
                <th role="columnheader" scope="col">
                  Mã đơn
                </th>
                <th role="columnheader" scope="col">
                  Khách hàng
                </th>
                <th role="columnheader" scope="col">
                  Giờ nhận
                </th>
                {canSeeMoney ? (
                  <th role="columnheader" scope="col">
                    Tổng tiền
                  </th>
                ) : null}
                <th role="columnheader" scope="col">
                  Trạng thái đơn
                </th>
                {canSeeMoney ? (
                  <th role="columnheader" scope="col">
                    Thanh toán
                  </th>
                ) : null}
                <th role="columnheader" scope="col">
                  <span className="sr-only">Thao tác</span>
                </th>
              </tr>
            </thead>
            <tbody role="rowgroup">
              {orders.map((order) => {
                const isNew = newOrderIds.has(order.id);

                return (
                  <tr role="row" key={order.id} className={isNew ? 'is-new' : undefined}>
                    <td role="cell" data-label="Mã đơn">
                      <strong>{order.code}</strong>
                      {isNew ? (
                        <span className="new-pill" role="status">
                          Mới
                        </span>
                      ) : null}
                    </td>
                    <td role="cell" data-label="Khách hàng">
                      <strong>{order.contactName}</strong>
                      <small className="cell-sub">{itemsSummary(order.items)}</small>
                    </td>
                    <td role="cell" data-label="Giờ nhận" className="num">
                      {timeLabel(order.scheduledAt ?? order.placedAt ?? order.createdAt)}
                    </td>
                    {canSeeMoney ? (
                      <td role="cell" data-label="Tổng tiền" className="num">
                        <strong>{money(order.total)}</strong>
                      </td>
                    ) : null}
                    <td role="cell" data-label="Trạng thái đơn">
                      <StatusBadge status={order.status} />
                    </td>
                    {canSeeMoney ? (
                      <td role="cell" data-label="Thanh toán">
                        <span className={`pay-tag pay-${paymentTone(order)}`}>
                          {order.paymentStatus === null
                            ? 'Chưa ghi nhận'
                            : PAYMENT_STATUS_LABELS[order.paymentStatus]}
                        </span>
                        {order.paymentMethod === null ? null : (
                          <small className="cell-sub">
                            {PAYMENT_METHOD_LABELS[order.paymentMethod]}
                          </small>
                        )}
                      </td>
                    ) : null}
                    <td role="cell" className="row-end">
                      <button type="button" className="row-btn" onClick={() => onOpen(order.id)}>
                        Chi tiết
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
