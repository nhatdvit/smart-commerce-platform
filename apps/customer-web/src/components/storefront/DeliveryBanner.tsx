'use client';

import { IconScooter } from './icons';
import { scrollToSection } from './scroll';

/**
 * The closing banner, spanning both grid columns below the cart/promo/trust
 * panels - the design's own illustration (a rider on a scooter) is replaced
 * with a badge in the same line-icon language as the rest of the page,
 * rather than one-off artwork (see DESIGN_PORT_NOTES.md item 8).
 */
export function DeliveryBanner() {
  return (
    <section className="delivery">
      <div className="delivery-info">
        <span className="delivery-art" aria-hidden="true">
          <IconScooter size={30} />
        </span>
        <div className="delivery-copy">
          <h2>Đặt món dễ dàng – Giao nhanh tận nơi</h2>
          <p>Thưởng thức món ngon nhà làm tại nhà, văn phòng hoặc bất cứ đâu.</p>
        </div>
      </div>
      <button className="btn btn-yellow" type="button" onClick={() => scrollToSection('menu')}>
        Đặt hàng ngay <span>→</span>
      </button>
    </section>
  );
}
