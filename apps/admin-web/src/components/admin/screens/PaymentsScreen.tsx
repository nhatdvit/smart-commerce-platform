'use client';

import { useEffect, useMemo, useState } from 'react';

import { money } from '@/lib/money';
import {
  PAYMENT_METHOD_LABELS,
  type AdminPayment,
  type PaymentMethod,
  type PaymentStatus,
} from '@/lib/payments-api';

import { useAdminData } from '../AdminDataProvider';
import { IconClock, IconReceipt, IconSettings, IconTrendUp, IconWallet } from '../icons';
import { Metric, PageHeading } from '../primitives';

/** "20/11/2026 08:40" from an ISO instant, or a dash when there is none. */
function dateTime(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime())
    ? '—'
    : at.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' });
}

type PaymentGroup = 'all' | 'unpaid' | 'paid' | 'refunding' | 'refunded';

/**
 * The design's four buckets, mapped onto the real six-status machine
 * (CLAUDE.md section 6: PENDING, PROCESSING, PAID, FAILED,
 * PARTIALLY_REFUNDED, REFUNDED).
 *
 * `FAILED` reads as "chưa thanh toán" alongside PENDING/PROCESSING: from a
 * glance at the queue, money not yet collected is one bucket, whatever the
 * reason. `isOutstanding()`/`isSettled()` on the backend's own PaymentStatus
 * enum draw the same two-sided line; this only splits the settled side
 * further, into "đang hoàn tiền" and "đã hoàn tiền", which the design shows
 * as separate tabs.
 */
const GROUP_STATUSES: Readonly<Record<Exclude<PaymentGroup, 'all'>, readonly PaymentStatus[]>> = {
  unpaid: ['PENDING', 'PROCESSING', 'FAILED'],
  paid: ['PAID'],
  refunding: ['PARTIALLY_REFUNDED'],
  refunded: ['REFUNDED'],
};

const GROUP_LABELS: Readonly<Record<PaymentGroup, string>> = {
  all: 'Tất cả',
  unpaid: 'Chưa thanh toán',
  paid: 'Đã thanh toán',
  refunding: 'Đang hoàn tiền',
  refunded: 'Đã hoàn tiền',
};

const GROUP_TONE: Readonly<Record<Exclude<PaymentGroup, 'all'>, string>> = {
  unpaid: 'waiting',
  paid: 'ready',
  refunding: 'preparing',
  refunded: 'done',
};

function groupFor(status: PaymentStatus): Exclude<PaymentGroup, 'all'> {
  if (status === 'PAID') {
    return 'paid';
  }
  if (status === 'PARTIALLY_REFUNDED') {
    return 'refunding';
  }
  if (status === 'REFUNDED') {
    return 'refunded';
  }

  return 'unpaid';
}

/**
 * The simplified label this screen shows - never the real six-value one,
 * except for `PROCESSING`: a customer's own "I have transferred" report
 * (CLAUDE.md section 6, decision dated 2026-11-24) is exactly the thing this
 * queue exists to surface, so it keeps its own distinct wording rather than
 * collapsing into the same "chưa thanh toán" text as a plain, unreported
 * PENDING row - both still belong to the same "unpaid" tab/tone.
 */
function statusLabel(status: PaymentStatus): string {
  return status === 'PROCESSING' ? 'Chờ xác nhận thanh toán' : GROUP_LABELS[groupFor(status)];
}

function SimplePaymentStatusBadge({ status }: { readonly status: PaymentStatus }) {
  const group = groupFor(status);

  return <span className={`status ${GROUP_TONE[group]}`}>{statusLabel(status)}</span>;
}

type MethodFilter = 'all' | PaymentMethod;

/** What staff actually collected, or what is owed when nothing was confirmed yet. */
function collected(payment: AdminPayment): number {
  return payment.confirmedAmount ?? payment.amount;
}

