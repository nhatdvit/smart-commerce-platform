'use client';

import { useAdminData } from '../AdminDataProvider';
import { Metric, PageHeading } from '../primitives';

export function PromotionsScreen() {
  const { coupons, openModal } = useAdminData();
  const totalUses = coupons.reduce((sum, coupon) => sum + coupon.used, 0);

  return (
    <>
      <PageHeading
        title="Mã ưu đãi"
        description="Tạo chương trình khuyến mãi để tăng đơn hàng và khách quay lại."
        actions={
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => openModal({ kind: 'add-coupon' })}
          >
            ＋ Tạo mã ưu đãi
          </button>
        }
      />

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Mã đang hoạt động</h3>
          </div>
          {coupons.map((coupon) => (
            <div className="coupon" key={coupon.code}>
              <div>
                <strong>✂ {coupon.code}</strong>
                <small>{coupon.desc}</small>
                <small>
                  Đã dùng {coupon.used}/{coupon.limit} lượt
                </small>
              </div>
              <button
                type="button"
                className="btn"
                onClick={() => openModal({ kind: 'coupon-detail', code: coupon.code })}
              >
                Chi tiết
              </button>
            </div>
          ))}
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>Hiệu quả ưu đãi</h3>
          </div>
          <Metric
            title="Lượt sử dụng"
            value={totalUses}
            icon="◇"
            color="#fff3d9"
            foot="Tổng lượt dùng mã demo"
          />
          <p className="notice">
            Mã ưu đãi có thể cấu hình mức giảm, giá trị đơn tối thiểu, thời hạn và giới hạn số lượt.
          </p>
        </section>
      </div>
    </>
  );
}
