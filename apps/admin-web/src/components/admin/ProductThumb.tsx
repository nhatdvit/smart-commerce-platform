'use client';

import { mediaUrl } from '@/lib/api-client';
import type { MenuProduct } from '@/lib/menu-api';

import { FoodIcon } from './icons';

/**
 * A dish's square: its photo, or the icon standing in for one.
 *
 * Shared by every screen that shows a dish, because a product carries one or
 * the other and a screen that only knew about icons would show the generic
 * fallback for every dish that has a photo.
 *
 * `next/image` is deliberately not used: `image_url` is opaque - a path on
 * this API now, a bucket URL later - and the optimiser needs a remote pattern
 * configured ahead of time, which would make adding storage a frontend change.
 */
export function ProductThumb({
  product,
  size = 32,
}: {
  readonly product: Pick<MenuProduct, 'name' | 'icon' | 'imageUrl'>;
  readonly size?: number;
}) {
  if (product.imageUrl === null) {
    return <FoodIcon name={product.icon} size={size} />;
  }

  return (
    <img
      className="product-photo"
      src={mediaUrl(product.imageUrl)}
      alt={product.name}
      width={size}
      height={size}
      loading="lazy"
    />
  );
}
