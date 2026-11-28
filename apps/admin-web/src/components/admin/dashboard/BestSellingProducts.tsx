'use client';

import Link from 'next/link';
import { useMemo } from 'react';

import type { BestSeller } from '@/lib/dashboard-metrics';
import type { MenuProduct } from '@/lib/menu-api';

import { ProductThumb } from '../ProductThumb';

export interface BestSellingProductsProps {
  readonly items: readonly BestSeller[];
  /** The menu, for each dish's photo and category. */
  readonly products: readonly MenuProduct[];
  readonly rangeLabel: string;
  readonly canSeePrices: boolean;
}

/**
 * The dishes that actually sold, by units.
 *
 * Matched to the menu by name, because an order summary's lines carry no
 * product id (spec section 5). A dish that has since been renamed or removed
 * simply has no photo - it is still counted, and still named, from the order
 * itself. Nothing here is dropped just because the menu moved on.
 *
 * Revenue per dish is absent on purpose: the order summary has no unit price,
 * and today's menu price times yesterday's quantity is a number the orders
 * themselves would contradict.
 */
export function BestSellingProducts({
  items,
  products,
  rangeLabel,
  canSeePrices,
}: BestSellingProductsProps) {
  const byName = useMemo(() => {
    const index = new Map<string, MenuProduct>();

    for (const product of products) {
      index.set(product.name, product);
    }

    return index;
  }, [products]);

  return (
    <section className="panel top-panel" aria-labelledby="bestsellers-title">
      <div className="panel-head">
        <div>
          <h2 id="bestsellers-title">Món bán chạy</h2>
          <p className="panel-sub">{rangeLabel} · theo số phần đã bán</p>
        </div>
        <Link className="panel-link" href="/menu">
          Thực đơn <span aria-hidden="true">→</span>
        </Link>
      </div>

      {items.length === 0 ? (
        <p className="panel-empty">Chưa có món nào được bán trong khoảng này.</p>
      ) : (
        <ol className="top-list">
          {items.map((item, index) => {
            const product = byName.get(item.name);

            return (
              <li className="top-row" key={item.name}>
                <span className={index === 0 ? 'top-rank is-first' : 'top-rank'}>{index + 1}</span>

                <span className="top-thumb">
                  <ProductThumb
                    product={product ?? { name: item.name, icon: 'gift', imageUrl: null }}
                    size={26}
                  />
                </span>

                <span className="top-info">
                  <strong title={item.name}>{item.name}</strong>
                  <small>{product?.cat ?? 'Không còn trên thực đơn'}</small>
                  <span className="top-bar" aria-hidden="true">
                    <span style={{ width: `${Math.round(item.share * 100)}%` }} />
                  </span>
                </span>

                <span className="top-figures">
                  <b>
                    {item.quantity} <span className="dim">phần</span>
                  </b>
                  {canSeePrices && product !== undefined ? (
                    <small>{product.stock} suất còn lại</small>
                  ) : null}
                </span>
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}
