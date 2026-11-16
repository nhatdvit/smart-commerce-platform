'use client';

import type { CategoryFilter } from './use-storefront';

const CATEGORIES: readonly {
  readonly key: CategoryFilter;
  readonly icon: string;
  readonly label: string;
}[] = [
  { key: 'all', icon: '🥖', label: 'Tất cả' },
  { key: 'breakfast', icon: '🥪', label: 'Bữa sáng' },
  { key: 'special', icon: '🍢', label: 'Chả đặc sản' },
  { key: 'main', icon: '🍜', label: 'Món chính' },
  { key: 'drink', icon: '🥤', label: 'Đồ uống' },
  { key: 'combo', icon: '🍱', label: 'Combo' },
  { key: 'snack', icon: '🥟', label: 'Ăn vặt' },
  { key: 'promo', icon: '🎟️', label: 'Ưu đãi' },
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
