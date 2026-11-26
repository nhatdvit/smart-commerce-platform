'use client';

import type { ReactNode } from 'react';

import type { MenuProductCard } from '@/lib/menu-api';
import { money } from '@/lib/money';

import { FoodIcon, IconCup, IconFlame, IconStar } from './icons';
import { scrollToSection } from './scroll';
import type { CategoryFilter } from './use-storefront';

/** Matches the 24px heading type the design sets on `.section-heading h2`. */
const HEADING_ICON = 22;

/** Cycled across sections purely for visual variety - categories carry no icon of their own. */
const HEADING_ICONS: readonly ReactNode[] = [
  <IconFlame key="flame" size={HEADING_ICON} />,
  <IconStar key="star" size={HEADING_ICON} />,
  <IconCup key="cup" size={HEADING_ICON} />,
];

function headingIconFor(index: number): ReactNode {
  return HEADING_ICONS[index % HEADING_ICONS.length] ?? <IconStar size={HEADING_ICON} />;
}

interface ProductCardProps {
  readonly card: MenuProductCard;
  readonly liked: boolean;
  readonly onAdd: (card: MenuProductCard) => void;
  readonly onToggleFavorite: (productId: string) => void;
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

function ProductCard({ card, liked, onAdd, onToggleFavorite }: ProductCardProps) {
  const subtitle = card.options.length <= 1 ? null : 'Nhiều quy cách đóng gói';
  const cheapest = card.options.reduce<number | null>(
    (min, option) => (min === null || option.price < min ? option.price : min),
    null,
  );
  const price =
    cheapest === null
      ? 'Hết hàng'
      : card.options.length > 1
        ? `Từ ${money(cheapest)}`
        : money(cheapest);

  return (
    <article className={card.isSoldOut ? 'product sold-out' : 'product'}>
      <div className="product-photo">
        {card.imageUrl === null ? (
          <FoodIcon name={card.icon} size={48} />
        ) : (
          <img src={card.imageUrl} alt={card.name} loading="lazy" />
        )}
        <button
          type="button"
          className={liked ? 'heart liked' : 'heart'}
          aria-label={`Yêu thích ${card.name}`}
          onClick={() => onToggleFavorite(card.productId)}
        >
          <HeartIcon filled={liked} />
        </button>
      </div>
      <div className="product-content">
        <div className="product-name">{card.name}</div>
        {subtitle === null ? null : <div className="product-sub">{subtitle}</div>}
        <div className="product-bottom">
          <span className="product-price">{price}</span>
          <button
            type="button"
            className="add"
            aria-label={`Thêm ${card.name}`}
            disabled={card.isSoldOut}
            onClick={() => onAdd(card)}
          >
            {card.isSoldOut ? '✕' : '+'}
          </button>
        </div>
      </div>
    </article>
  );
}

interface ProductSectionsProps {
  readonly cards: readonly MenuProductCard[];
  readonly favorites: readonly string[];
  readonly onAdd: (card: MenuProductCard) => void;
  readonly onToggleFavorite: (productId: string) => void;
  readonly onSeeAll: (category: CategoryFilter) => void;
  /** Which tab is active; decides whether the "Tất cả" layout below applies. */
  readonly categoryFilter: CategoryFilter;
}

/**
 * Categories carved out of the shared grid when "Tất cả" is selected, each
 * into its own editorial section - a dish this specific to the brand (an
 * assortment of specialty chả, or a bundled combo) reads better as its own
 * moment than as four more tiles in a long undifferentiated grid.
 */
const EDITORIAL_SLUGS = ['cha-dac-san', 'combo'] as const;

/** One category's worth of dishes, in a named section. */
function section(
  key: string,
  heading: string,
  icon: ReactNode,
  cards: readonly MenuProductCard[],
  favorites: readonly string[],
  onAdd: (card: MenuProductCard) => void,
  onToggleFavorite: (productId: string) => void,
  onSeeAll: (category: CategoryFilter) => void,
  seeAllTarget: CategoryFilter,
  /** Set only for the one collection that gets editorial treatment (see below). */
  intro?: string,
) {
  return (
    <section
      key={key}
      className={
        intro === undefined ? 'product-section' : 'product-section product-section--editorial'
      }
    >
      {intro === undefined ? null : <p className="product-section-intro">{intro}</p>}
      <div className="section-heading">
        <h2>
          {icon} {heading}
        </h2>
        <button
          type="button"
          className="see-all"
          onClick={() => {
            onSeeAll(seeAllTarget);
            scrollToSection('menu');
          }}
        >
          Xem tất cả →
        </button>
      </div>
      <div className="product-grid">
        {cards.map((card) => (
          <ProductCard
            key={card.productId}
            card={card}
            liked={favorites.includes(card.productId)}
            onAdd={onAdd}
            onToggleFavorite={onToggleFavorite}
          />
        ))}
      </div>
    </section>
  );
}

export function ProductSections({
  cards,
  favorites,
  onAdd,
  onToggleFavorite,
  onSeeAll,
  categoryFilter,
}: ProductSectionsProps) {
  if (cards.length === 0) {
    return (
      <div id="menu">
        <p className="notice">Chưa có món nào trên thực đơn hôm nay.</p>
      </div>
    );
  }

  // A specific tab (a real category, or "Ưu đãi") already narrows `cards` to
  // what belongs together, so it is one grid - the per-category split below
  // is only for "Tất cả", where otherwise a category with one or two dishes
  // would still claim a full-width row of its own (DESIGN_PORT_NOTES.md).
  if (categoryFilter !== 'all') {
    return (
      <div id="menu">
        <div className="product-grid">
          {cards.map((card) => (
            <ProductCard
              key={card.productId}
              card={card}
              liked={favorites.includes(card.productId)}
              onAdd={onAdd}
              onToggleFavorite={onToggleFavorite}
            />
          ))}
        </div>
      </div>
    );
  }

  const editorialSlugs: readonly string[] = EDITORIAL_SLUGS;
  const everyday = cards.filter((card) => !editorialSlugs.includes(card.category?.slug ?? ''));
  const editorialHeading: Readonly<Record<(typeof EDITORIAL_SLUGS)[number], string>> = {
    'cha-dac-san': 'Chả đặc sản nhà làm',
    combo: 'Combo & ưu đãi',
  };
  /** Only the specialty collection reads as a brand moment; combo is a plain section. */
  const editorialIntro: Partial<Record<(typeof EDITORIAL_SLUGS)[number], string>> = {
    'cha-dac-san': 'Chả cuốn tay, hấp chín mỗi ngày - không trộn lẫn với thực đơn thường.',
  };
  const editorial = EDITORIAL_SLUGS.map((slug) => {
    const group = cards.filter((card) => card.category?.slug === slug);

    return { slug, id: group[0]?.category?.id ?? slug, cards: group };
  }).filter((group) => group.cards.length > 0);

  return (
    <div id="menu">
      {everyday.length === 0
        ? null
        : section(
            'everyday',
            'Thực đơn hôm nay',
            headingIconFor(0),
            everyday,
            favorites,
            onAdd,
            onToggleFavorite,
            onSeeAll,
            'all',
          )}

      {editorial.map((group, index) =>
        section(
          group.slug,
          editorialHeading[group.slug],
          headingIconFor(index + 1),
          group.cards,
          favorites,
          onAdd,
          onToggleFavorite,
          onSeeAll,
          group.id,
          editorialIntro[group.slug],
        ),
      )}
    </div>
  );
}
