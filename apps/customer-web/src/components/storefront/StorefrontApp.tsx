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
import { usePinnedHeader } from './use-pinned-header';
import { useStorefront } from './use-storefront';

/**
 * The Bếp nhà mình storefront, ported from the delivered HTML/CSS/JS prototype.
 *
 * It is one scrolling page, exactly as designed: the category bar and the
 * "see all" links filter the sections in place rather than navigating, so the
 * whole screen is a single client component tree.
 *
 * The menu is real now (`GET /menus/today`). Cart, checkout, sign-in and
 * loyalty points are still the delivered demo behaviour, held in the browser:
 * there is no cart, order or customer-account endpoint yet for them to call.
 */
export function StorefrontApp() {
  const store = useStorefront();
  const layerOpen = store.drawerOpen || store.modal !== null;
  const headerPinned = usePinnedHeader('hero');

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
        pinned={headerPinned}
        currentCustomer={store.currentCustomer}
        onSearchChange={store.setSearch}
        onOpenCart={() => store.setDrawerOpen(true)}
        onOpenLogin={() => store.setModal({ kind: 'login' })}
        onLogout={store.logout}
      />

      <main className="page" id="top">
        <div className="main-column">
          <HeroSection />

          <CategoryBar
            categories={store.categories}
            active={store.categoryFilter}
            onSelect={(category) => {
              store.setCategoryFilter(category);
              scrollToSection('menu');
            }}
          />

          {store.menuError !== '' ? (
            <div className="notice">{store.menuError}</div>
          ) : store.menuBusy ? (
            <div id="menu">
              <p className="notice">Đang tải thực đơn...</p>
            </div>
          ) : (
            <ProductSections
              cards={store.visibleCards}
              favorites={store.favorites}
              onAdd={store.addToCart}
              onToggleFavorite={store.toggleFavorite}
              onSeeAll={store.setCategoryFilter}
            />
          )}

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
        © 2026 Bếp nhà mình · Món ngon nhà làm · Giao diện demo Smart Commerce Platform · Giá và
        hình ảnh minh họa
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
          cardsByProductId={store.cardsByProductId}
          authBusy={store.authBusy}
          authError={store.authError}
          onClose={store.closeLayers}
          onAddMenuItem={(menuItemId) => {
            store.addMenuItem(menuItemId);
            store.closeLayers();
          }}
          onPlaceOrder={store.placeDemoOrder}
          onInvalidCheckout={() => store.toast('Vui lòng nhập tên và số điện thoại hợp lệ')}
          onRedeem={store.redeemReward}
          onLogin={(phone, password) => void store.login(phone, password)}
          onRegister={(fullName, phone, password) => void store.register(fullName, phone, password)}
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
