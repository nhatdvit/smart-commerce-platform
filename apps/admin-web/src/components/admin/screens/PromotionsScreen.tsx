'use client';

import { useEffect } from 'react';

import {
  AVAILABILITY_LABELS,
  availabilityTone,
  dateLabel,
  describeCoupon,
  describeUsage,
  type Coupon,
} from '@/lib/coupons-api';

import { useAdminData } from '../AdminDataProvider';
import { IconPlus, IconScissors, IconTicket } from '../icons';
import { Metric, PageHeading } from '../primitives';

/** "20/11/2026 → 30/11/2026", or what is known of it. */
function windowLabel(coupon: Coupon): string | null {
  if (coupon.startsAt === null && coupon.endsAt === null) {
    return null;
  }

  if (coupon.endsAt === null) {
    return `Từ ${dateLabel(coupon.startsAt)}`;
  }

  if (coupon.startsAt === null) {
    return `Đến hết ${dateLabel(coupon.endsAt)}`;
  }

  return `${dateLabel(coupon.startsAt)} → ${dateLabel(coupon.endsAt)}`;
}

export function PromotionsScreen() {
  const { coupons, couponsBusy, couponsError, loadCoupons, openModal } = useAdminData();

  useEffect(() => {
    loadCoupons();
  }, [loadCoupons]);

  const running = coupons.filter((coupon) => coupon.availability === 'RUNNING');
  const totalUses = coupons.reduce((sum, coupon) => sum + coupon.redeemedCount, 0);

  return (
    <>
      <PageHeading
        title="Mã ưu đãi"
        description="Tạo chương trình khuyến mãi để tăng đơn hàng và khách quay lại."
        actions={
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => openModal({ kind: 'coupon-form' })}
          >
            <IconPlus size={15} /> Tạo mã ưu đãi
          </button>
        }
      />

      {/*
       * A list that could not be loaded is not an empty list. Saying so keeps
       * an operator from creating a second copy of a code that already exists.
       */}
      {couponsError === '' ? null : (
        <div className="panel empty">
          <strong>Không tải được danh sách mã</strong>
          {couponsError}
        </div>
      )}

      {couponsError !== '' ? null : (
        <div className="two-col">
          <section className="panel">
            <div className="panel-title">
              <h3>Mã ưu đãi ({coupons.length})</h3>
              <span className="panel-note" aria-live="polite">
                {couponsBusy ? 'Đang tải...' : `${running.length} mã đang chạy`}
              </span>
            </div>

            {coupons.length === 0 ? (
              <div className="empty">
                <strong>{couponsBusy ? 'Đang tải...' : 'Chưa có mã ưu đãi nào'}</strong>
                {couponsBusy
                  ? 'Vui lòng đợi trong giây lát.'
                  : 'Bấm "Tạo mã ưu đãi" để tạo chương trình đầu tiên.'}
              </div>
            ) : (
              coupons.map((coupon) => (
                <div className="coupon" key={coupon.id}>
                  <div>
                    <strong>
                      <IconScissors size={15} /> {coupon.code}
                      <span className={`status ${availabilityTone(coupon.availability)}`}>
                        {AVAILABILITY_LABELS[coupon.availability]}
                      </span>
                    </strong>
                    <small>{describeCoupon(coupon)}</small>
                    <small>
                      Đã dùng {describeUsage(coupon)}
                      {windowLabel(coupon) === null ? '' : ` · ${windowLabel(coupon)}`}
                    </small>
                    {coupon.description === null ? null : <small>{coupon.description}</small>}
                  </div>
                  <button
                    type="button"
                    className="btn"
                    onClick={() => openModal({ kind: 'coupon-form', couponId: coupon.id })}
                  >
                    Chi tiết
                  </button>
                </div>
              ))
            )}
          </section>

          <section className="panel">
            <div className="panel-title">
              <h3>Hiệu quả ưu đãi</h3>
            </div>
            <Metric
              title="Lượt sử dụng"
              value={totalUses}
              icon={<IconTicket size={18} />}
              color="#fff3d9"
              foot="Tổng lượt đã ghi nhận"
            />
            {/*
             * The counter can only read zero today, and a zero that looks like
             * a measurement is worse than no number at all - so the screen
             * says which half of the feature exists.
             */}
            <p className="notice">
              Mã ưu đãi đã lưu thật: mức giảm, đơn tối thiểu, thời hạn và giới hạn lượt đều được máy
              chủ kiểm tra. Lượt sử dụng sẽ chỉ tăng khi khách đặt hàng kèm mã — luồng đặt hàng của
              khách chưa được triển khai, nên hiện tại con số này luôn là 0.
            </p>
          </section>
        </div>
      )}
    </>
  );
}
