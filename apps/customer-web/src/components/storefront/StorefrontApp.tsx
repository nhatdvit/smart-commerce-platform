'use client';

import { useEffect } from 'react';

import { CartDrawer } from './CartDrawer';
import { CategoryBar } from './CategoryBar';
import { HeroSection } from './HeroSection';
import { ModalLayer } from './ModalLayer';
import { ProductSections } from './ProductSections';
import { SidePanels } from './SidePanels';
import { SiteHeader } from './SiteHeader';
import {
  IconCart,
  IconGift,
  IconHome,
  IconLeaf,
  IconMenuList,
  IconScooter,
  IconStar,
} from './icons';
import { scrollToSection, scrollToTop } from './scroll';
import { useStorefront } from './use-storefront';

/**
 * The Phú Quý storefront, ported from the delivered HTML/CSS/JS prototype.
 *
 * It is one scrolling page, exactly as designed: the category bar and the
 * "see all" links filter the sections in place rather than navigating, so the
 * whole screen is a single client component tree. Every price, total and
 * loyalty figure here is demo data (see `@/data/products`); the API has no
 * catalogue, cart or order endpoint yet.
 */
export function StorefrontApp() {
  const store = useStorefront();
  const layerOpen = store.drawerOpen || store.modal !== null;

  // Escape closes the drawer and the modal, and the page behind a layer must
  // not scroll - both behaviours of the delivered design.
  useEffect(() => {
    if (!layerOpen) {
      return;
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        store.closeLayers();
      }
    }

    document.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [layerOpen, store.closeLayers]);

  return (
    <>
      <SiteHeader
        search={store.search}
        cartCount={store.totals.count}
        onSearchChange={store.setSearch}
        onOpenCart={() => store.setDrawerOpen(true)}
        onOpenLogin={() => store.setModal({ kind: 'login' })}
      />

      <main className="page" id="top">
        <div className="main-column">
          <HeroSection />

          <CategoryBar
            active={store.categoryFilter}
            onSelect={(category) => {
              store.setCategoryFilter(category);
              scrollToSection('menu');
            }}
          />

          <ProductSections
            products={store.visibleProducts}
            favorites={store.favorites}
            onAdd={store.addToCart}
            onToggleFavorite={store.toggleFavorite}
            onSeeAll={store.setCategoryFilter}
          />

          <section className="why">
            <div>
              <IconScooter size={26} /> <strong>Giao nhanh</strong>
              <small>Đúng giờ, đúng món</small>
            </div>
            <div>
              <IconLeaf size={26} /> <strong>Tươi ngon</strong>
              <small>Chế biến mỗi ngày</small>
            </div>
            <div>
              <IconGift size={26} /> <strong>Tích điểm</strong>
              <small>Đổi món miễn phí</small>
            </div>
          </section>
        </div>

        <SidePanels
          lines={store.lines}
          totals={store.totals}
          points={store.points}
          couponInput={store.couponInput}
          couponMessage={store.couponMessage}
          onCouponInputChange={store.setCouponInput}
          onApplyCoupon={(code) => {
            store.applyCoupon(code);
            if (code !== undefined) {
              store.toast(`Đã chọn mã ${code}`);
            }
          }}
          onChangeQuantity={store.changeQuantity}
          onClearCart={store.clearCart}
          onCheckout={store.openCheckout}
          onOpenRewards={() => store.setModal({ kind: 'rewards' })}
        />
      </main>

      <footer>
        © 2026 Phú Quý Food &amp; Drink · Giao diện demo Smart Commerce Platform · Giá và hình ảnh
        minh họa
      </footer>

      <div className="mobile-bar">
        <button type="button" onClick={scrollToTop}>
          <IconHome size={22} />
          <span>Trang chủ</span>
        </button>
        <button type="button" onClick={() => scrollToSection('menu')}>
          <IconMenuList size={22} />
          <span>Thực đơn</span>
        </button>
        <button type="button" onClick={() => store.setModal({ kind: 'rewards' })}>
          <IconStar size={22} />
          <span>Tích điểm</span>
        </button>
        <button type="button" onClick={() => store.setDrawerOpen(true)}>
          <IconCart size={22} />
          <span>
            Giỏ hàng <b>{store.totals.count}</b>
          </span>
        </button>
      </div>

      {layerOpen ? (
        <div className="overlay" role="presentation" onClick={store.closeLayers} />
      ) : null}

      {store.drawerOpen ? (
        <CartDrawer
          lines={store.lines}
          totals={store.totals}
          onChangeQuantity={store.changeQuantity}
          onCheckout={store.openCheckout}
          onClose={store.closeLayers}
        />
      ) : null}

      {store.modal === null ? null : (
        <ModalLayer
          view={store.modal}
          totals={store.totals}
          points={store.points}
          onClose={store.closeLayers}
          onAddVariant={(productId, variantIndex) => {
            store.addToCart(productId, variantIndex);
            store.closeLayers();
          }}
          onPlaceOrder={store.placeDemoOrder}
          onInvalidCheckout={() => store.toast('Vui lòng nhập tên và số điện thoại hợp lệ')}
          onRedeem={store.redeemReward}
        />
      )}

      <div
        className={store.toastMessage === '' ? 'toast' : 'toast show'}
        role="status"
        aria-live="polite"
      >
        {store.toastMessage}
      </div>
    </>
  );
}