export function PaymentsScreen() {
  const {
    payments,
    paymentsBusy,
    paymentsError,
    hasMorePayments,
    loadPayments,
    loadMorePayments,
    openModal,
  } = useAdminData();

  const [group, setGroup] = useState<PaymentGroup>('all');
  const [method, setMethod] = useState<MethodFilter>('all');

  // Loaded once, unfiltered: the four cards above are a summary of the whole
  // queue, not of whichever tab happens to be selected below, so they must
  // not depend on `group`/`method` the way the table rows do. Filtering
  // happens client-side over this one page instead of a second server round
  // trip per tab - a real shop's daily payment count is small the same way
  // its daily order count is (CLAUDE.md: no store-wide totals endpoint exists
  // yet, this is Reporting module territory and explicitly out of MVP scope).
  useEffect(() => {
    loadPayments({});
  }, [loadPayments]);

  const totalCollected = payments
    .filter((payment) => payment.status === 'PAID')
    .reduce((sum, payment) => sum + collected(payment), 0);
  const cashCollected = payments
    .filter((payment) => payment.status === 'PAID' && payment.method === 'CASH')
    .reduce((sum, payment) => sum + collected(payment), 0);
  const transferCollected = payments
    .filter((payment) => payment.status === 'PAID' && payment.method === 'BANK_TRANSFER')
    .reduce((sum, payment) => sum + collected(payment), 0);
  const outstanding = payments.filter(
    (payment) => payment.status === 'PENDING' || payment.status === 'PROCESSING',
  );
  const outstandingTotal = outstanding.reduce((sum, payment) => sum + payment.amount, 0);
  const more = hasMorePayments ? '+' : '';

  const displayed = useMemo(
    () =>
      payments.filter(
        (payment) =>
          (group === 'all' || GROUP_STATUSES[group].includes(payment.status)) &&
          (method === 'all' || payment.method === method),
      ),
    [payments, group, method],
  );

  return (
    <>
      <PageHeading
        title="Thanh toán"
        description="Đối soát tiền mặt và chuyển khoản VietQR của cửa hàng."
        actions={
          <>
            <button
              type="button"
              className="btn"
              onClick={() => openModal({ kind: 'bank-account-form' })}
            >
              <IconWallet size={15} /> Tài khoản ngân hàng
            </button>
            <button
              type="button"
              className="btn"
              onClick={() => openModal({ kind: 'payment-methods' })}
            >
              <IconSettings size={15} /> Phương thức thanh toán
            </button>
          </>
        }
      />

      <div className="metrics">
        <Metric
          title="Tổng tiền đã thu"
          value={`${money(totalCollected)}${more}`}
          icon={<IconTrendUp size={18} />}
          color="#fff3d9"
          foot="Không bao gồm khoản đã hoàn"
        />
        <Metric
          title="Tiền mặt đã thu"
          value={`${money(cashCollected)}${more}`}
          icon={<IconWallet size={18} />}
          color="#e3f7ec"
          foot="Đã xác nhận thu tiền"
        />
        <Metric
          title="VietQR đã thu"
          value={`${money(transferCollected)}${more}`}
          icon={<IconReceipt size={18} />}
          color="#e9f0ff"
          foot="Đã đối soát ngân hàng"
        />
        <Metric
          title="Còn chờ thu"
          value={`${money(outstandingTotal)}${more}`}
          icon={<IconClock size={18} />}
          color="#fff0e9"
          foot={`${outstanding.length}${more} đơn chưa thanh toán`}
        />
      </div>

      <div className="order-filters">
        <div className="tabs" role="tablist" aria-label="Lọc theo trạng thái">
          {(['all', 'unpaid', 'paid', 'refunding', 'refunded'] as const).map((option) => (
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
          <select
            aria-label="Lọc theo phương thức"
            value={method}
            onChange={(event) => setMethod(event.target.value as MethodFilter)}
          >
            <option value="all">Tất cả phương thức</option>
            <option value="CASH">Tiền mặt</option>
            <option value="BANK_TRANSFER">VietQR</option>
          </select>
        </div>
      </div>

      <div className="panel order-table-panel">
        <div className="panel-title order-table-title">
          <h3>Giao dịch & đối soát</h3>
          <span className="panel-note">
            {displayed.length} thanh toán{hasMorePayments ? '+' : ''}
          </span>
        </div>

        {paymentsError !== '' ? (
          <div className="panel empty">
            <strong>Không tải được danh sách</strong>
            {paymentsError}
          </div>
        ) : (
          <div className="table-wrap">
            <table className="data-table payments-table">
              <thead>
                <tr>
                  <th>MÃ ĐƠN</th>
                  <th>KHÁCH HÀNG</th>
                  <th>PHƯƠNG THỨC</th>
                  <th>SỐ TIỀN</th>
                  <th>TRẠNG THÁI</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {displayed.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="orders-empty">
                      {paymentsBusy ? 'Đang tải...' : 'Không có giao dịch phù hợp với bộ lọc.'}
                    </td>
                  </tr>
                ) : (
                  displayed.map((payment: AdminPayment) => {
                    const isOutstanding =
                      payment.status === 'PENDING' || payment.status === 'PROCESSING';

                    return (
                      <tr key={payment.id}>
                        <td>
                          <strong>{payment.orderCode}</strong>
                          {payment.confirmedAt === null ? null : (
                            <>
                              <br />
                              <span className="cell-sub">
                                đã thu {dateTime(payment.confirmedAt)}
                              </span>
                            </>
                          )}
                        </td>
                        <td>{payment.customerName}</td>
                        <td>{PAYMENT_METHOD_LABELS[payment.method]}</td>
                        <td className="amount">{money(payment.amount)}</td>
                        <td>
                          <SimplePaymentStatusBadge status={payment.status} />
                        </td>
                        <td>
                          <button
                            type="button"
                            className={isOutstanding ? 'row-btn primary' : 'row-btn'}
                            onClick={() => openModal({ kind: 'payment', paymentId: payment.id })}
                          >
                            {isOutstanding ? 'Xác nhận thu tiền' : 'Chi tiết →'}
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        )}

        {!hasMorePayments ? null : (
          <div className="load-more">
            <button
              type="button"
              className="btn"
              onClick={loadMorePayments}
              disabled={paymentsBusy}
            >
              Tải thêm
            </button>
          </div>
        )}
      </div>

      <p className="notice">
        Chỉ nhân viên được cấp quyền mới được đánh dấu đã thu, và chỉ sau khi đối chiếu khoản tiền
        thật trong ngân hàng hoặc tại quầy. Mã QR hiển thị, ảnh chụp, hoặc khách báo "đã chuyển"
        không phải là bằng chứng thanh toán.
      </p>
    </>
  );
}
