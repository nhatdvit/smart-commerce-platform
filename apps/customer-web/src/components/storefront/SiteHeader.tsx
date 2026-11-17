'use client';

import { IconCart, IconSearch, IconUser } from './icons';
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
        {/*
         * The supplied logo is one lockup: emblem above, wordmark below. At
         * the 80px header height (62px on a phone) that whole lockup would be
         * unreadable, so the emblem carries the mark and the wordmark is set
         * as live text beside it - which also keeps it crisp at any zoom and
         * readable to a screen reader.
         */}
        <img
          className="brand-logo"
          src="/brand/logo-mark.webp"
          alt="Bếp nhà mình"
          width={240}
          height={180}
        />
        <span>
          <strong>Bếp nhà mình</strong>
          <small>Món ngon nhà làm</small>
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
