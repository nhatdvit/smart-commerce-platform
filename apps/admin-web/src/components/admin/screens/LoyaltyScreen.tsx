'use client';

import { useEffect, useState } from 'react';

import { money } from '@/lib/money';

import { useAdminData } from '../AdminDataProvider';
import {
  FoodIcon,
  IconGift,
  IconPlus,
  IconSettings,
  IconStar,
  IconUsers,
  type FoodIconName,
} from '../icons';
import { Metric, PageHeading } from '../primitives';

/**
 * Milliseconds to wait before searching.
 *
 * Long enough that typing a phone number is one request rather than ten,
 * short enough that it still feels like typing.
 */
const SEARCH_DEBOUNCE_MS = 300;

/** A reward's icon is one of the admin UI's known names; anything else falls back to a gift box. */
function rewardIcon(icon: string | null): FoodIconName {
  const known: readonly FoodIconName[] = ['banh-mi', 'xoi', 'pho', 'drink', 'combo', 'gift'];

  return (known as readonly string[]).includes(icon ?? '') ? (icon as FoodIconName) : 'gift';
}

export function LoyaltyScreen() {
  const {
    loyaltySummary,
    loyaltyMembers,
    loyaltyMembersBusy,
    loyaltyMembersError,
    hasMoreLoyaltyMembers,
    loyaltyRewards,
    loyaltyRewardsBusy,
    loyaltySettings,
    loadLoyaltySummary,
    loadLoyaltyMembers,
    loadMoreLoyaltyMembers,
    loadLoyaltyRewards,
    loadLoyaltySettings,
    openModal,
  } = useAdminData();

  const [query, setQuery] = useState('');
  /** The debounced copy of `query`; only this one reaches the server. */
  const [search, setSearch] = useState('');

  useEffect(() => {
    loadLoyaltySummary();
    loadLoyaltyRewards();
    loadLoyaltySettings();
  }, [loadLoyaltySummary, loadLoyaltyRewards, loadLoyaltySettings]);

  useEffect(() => {
    const timer = setTimeout(() => setSearch(query.trim()), SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    loadLoyaltyMembers(search);
  }, [loadLoyaltyMembers, search]);

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
              <IconSettings size={15} /> Cấu hình tích điểm
            </button>
            <button
              type="button"
              className="btn btn-primary"
              onClick={() => openModal({ kind: 'reward-form' })}
            >
              <IconPlus size={15} /> Thêm quà đổi điểm
            </button>
          </>
        }
      />

      <div className="metrics">
        <Metric
          title="Thành viên tích điểm"
          value={loyaltySummary?.members ?? '—'}
          icon={<IconUsers size={18} />}
          color="#e9f0ff"
          foot="Số điện thoại đã có điểm"
        />
        <Metric
          title="Điểm đã phát hành"
          value={loyaltySummary?.pointsIssued ?? '—'}
          icon={<IconStar size={18} />}
          color="#fff3d9"
          foot="Lũy kế toàn bộ"
        />
        <Metric
          title="Lượt đổi quà"
          value={loyaltySummary?.redemptionsThisMonth ?? '—'}
          icon={<IconGift size={18} />}
          color="#e9f8f0"
          foot="Trong tháng này"
        />
      </div>

      <div className="two-col">
        <section className="panel">
          <div className="panel-title">
            <h3>Quà tặng đổi điểm ({loyaltyRewards.length})</h3>
            <span className="panel-note" aria-live="polite">
              {loyaltyRewardsBusy ? 'Đang tải...' : null}
            </span>
          </div>
          {loyaltyRewards.length === 0 ? (
            <div className="empty">
              <strong>{loyaltyRewardsBusy ? 'Đang tải...' : 'Chưa có quà đổi điểm nào'}</strong>
              {loyaltyRewardsBusy
                ? 'Vui lòng đợi trong giây lát.'
                : 'Bấm "Thêm quà đổi điểm" để tạo quà đầu tiên.'}
            </div>
          ) : (
            loyaltyRewards.map((reward) => (
              <div className="reward-row" key={reward.id}>
                <div className="food-thumb">
                  <FoodIcon name={rewardIcon(reward.icon)} />
                </div>
                <div className="reward-desc">
                  <strong>
                    {reward.name}
                    {reward.isActive ? null : <span className="status cancelled">Đã ngừng</span>}
                  </strong>
                  <small>Đã đổi {reward.redeemedCount} lần</small>
                </div>
                <b>{reward.pointsCost} điểm</b>
                <button
                  type="button"
                  className="table-action"
                  onClick={() => openModal({ kind: 'reward-form', rewardId: reward.id })}
                >
                  Sửa
                </button>
              </div>
            ))
          )}
        </section>

        <section className="panel">
          <div className="panel-title">
            <h3>Chính sách điểm thưởng</h3>
          </div>
          <div className="info-card" style={{ background: '#fffaf0', borderColor: '#f8eac8' }}>
            <h3>
              <IconStar size={15} /> Mua hàng — Nhận điểm
            </h3>
            {loyaltySettings === null ? (
              <strong>Đang tải...</strong>
            ) : (
              <strong>{money(loyaltySettings.pointsPerCurrency)} = 1 điểm</strong>
            )}
            <p>
              Điểm được cộng tự động cho mỗi đơn đã thanh toán, qua một tiến trình chạy mỗi phút.
              Khách đạt {loyaltySettings === null ? '—' : loyaltySettings.loyalTierThresholdPoints}{' '}
              điểm lũy kế trở thành hạng &quot;Thân thiết&quot;.
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
            Điểm được cộng khi đơn đã thanh toán (PAID) được máy chủ quét thấy, không phải ngay khi
            khách bấm đặt hàng. Đổi quà trừ điểm ngay và được ghi lại ai là người xử lý.
          </p>
        </section>
      </div>

      <section className="panel">
        <div className="panel-title">
          <h3>
            Khách tích điểm ({loyaltyMembers.length}
            {hasMoreLoyaltyMembers ? '+' : ''})
          </h3>
          <span className="panel-note" aria-live="polite">
            {loyaltyMembersBusy ? 'Đang tải...' : null}
          </span>
        </div>

        <div className="search-row">
          <input
            className="search"
            placeholder="Tìm số điện thoại hoặc tên khách..."
            aria-label="Tìm số điện thoại hoặc tên khách"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
        </div>

        {loyaltyMembersError !== '' ? (
          <div className="empty">
            <strong>Không tải được danh sách khách tích điểm</strong>
            {loyaltyMembersError}
          </div>
        ) : loyaltyMembers.length === 0 ? (
          <div className="empty">
            <strong>{loyaltyMembersBusy ? 'Đang tải...' : 'Chưa có khách tích điểm'}</strong>
            {loyaltyMembersBusy
              ? 'Vui lòng đợi trong giây lát.'
              : 'Điểm được cộng tự động khi có đơn đã thanh toán.'}
          </div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>KHÁCH HÀNG</th>
                  <th>ĐIỂM LŨY KẾ</th>
                  <th>ĐIỂM HIỆN CÓ</th>
                  <th>HẠNG</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {loyaltyMembers.map((member) => (
                  <tr key={member.phone}>
                    <td>
                      <strong>{member.name}</strong>
                      <br />
                      <small>{member.phone}</small>
                    </td>
                    <td>{member.lifetimeEarnedPoints}</td>
                    <td>
                      <strong>
                        <IconStar size={13} /> {member.balance}
                      </strong>
                    </td>
                    <td>
                      <span className={`status ${member.tier === 'LOYAL' ? 'ready' : 'waiting'}`}>
                        {member.tierLabel}
                      </span>
                    </td>
                    <td>
                      <button
                        type="button"
                        className="table-action"
                        onClick={() =>
                          openModal({
                            kind: 'loyalty-member',
                            phone: member.phone,
                            name: member.name,
                          })
                        }
                      >
                        Chi tiết
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {hasMoreLoyaltyMembers ? (
          <div className="load-more">
            <button
              type="button"
              className="btn"
              onClick={loadMoreLoyaltyMembers}
              disabled={loyaltyMembersBusy}
            >
              Tải thêm khách
            </button>
          </div>
        ) : null}
      </section>
    </>
  );
}
