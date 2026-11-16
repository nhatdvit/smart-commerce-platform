'use client';

import { useAdminData } from '../AdminDataProvider';
import { PageHeading } from '../primitives';

const THEME_ROWS: readonly (readonly [string, string])[] = [
  ['Logo & tên cửa hàng', 'Bữa Sáng Phú Quý'],
  ['Màu thương hiệu', 'Vàng nắng · Xanh navy'],
  ['Banner trang chủ', 'Khuyến mãi bữa sáng'],
  ['Danh mục hiển thị', 'Bánh mì · Xôi · Bún/Phở · Đồ uống · Combo'],
];

const TOGGLE_ROWS: readonly (readonly [string, string, string])[] = [
  ['Hiển thị mục đổi điểm', 'Khách có thể đổi món bằng điểm', 'Điểm thưởng'],
  ['Hiển thị mã ưu đãi', 'Áp dụng mã giảm giá khi thanh toán', 'Mã ưu đãi'],
];

export function StorefrontScreen() {
  const { notify, notifyAction } = useAdminData();

  return (
    <>
      <PageHeading
        title="Giao diện cửa hàng"
        description="Tùy chỉnh thương hiệu, màu sắc và các khối hiển thị của cửa hàng F&B."
        actions={
          <button
            type="button"
            className="btn btn-primary"
            onClick={() =>
              notify('Đây là bản xem trước mini. Storefront thực tế là ứng dụng riêng.')
            }
          >
            ◉ Xem bản xem trước
          </button>
        }
      />

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Cấu hình giao diện</h3>
          </div>
          {THEME_ROWS.map(([label, value]) => (
            <div className="settings-row" key={label}>
              <div>
                <strong>{label}</strong>
                <small>{value}</small>
              </div>
              <button type="button" className="btn" onClick={() => notifyAction('theme')}>
                Sửa
              </button>
            </div>
          ))}
          {TOGGLE_ROWS.map(([label, description, toggleName]) => (
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
                    notify(`${event.target.checked ? 'Đã bật ' : 'Đã tắt '}${toggleName} (demo)`)
                  }
                />
                <span />
              </label>
            </div>
          ))}
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>Xem trước storefront</h3>
          </div>
          <div className="preview">
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 15 }}>
              <strong>🥐 Bữa Sáng Phú Quý</strong>
              <span>🛒</span>
            </div>
            <div className="preview-hero">
              <small>BỮA SÁNG ĐỦ ĐẦY</small>
              <h3>
                Ăn sáng ngon,
                <br />
                <span>ngày mới vui!</span>
              </h3>
              <small>Đặt trước · Nhận đúng giờ</small>
            </div>
            <div className="preview-categories">
              <span>🥖 Bánh mì</span>
              <span>🍚 Xôi</span>
              <span>☕ Đồ uống</span>
            </div>
          </div>
          <p className="notice">
            MVP sử dụng theme và section cấu hình sẵn. Sau này có thể mở rộng theme theo ngành hàng.
          </p>
        </section>
      </div>
    </>
  );
}
