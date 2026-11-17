'use client';

import { productImage } from '@/data/products';
import { money } from '@/lib/money';

import { IconBag } from './icons';

import type { CartKey, CartLine, CartTotals } from './use-storefront';

interface CartLinesProps {
  readonly lines: readonly CartLine[];
  readonly onChangeQuantity: (key: CartKey, delta: number) => void;
}

/** The cart line list, shared by the desktop panel and the mobile drawer. */
export function CartLines({ lines, onChangeQuantity }: CartLinesProps) {
  if (lines.length === 0) {
    return (
      <div className="cart-empty">
        <span>
          <IconBag size={34} />
        </span>
        Giỏ hàng đang trống.
        <br />
        Chọn món ngon để bắt đầu nhé!
      </div>
    );
  }

  return (
    <>
      {lines.map((line) => (
        <div className="cart-line" key={line.key}>
          <img src={productImage(line.product)} alt={line.product.name} />
          <div className="cart-detail">
            <div className="cart-name">{line.displayName}</div>
            <div className="cart-unit">
              {money(line.unitPrice)} / {line.unitLabel}
            </div>
            <div className="cart-controls">
              <div className="qty">
                <button
                  type="button"
                  aria-label="Giảm số lượng"
                  onClick={() => onChangeQuantity(line.key, -1)}
                >
                  −
                </button>
                <b>{line.quantity}</b>
                <button
                  type="button"
                  aria-label="Tăng số lượng"
                  onClick={() => onChangeQuantity(line.key, 1)}
                >
                  +
                </button>
              </div>
              <span className="cart-price">{money(line.unitPrice * line.quantity)}</span>
            </div>
          </div>
        </div>
      ))}
    </>
  );
}

interface CartTotalsRowsProps {
  readonly totals: CartTotals;
  /** The desktop panel hides the discount row until a coupon applies; the drawer always shows it. */
  readonly alwaysShowDiscount?: boolean;
}

export function CartTotalsRows({ totals, alwaysShowDiscount = false }: CartTotalsRowsProps) {
  return (
    <div className="totals">
      <div>
        <span>Tạm tính</span>
        <strong>{money(totals.subtotal)}</strong>
      </div>
      <div hidden={!alwaysShowDiscount && totals.discount === 0}>
        <span>Giảm giá</span>
        <strong>−{money(totals.discount)}</strong>
      </div>
      <div className="total">
        <span>Thành tiền</span>
        <strong>{money(totals.total)}</strong>
      </div>
    </div>
  );
}
