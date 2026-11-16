'use client';

import { useAdminData } from '../AdminDataProvider';
import { Metric, PageHeading } from '../primitives';

export function LoyaltyScreen() {
  const { rewards, openModal } = useAdminData();

  return (
    <>
      <PageHeading
        title="Tích điểm & đổi món"
        description="Khuyến khích khách mua lại bằng điểm thưởng và quà tặng."
        actions={
          <>
            <button
              type="button"
              className="btn"
              onClick={() => openModal({ kind: 'loyalty-settings' })}
            >
              ⚙ Cấu hình tích điểm
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => openModal({ kind: 'add-reward' })}
            >
              ＋ Thêm quà đổi điểm
            </button>
          </>
        }
      />

      <div className="metrics">
        <Metric
          title="Thành viên tích điểm"
          value="126"
          icon="♡"
          color="#e9f0ff"
          foot="Khách tham gia"
        />
        <Metric
          title="Điểm đã phát hành"
          value="8.450"
          icon="☆"
          color="#fff3d9"
          foot="Điểm thưởng demo"
        />
        <Metric title="Lượt đổi quà" value="23" icon="🎁" color="#e9f8f0" foot="Trong tháng" />
        <Metric
          title="Tỷ lệ quay lại"
          value="67%"
          icon="↗"
          color="#f5ebff"
          foot="Thống kê minh họa"
        />
      </div>

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Quà tặng đổi điểm</h3>
            <button type="button" onClick={() => openModal({ kind: 'add-reward' })}>
              Quản lý →
            </button>
          </div>
          {rewards.map((reward) => (
            <div className="reward-row" key={`${reward.name}-${reward.points}`}>
              <div className="food-thumb">{reward.emoji}</div>
              <div className="reward-desc">
                <strong>{reward.name}</strong>
                <small>Đã đổi {reward.claimed} lần</small>
              </div>
              <b>{reward.points} điểm</b>
            </div>
          ))}
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>Chính sách điểm thưởng</h3>
          </div>
          <div className="info-card" style={{ background: '#fffaf0', borderColor: '#f8eac8' }}>
            <h3>⭐ Mua hàng — Nhận điểm</h3>
            <strong>10.000đ = 1 điểm</strong>
            <p>
              Điểm được cộng khi đơn hoàn thành và thanh toán hợp lệ. Khách dùng điểm để đổi món ăn
              hoặc đồ uống.
            </p>
            <button
              type="button"
              className="btn"
              onClick={() => openModal({ kind: 'loyalty-settings' })}
            >
              Chỉnh sửa chính sách →
            </button>
          </div>
          <p className="notice">
            Backend phải chống cộng điểm trùng, giữ điểm khi đổi quà và hoàn điểm nếu giao dịch thất
            bại.
          </p>
        </section>
      </div>
    </>
  );
}
