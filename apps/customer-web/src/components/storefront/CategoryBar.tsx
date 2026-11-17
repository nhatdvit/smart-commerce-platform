'use client';

import type { ReactNode } from 'react';

import {
  IconBaguette,
  IconBox,
  IconCup,
  IconDumpling,
  IconGrid,
  IconNoodleBowl,
  IconSkewer,
  IconTicket,
} from './icons';
import type { CategoryFilter } from './use-storefront';

/** Tile size from the design: the stylesheet set these glyphs at 32px. */
const TILE_ICON = 30;

const CATEGORIES: readonly {
  readonly key: CategoryFilter;
  readonly icon: ReactNode;
  readonly label: string;
}[] = [
  { key: 'all', icon: <IconGrid size={TILE_ICON} />, label: 'Tất cả' },
  { key: 'breakfast', icon: <IconBaguette size={TILE_ICON} />, label: 'Bữa sáng' },
  { key: 'special', icon: <IconSkewer size={TILE_ICON} />, label: 'Chả đặc sản' },
  { key: 'main', icon: <IconNoodleBowl size={TILE_ICON} />, label: 'Món chính' },
  { key: 'drink', icon: <IconCup size={TILE_ICON} />, label: 'Đồ uống' },
  { key: 'combo', icon: <IconBox size={TILE_ICON} />, label: 'Combo' },
  { key: 'snack', icon: <IconDumpling size={TILE_ICON} />, label: 'Ăn vặt' },
  { key: 'promo', icon: <IconTicket size={TILE_ICON} />, label: 'Ưu đãi' },
];

interface CategoryBarProps {
  readonly active: CategoryFilter;
  readonly onSelect: (category: CategoryFilter) => void;
}

export function CategoryBar({ active, onSelect }: CategoryBarProps) {
  return (
    <section className="categories" aria-label="Danh mục sản phẩm" id="categories">
      {CATEGORIES.map((category) => (
        <button
          key={category.key}
          type="button"
          className={category.key === active ? 'category active' : 'category'}
          onClick={() => onSelect(category.key)}
        >
          <span>{category.icon}</span>
          <b>{category.label}</b>
        </button>
      ))}
    </section>
  );
}
