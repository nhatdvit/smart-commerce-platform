'use client';

import { IconBrand, IconCart, IconSearch, IconUser } from './icons';
import { scrollToSection, scrollToTop } from './scroll';

const NAV_ITEMS: readonly { readonly label: string; readonly target: string }[] = [
  { label: 'Trang chủ', target: 'top' },
  { label: 'Thực đơn', target: 'menu' },
  { label: 'Đặt trước', target: 'menu' },
  { label: 'Ưu đãi', target: 'promos' },
  { label: 'Tích điểm', target: 'rewards' },
];

interface SiteHeaderProps {
  readonly search: string;
  readonly cartCount: number;
  readonly onSearchChange: (value: string) => void;
  readonly onOpenCart: () => void;
  readonly onOpenLogin: () => void;
}

export function SiteHeader({
  search,
  cartCount,
  onSearchChange,
  onOpenCart,
  onOpenLogin,
}: SiteHeaderProps) {
  return (
    <header className="header">
      <div
        className="brand"
        role="button"
        tabIndex={0}
        onClick={scrollToTop}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            scrollToTop();
          }
        }}
      >
        <span className="brand-icon">
          <IconBrand size={32} />
        </span>
        <span>
          <strong>Phú Quý</strong>
          <small>Food &amp; Drink</small>
        </span>
      </div>

      <nav className="topnav" aria-label="Điều hướng">
        {NAV_ITEMS.map((item, index) => (
          <button
            key={item.label}
            type="button"
            className={index === 0 ? 'nav-active' : undefined}
            onClick={() => scrollToSection(item.target)}
          >
            {item.label}
          </button>
        ))}
      </nav>

      <label className="search">
        <IconSearch size={20} />
        <input
          type="search"
          placeholder="Tìm món ăn, đồ uống..."
          aria-label="Tìm món"
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
        />
        <kbd>
          <IconSearch size={18} />
        </kbd>
      </label>

      <button className="header-cart" type="button" aria-label="Mở giỏ hàng" onClick={onOpenCart}>
        <IconCart size={24} />
        <span>{cartCount}</span>
      </button>

      <button className="login" type="button" onClick={onOpenLogin}>
        <IconUser size={17} /> <span>Đăng nhập</span>
      </button>
    </header>
  );
}
