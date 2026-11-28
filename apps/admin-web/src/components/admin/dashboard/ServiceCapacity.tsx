'use client';

import Link from 'next/link';
import { useMemo } from 'react';

import { isLowStock, isSoldOut, remainingPercent, type MenuProduct } from '@/lib/menu-api';

import { ProductThumb } from '../ProductThumb';

export interface PickupSlot {
  readonly key: string;
  /** "08:00 – 08:30". */
  readonly label: string;
  readonly count: number;
}

export interface ServiceCapacityProps {
  readonly products: readonly MenuProduct[];
  /** Busy pickup windows still ahead, from the live order list. */
  readonly slots: readonly PickupSlot[];
}

const SHOWN = 4;

/**
 * Can the shop still serve what people are about to order?
 *
 * Portions are the one operational figure that is already real and already
 * store-wide, so this needs no request of its own: it reads the same menu
 * snapshot the rest of the shell holds, and the same "sắp hết" threshold the
 * inventory screen uses, so the two can never disagree about what low means.
 */
export function ServiceCapacity({ products, slots }: ServiceCapacityProps) {
  const { low, soldOut, remaining, capacity } = useMemo(() => {
    const lowItems = products.filter(isLowStock);
    const soldOutItems = products.filter(isSoldOut);

    return {
      low: lowItems,
      soldOut: soldOutItems,
      remaining: products.reduce((total, product) => total + product.stock, 0),
      capacity: products.reduce((total, product) => total + product.capacity, 0),
    };
  }, [products]);

  // Nothing prepared today means nobody has filled the prep sheet in - a
  // different situation from "everything is in stock", and not one to dress up
  // as a capacity report.
  if (capacity === 0) {
    return null;
  }

  const attention = [...soldOut, ...low].slice(0, SHOWN);

  return (
    <section className="panel capacity-panel" aria-labelledby="capacity-title">
      <div className="panel-head">
        <div>
          <h2 id="capacity-title">Khả năng phục vụ hôm nay</h2>
          <p className="panel-sub">
            Còn {remaining} / {capacity} suất đã chuẩn bị
          </p>
        </div>
        <Link className="panel-link" href="/inventory">
          Số suất <span aria-hidden="true">→</span>
        </Link>
      </div>

      <div className="capacity-counts">
        <span className="cap-stat">
          <b className="is-bad">{soldOut.length}</b> món hết hàng
        </span>
        <span className="cap-stat">
          <b className="is-warn">{low.length}</b> món sắp hết
        </span>
        <span className="cap-stat">
          <b>{products.length - soldOut.length - low.length}</b> món còn đủ
        </span>
      </div>

      {attention.length === 0 ? (
        <p className="panel-empty">Tất cả các món đều còn đủ suất.</p>
      ) : (
        <ul className="cap-list">
          {attention.map((product) => {
            const percent = remainingPercent(product);

            return (
              <li key={product.id}>
                <span className="cap-thumb">
                  <ProductThumb product={product} size={22} />
                </span>
                <span className="cap-name" title={product.name}>
                  {product.name}
                </span>
                <span className={isSoldOut(product) ? 'cap-tag is-bad' : 'cap-tag is-warn'}>
                  {isSoldOut(product) ? 'Hết hàng' : `Còn ${product.stock} suất`}
                </span>
                <span className="cap-bar" aria-hidden="true">
                  <span
                    className={isSoldOut(product) ? 'is-bad' : 'is-warn'}
                    style={{ width: `${percent ?? 0}%` }}
                  />
                </span>
              </li>
            );
          })}
        </ul>
      )}

      {slots.length === 0 ? null : (
        <div className="cap-slots">
          <h3>Khung giờ nhận sắp tới</h3>
          <ul>
            {slots.map((slot) => (
              <li key={slot.key}>
                <span>{slot.label}</span>
                <b>{slot.count} đơn</b>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
