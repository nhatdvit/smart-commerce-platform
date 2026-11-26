'use client';

import { CartLines, CartTotalsRows } from './CartContents';
import { IconCart } from './icons';
import type { CartKey, CartLine, CartTotals } from './use-storefront';

interface CartDrawerProps {
  readonly lines: readonly CartLine[];
  readonly totals: CartTotals;
  readonly onChangeQuantity: (key: CartKey, delta: number) => void;
  readonly onCheckout: () => void;
  readonly onClose: () => void;
}

/** Mobile cart: the right-hand column is hidden below 760px, so the cart slides in. */
export function CartDrawer({
  lines,
  totals,
  onChangeQuantity,
  onCheckout,
  onClose,
}: CartDrawerProps) {
  return (
    <div className="drawer" role="dialog" aria-modal="true" aria-label="Giỏ hàng">
      <div className="drawer-head">
        <h3>
          <IconCart size={17} /> Giỏ hàng của bạn
        </h3>
        <button type="button" className="close" onClick={onClose}>
          ✕
        </button>
      </div>
      <div>
        <CartLines lines={lines} onChangeQuantity={onChangeQuantity} />
        <CartTotalsRows totals={totals} alwaysShowDiscount />
        <button
          type="button"
          className="btn btn-yellow checkout-btn"
          disabled={totals.count === 0}
          onClick={onCheckout}
        >
          Đặt hàng ngay →
        </button>
      </div>
    </div>
  );
}
