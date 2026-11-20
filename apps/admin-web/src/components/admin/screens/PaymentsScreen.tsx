'use client';

import { useEffect } from 'react';

import { money } from '@/lib/money';
import {
  DEFAULT_ORDER_QUERY,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  type AdminOrder,
} from '@/lib/orders-api';

import { useAdminData } from '../AdminDataProvider';
import { IconClock, IconReceipt, IconTrendUp, IconWallet } from '../icons';
import { Metric, PageHeading } from '../primitives';

/**
 * The payment's own status, never the order's.
 *
 * An order can be complete with its payment still pending - that is an
 * uncollected cash order - so this cell reads the payment and nothing else
 * (CLAUDE.md section 6).
 */
function PaymentCell({ order }: { order: AdminOrder }) {
  if (order.paymentStatus === null) {
    return <span className="status cancelled">Chưa ghi nhận</span>;
  }

  if (order.paymentStatus === 'PAID') {
    return <span className="status ready">Đã thu</span>;
  }

  return <span className="status waiting">{PAYMENT_STATUS_LABELS[order.paymentStatus]}</span>;
}

export function PaymentsScreen() {
  const { orders, openModal, notifyAction, loadOrders } = useAdminData();

  useEffect(() => {
    loadOrders(DEFAULT_ORDER_QUERY);
  }, [loadOrders]);

  return (
    <>
      <PageHeading
        title="Thanh toán"
        description="Theo dõi tiền mặt và chuyển khoản VietQR của cửa hàng."
        actions={
          <button type="button" className="btn" onClick={() => notifyAction('tài khoản ngân hàng')}>
            <IconWallet size={15} /> Tài khoản ngân hàng
          </button>
        }
      />

      <div className="metrics">
        <Metric
          title="Đã thu hôm nay"
          value="1.285.000đ"
          icon={<IconTrendUp size={18} />}
          color="#e9f8f0"
          foot="Dữ liệu minh họa"
        />
        <Metric
          title="Tiền mặt"
          value="485.000đ"
          icon={<IconReceipt size={18} />}
          color="#fff3d9"
          foot="Dữ liệu minh họa"
        />
        <Metric
          title="Chuyển khoản"
          value="800.000đ"
          icon={<IconWallet size={18} />}
          color="#e9f0ff"
          foot="Dữ liệu minh họa"
        />
        <Metric
          title="Chờ đối soát"
          value={`${orders.filter((order) => order.paymentStatus !== 'PAID').length} đơn`}
          icon={<IconClock size={18} />}
          color="#fff0e9"
          foot="Trong danh sách đang xem"
        />
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>Thanh toán theo đơn</h3>
        </div>
        <div className="table-wrap">
          <table className="data-table">
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
              {orders.length === 0 ? (
                <tr>
                  <td colSpan={6} className="empty">
                    Chưa có đơn hàng nào
                  </td>
                </tr>
              ) : (
                orders.map((order) => (
                  <tr key={order.id}>
                    <td>
                      <strong>{order.code}</strong>
                    </td>
                    <td>{order.contactName}</td>
                    <td>
                      {order.paymentMethod === null
                        ? '—'
                        : PAYMENT_METHOD_LABELS[order.paymentMethod]}
                    </td>
                    <td>
                      <strong>{money(order.total)}</strong>
                    </td>
                    <td>
                      <PaymentCell order={order} />
                    </td>
                    <td>
                      <button
                        type="button"
                        className="table-action"
                        onClick={() => openModal({ kind: 'order', orderId: order.id })}
                      >
                        Xem đơn →
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      <p className="notice">
        Phương thức và trạng thái thanh toán ở trên là dữ liệu thật; các ô tổng tiền phía trên vẫn
        là số minh họa. Việc sinh mã VietQR động và xác nhận đã nhận tiền thuộc lát cắt Thanh toán
        chưa được triển khai: chỉ nhân viên được cấp quyền mới được đánh dấu đã thu, sau khi đối
        chiếu khoản tiền thật trong ngân hàng.
      </p>
    </>
  );
}
