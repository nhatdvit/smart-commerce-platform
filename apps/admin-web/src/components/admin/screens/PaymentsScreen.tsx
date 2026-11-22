'use client';

import { useEffect, useState } from 'react';

import { money } from '@/lib/money';
import {
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  paymentStatusTone,
  type AdminPayment,
  type PaymentListQuery,
} from '@/lib/payments-api';

import { useAdminData } from '../AdminDataProvider';
import { IconClock, IconSettings, IconWallet } from '../icons';
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

type QueueFilter = 'needs_attention' | 'all';

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
  const [filter, setFilter] = useState<QueueFilter>('needs_attention');

  useEffect(() => {
    const query: PaymentListQuery = filter === 'needs_attention' ? { needsAttention: true } : {};
    loadPayments(query);
  }, [filter, loadPayments]);

  const needingAttention = payments.filter((payment) => payment.needsAttention).length;
  const cashOutstanding = payments.filter(
    (payment) => payment.method === 'CASH' && payment.status === 'PENDING',
  ).length;
  const transfersOutstanding = payments.filter(
    (payment) => payment.method === 'BANK_TRANSFER' && payment.status === 'PENDING',
  ).length;

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
          title="Cần xử lý"
          value={`${needingAttention} đơn`}
          icon={<IconClock size={18} />}
          color="#fff0e9"
          foot="Trong danh sách đang xem"
        />
        <Metric
          title="Tiền mặt chưa thu"
          value={`${cashOutstanding} đơn`}
          icon={<IconWallet size={18} />}
          color="#fff3d9"
          foot="Trong danh sách đang xem"
        />
        <Metric
          title="Chuyển khoản chưa xác nhận"
          value={`${transfersOutstanding} đơn`}
          icon={<IconWallet size={18} />}
          color="#e9f0ff"
          foot="Trong danh sách đang xem"
        />
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>Hàng đợi đối soát</h3>
          <select
            className="filter"
            aria-label="Lọc hàng đợi"
            value={filter}
            onChange={(event) =>
              setFilter(event.target.value === 'all' ? 'all' : 'needs_attention')
            }
          >
            <option value="needs_attention">Cần xử lý</option>
            <option value="all">Tất cả</option>
          </select>
          <span className="panel-note" aria-live="polite">
            {paymentsBusy ? 'Đang tải...' : `${payments.length} thanh toán`}
          </span>
        </div>

        {paymentsError !== '' ? (
          <div className="empty">
            <strong>Không tải được danh sách</strong>
            {paymentsError}
          </div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>MÃ ĐƠN</th>
                  <th>PHƯƠNG THỨC</th>
                  <th>SỐ TIỀN</th>
                  <th>MÃ THAM CHIẾU</th>
                  <th>TRẠNG THÁI</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {payments.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="empty">
                      {filter === 'needs_attention'
                        ? 'Không có thanh toán cần xử lý'
                        : 'Chưa có thanh toán nào'}
                    </td>
                  </tr>
                ) : (
                  payments.map((payment: AdminPayment) => (
                    <tr key={payment.id}>
                      <td>
                        <strong>{payment.orderCode}</strong>
                        {payment.confirmedAt === null ? null : (
                          <>
                            <br />
                            <span className="cell-sub">đã thu {dateTime(payment.confirmedAt)}</span>
                          </>
                        )}
                      </td>
                      <td>{PAYMENT_METHOD_LABELS[payment.method]}</td>
                      <td>
                        <strong>{money(payment.amount)}</strong>
                      </td>
                      <td>{payment.transferReference ?? '—'}</td>
                      <td>
                        <span className={`status ${paymentStatusTone(payment.status)}`}>
                          {PAYMENT_STATUS_LABELS[payment.status]}
                        </span>
                        {payment.needsAttention ? (
                          <span className="status waiting">Cần xử lý</span>
                        ) : null}
                      </td>
                      <td>
                        <button
                          type="button"
                          className="table-action"
                          onClick={() => openModal({ kind: 'payment', paymentId: payment.id })}
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
        )}

        {!hasMorePayments ? null : (
          <button type="button" className="btn" onClick={loadMorePayments} disabled={paymentsBusy}>
            Tải thêm
          </button>
        )}
      </section>

      <p className="notice">
        Chỉ nhân viên được cấp quyền mới được đánh dấu đã thu, và chỉ sau khi đối chiếu khoản tiền
        thật trong ngân hàng hoặc tại quầy. Mã QR hiển thị, ảnh chụp, hoặc khách báo "đã chuyển"
        không phải là bằng chứng thanh toán.
      </p>
    </>
  );
}
