'use client';

import { CartLines, CartTotalsRows } from './CartContents';
import { IconCart, IconGift, IconLeaf, IconScooter, IconShield, IconTicket } from './icons';
import type { CartKey, CartLine, CartTotals } from './use-storefront';

interface SidePanelsProps {
  readonly lines: readonly CartLine[];
  readonly totals: CartTotals;
  readonly points: number;
  readonly couponInput: string;
  readonly couponMessage: string;
  readonly onCouponInputChange: (value: string) => void;
  readonly onApplyCoupon: (code?: string) => void;
  readonly onChangeQuantity: (key: CartKey, delta: number) => void;
  readonly onClearCart: () => void;
  readonly onCheckout: () => void;
  readonly onOpenRewards: () => void;
}

/** The sticky right-hand column: cart, loyalty points, today's promotion, trust badges. */
export function SidePanels({
  lines,
  totals,
  points,
  couponInput,
  couponMessage,
  onCouponInputChange,
  onApplyCoupon,
  onChangeQuantity,
  onClearCart,
  onCheckout,
  onOpenRewards,
}: SidePanelsProps) {
  return (
    <aside className="right-column" aria-label="Giỏ hàng và ưu đãi">
      <div className="panel cart-panel">
        <div className="panel-title">
          <h3>
            <IconCart size={17} /> Giỏ hàng của bạn ({totals.count})
          </h3>
          <button type="button" className="text-danger" onClick={onClearCart}>
            Xóa tất cả
          </button>
        </div>

        <div className="cart-items">
          <CartLines lines={lines} onChangeQuantity={onChangeQuantity} />
        </div>

        <div className="coupon-entry">
          <label htmlFor="couponInput">
            <IconTicket size={15} /> Mã ưu đãi
          </label>
          <div className="coupon-row">
            <input
              id="couponInput"
              placeholder="Nhập mã giảm giá"
              value={couponInput}
              onChange={(event) => onCouponInputChange(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  onApplyCoupon();
                }
              }}
            />
            <button type="button" onClick={() => onApplyCoupon()}>
              Áp dụng
            </button>
          </div>
          <small>{couponMessage}</small>
        </div>

        <CartTotalsRows totals={totals} />

        <button
          type="button"
          className="btn btn-yellow checkout-btn"
          disabled={totals.count === 0}
          onClick={onCheckout}
        >
          Đặt hàng ngay <span>→</span>
        </button>
      </div>

      <div className="panel reward-panel" id="rewards">
        <div className="panel-title">
          <h3>
            <IconGift size={17} /> Điểm thưởng của bạn
          </h3>
        </div>
        <div className="reward-big">
          <strong>
            <span>{points}</span> điểm
          </strong>
          <span>
            <IconGift size={42} />
          </span>
        </div>
        <button type="button" className="reward-link" onClick={onOpenRewards}>
          Đổi điểm lấy món ngon →
        </button>
      </div>

      <div className="panel promo-panel" id="promos">
        <h3>
          <IconTicket size={17} /> Ưu đãi hôm nay
        </h3>
        <strong>GIẢM 10%</strong>
        <p>cho đơn từ 100.000đ</p>
        <button type="button" onClick={() => onApplyCoupon('SANG10')}>
          Dùng mã SANG10 ⧉
        </button>
      </div>

      <div className="panel trust-panel">
        <div>
          <IconScooter size={22} />
          <span>
            Giao nhanh
            <br />
            trong khu vực
          </span>
        </div>
        <div>
          <IconLeaf size={22} />
          <span>
            Nguyên liệu
            <br />
            tươi ngon
          </span>
        </div>
        <div>
          <IconShield size={22} />
          <span>
            An toàn
            <br />
            vệ sinh
          </span>
        </div>
      </div>
    </aside>
  );
}
