'use client';

import { CategoryIcon, IconGrid, IconTicket } from './icons';
import type { CategoryFilter } from './use-storefront';

/** Tile size from the design: the stylesheet set these glyphs at 32px. */
const TILE_ICON = 30;

interface Category {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

interface CategoryBarProps {
  readonly categories: readonly Category[];
  readonly active: CategoryFilter;
  readonly onSelect: (category: CategoryFilter) => void;
}

/** Today's real categories, plus the two navigation-only tiles the design always shows. */
export function CategoryBar({ categories, active, onSelect }: CategoryBarProps) {
  return (
    <section className="categories" aria-label="Danh mục sản phẩm" id="categories">
      <button
        type="button"
        className={active === 'all' ? 'category active' : 'category'}
        onClick={() => onSelect('all')}
      >
        <span>
          <IconGrid size={TILE_ICON} />
        </span>
        <b>Tất cả</b>
      </button>

      {categories.map((category) => (
        <button
          key={category.id}
          type="button"
          className={category.id === active ? 'category active' : 'category'}
          onClick={() => onSelect(category.id)}
        >
          <span>
            <CategoryIcon slug={category.slug} size={TILE_ICON} />
          </span>
          <b>{category.name}</b>
        </button>
      ))}

      <button
        type="button"
        className={active === 'promo' ? 'category active' : 'category'}
        onClick={() => onSelect('promo')}
      >
        <span>
          <IconTicket size={TILE_ICON} />
        </span>
        <b>Ưu đãi</b>
      </button>
    </section>
  );
}
