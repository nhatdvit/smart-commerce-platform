'use client';

import Link from 'next/link';

import type { PaymentTotals } from '@/lib/dashboard-metrics';
import { money } from '@/lib/money';

export interface PaymentOverviewProps {
  readonly payment: PaymentTotals;
  readonly rangeLabel: string;
}

/** Share of the collected total, for the two-segment bar. */
function share(part: number, whole: number): number {
  return whole === 0 ? 0 : (part / whole) * 100;
}

/**
 * What the shop has actually taken, and what it is still owed.
 *
 * Every figure here is at order level, from payments a named staff member
 * confirmed (`PAID`). Two things are deliberately NOT in it:
 *
 *   - A customer's "tôi đã chuyển khoản" (`PROCESSING`) is listed under what
 *     is still owed, never under what has been taken. It is a notification,
 *     not money in the account (CLAUDE.md section 6).
 *   - Tips, over-transfers and refunds are absent rather than guessed. They
 *     live on the payment row as `confirmed_amount`, which no endpoint yet
 *     totals over a date window - so the panel says so instead of inventing a
 *     number (spec section 5, backend request 4).
 */
export function PaymentOverview({ payment, rangeLabel }: PaymentOverviewProps) {
  const { collected, collectedCash, collectedTransfer } = payment;

  return (
    <section className="panel pay-panel" aria-labelledby="payment-overview-title">
      <div className="panel-head">
        <div>
          <h2 id="payment-overview-title">Thanh toán trong kỳ</h2>
          <p className="panel-sub">{rangeLabel}</p>
        </div>
        <Link className="panel-link" href="/payments">
          Đối soát <span aria-hidden="true">→</span>
        </Link>
      </div>

      <div className="pay-total">
        <span className="pay-label">Đã thu</span>
        <strong className="pay-value">{money(collected)}</strong>
        <span className="panel-sub">{payment.collectedCount} đơn đã xác nhận thu</span>
      </div>

      {collected === 0 ? null : (
        <>
          <div
            className="pay-bar"
            role="img"
            aria-label={`Tiền mặt ${money(collectedCash)}, chuyển khoản ${money(collectedTransfer)}`}
          >
            <span
              className="pay-seg pay-cash"
              style={{ width: `${share(collectedCash, collected)}%` }}
            />
            <span
              className="pay-seg pay-transfer"
              style={{ width: `${share(collectedTransfer, collected)}%` }}
            />
          </div>

          <ul className="pay-split">
            <li>
              <span className="legend-dot pay-cash" aria-hidden="true" />
              Tiền mặt
              <b>{money(collectedCash)}</b>
            </li>
            <li>
              <span className="legend-dot pay-transfer" aria-hidden="true" />
              Chuyển khoản
              <b>{money(collectedTransfer)}</b>
            </li>
          </ul>
        </>
      )}

      <div className="pay-rows">
        <div className="pay-row">
          <span>
            Còn phải thu
            <small>Đơn chưa hủy, chưa xác nhận thu</small>
          </span>
          <b>{money(payment.outstanding)}</b>
        </div>

        {payment.awaitingReconciliationCount === 0 ? null : (
          <div className="pay-row is-warn">
            <span>
              Khách đã báo chuyển khoản
              <small>Chờ đối soát · chưa tính là đã thu</small>
            </span>
            <b>
              {money(payment.awaitingReconciliation)}
              <em>{payment.awaitingReconciliationCount} đơn</em>
            </b>
          </div>
        )}

        {payment.refundingCount === 0 ? null : (
          <div className="pay-row">
            <span>
              Đơn đang hoàn tiền
              <small>Số tiền hoàn chưa được API tổng hợp</small>
            </span>
            <b>{payment.refundingCount} đơn</b>
          </div>
        )}
      </div>

      <p className="panel-note">
        Tính theo đơn hàng trong kỳ. Tiền tip và tiền khách chuyển thừa không nằm trong doanh thu
        bán hàng.
      </p>
    </section>
  );
}
