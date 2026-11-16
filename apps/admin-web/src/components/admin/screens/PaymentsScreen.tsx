'use client';

import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import { Metric, PageHeading } from '../primitives';

export function PaymentsScreen() {
  const { orders, openModal, notifyAction } = useAdminData();

  return (
    <>
      <PageHeading
        title="Thanh toán"
        description="Theo dõi tiền mặt và chuyển khoản VietQR của cửa hàng."
        actions={
          <button type="button" className="btn" onClick={() => notifyAction('bank')}>
            ▣ Tài khoản ngân hàng
          </button>
        }
      />

      <div className="metrics">
        <Metric
          title="Đã thu hôm nay"
          value="1.285.000đ"
          icon="↗"
          color="#e9f8f0"
          foot="Dữ liệu minh họa"
        />
        <Metric title="Tiền mặt" value="485.000đ" icon="▤" color="#fff3d9" foot="Đã ghi nhận" />
        <Metric title="Chuyển khoản" value="800.000đ" icon="▣" color="#e9f0ff" foot="Đã xác nhận" />
        <Metric
          title="Chờ đối soát"
          value="3 giao dịch"
          icon="◷"
          color="#fff0e9"
          foot="Xác nhận thủ công"
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
              {orders.map((order) => (
                <tr key={order.id}>
                  <td>
                    <strong>{order.id}</strong>
                  </td>
                  <td>{order.name}</td>
                  <td>{order.payment}</td>
                  <td>
                    <strong>{money(order.total)}</strong>
                  </td>
                  <td>
                    {order.status === 'done' ? (
                      <span className="status ready">Đã thu</span>
                    ) : (
                      <span className="status waiting">Chờ xác nhận</span>
                    )}
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
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <p className="notice">
        VietQR thực tế phải sinh ở Backend theo số tiền và mã tham chiếu từng đơn; giao diện này
        chưa kết nối ngân hàng hoặc xác nhận tiền tự động.
      </p>
    </>
  );
}
