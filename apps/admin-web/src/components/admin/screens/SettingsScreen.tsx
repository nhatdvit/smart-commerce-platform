'use client';

import { useAdminData } from '../AdminDataProvider';
import { PageHeading } from '../primitives';

const OPERATION_TOGGLES: readonly (readonly [string, string])[] = [
  ['Nhận đơn trực tuyến', 'Cho phép khách đặt món qua website'],
  ['Đặt trước cho ngày mai', 'Khách có thể chọn ngày nhận trong tương lai'],
  ['Giới hạn số suất', 'Không cho đặt vượt số suất khả dụng'],
  ['Thông báo đơn mới', 'Hiển thị thông báo khi có đơn'],
];

const PAYMENT_ROWS: readonly (readonly [string, string])[] = [
  ['Giờ bán', '06:00 – 10:30'],
  ['Nhận đơn trước', 'Đến 22:00 tối hôm trước'],
  ['Thanh toán tiền mặt', 'Đang bật'],
  ['Chuyển khoản VietQR', 'Xác nhận thủ công'],
];

export function SettingsScreen() {
  const { notify, notifyAction } = useAdminData();

  return (
    <>
      <PageHeading
        title="Cài đặt cửa hàng"
        description="Quản lý vận hành, giờ bán và phương thức thanh toán."
      />

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Vận hành cửa hàng</h3>
          </div>
          {OPERATION_TOGGLES.map(([label, description]) => (
            <div className="settings-row" key={label}>
              <div>
                <strong>{label}</strong>
                <small>{description}</small>
              </div>
              <label className="switch">
                <input
                  type="checkbox"
                  defaultChecked
                  onChange={(event) =>
                    notify(`${event.target.checked ? 'Đã bật ' : 'Đã tắt '}${label} (demo)`)
                  }
                />
                <span />
              </label>
            </div>
          ))}
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>Thanh toán & thời gian</h3>
          </div>
          {PAYMENT_ROWS.map(([label, value]) => (
            <div className="settings-row" key={label}>
              <div>
                <strong>{label}</strong>
                <small>{value}</small>
              </div>
              <button type="button" className="btn" onClick={() => notifyAction('setting')}>
                Sửa
              </button>
            </div>
          ))}
        </section>
      </div>
    </>
  );
}
