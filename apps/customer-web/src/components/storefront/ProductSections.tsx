'use client';

import { productImage, type Product, type ProductCategory } from '@/data/products';
import { money } from '@/lib/money';

import { scrollToSection } from './scroll';
import type { CategoryFilter } from './use-storefront';

interface SectionSpec {
  readonly heading: string;
  readonly categories: readonly ProductCategory[];
  readonly seeAll: CategoryFilter;
}

const SECTIONS: readonly SectionSpec[] = [
  {
    heading: '🔥 Món ăn sáng nổi bật',
    categories: ['breakfast', 'main'],
    seeAll: 'breakfast',
  },
  {
    heading: '⭐ Chả đặc sản – Món ngon nhà làm',
    categories: ['special', 'snack'],
    seeAll: 'special',
  },
  {
    heading: '🥤 Đồ uống & combo yêu thích',
    categories: ['drink', 'combo'],
    seeAll: 'drink',
  },
];

interface ProductCardProps {
  readonly product: Product;
  readonly liked: boolean;
  readonly onAdd: (productId: number) => void;
  readonly onToggleFavorite: (productId: number) => void;
}

/**
 * Favourite icon.
 *
 * The design used the text characters ♡ / ♥ (U+2661 / U+2665). Neither is in
 * the body font, so every platform resolves them through its own symbol or
 * emoji fallback: on Windows they come back oversized and off-centre inside
 * the 27px button. An inline SVG draws the same shape identically everywhere
 * and takes its colour from the button's `color`, so the design's red and the
 * `liked` background still apply.
 */
function HeartIcon({ filled }: { filled: boolean }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="15"
      height="15"
      aria-hidden="true"
      focusable="false"
      fill={filled ? 'currentColor' : 'none'}
      stroke="currentColor"
      strokeWidth={filled ? 0 : 2}
      strokeLinejoin="round"
    >
      <path d="M12 21.35 10.55 20C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35Z" />
    </svg>
  );
}

function ProductCard({ product, liked, onAdd, onToggleFavorite }: ProductCardProps) {
  const subtitle = product.variants === undefined ? product.unit : 'Nhiều quy cách đóng gói';
  const firstVariant = product.variants?.[0];
  const price =
    firstVariant === undefined ? money(product.price) : `Từ ${money(firstVariant.price)}`;

  return (
    <article className="product">
      <div className="product-photo">
        <img src={productImage(product)} alt={product.name} loading="lazy" />
        <button
          type="button"
          className={liked ? 'heart liked' : 'heart'}
          aria-label={`Yêu thích ${product.name}`}
          onClick={() => onToggleFavorite(product.id)}
        >
          <HeartIcon filled={liked} />
        </button>
      </div>
      <div className="product-content">
        <div className="product-name">{product.name}</div>
        <div className="product-sub">{subtitle}</div>
        <div className="product-bottom">
          <span className="product-price">{price}</span>
          <button
            type="button"
            className="add"
            aria-label={`Thêm ${product.name}`}
            onClick={() => onAdd(product.id)}
          >
            +
          </button>
        </div>
      </div>
    </article>
  );
}

interface ProductSectionsProps {
  readonly products: readonly Product[];
  readonly favorites: readonly number[];
  readonly onAdd: (productId: number) => void;
  readonly onToggleFavorite: (productId: number) => void;
  readonly onSeeAll: (category: CategoryFilter) => void;
}

export function ProductSections({
  products,
  favorites,
  onAdd,
  onToggleFavorite,
  onSeeAll,
}: ProductSectionsProps) {
  return (
    <div id="menu">
      {SECTIONS.map((section) => {
        const items = products.filter((product) => section.categories.includes(product.category));

        return (
          <section
            key={section.heading}
            className={items.length === 0 ? 'product-section hide' : 'product-section'}
          >
            <div className="section-heading">
              <h2>{section.heading}</h2>
              <button
                type="button"
                className="see-all"
                onClick={() => {
                  onSeeAll(section.seeAll);
                  scrollToSection('menu');
                }}
              >
                Xem tất cả →
              </button>
            </div>
            <div className="product-grid">
              {items.length === 0 ? (
                <p>Chưa có món phù hợp.</p>
              ) : (
                items.map((product) => (
                  <ProductCard
                    key={product.id}
                    product={product}
                    liked={favorites.includes(product.id)}
                    onAdd={onAdd}
                    onToggleFavorite={onToggleFavorite}
                  />
                ))
              )}
            </div>
          </section>
        );
      })}
    </div>
  );
}
