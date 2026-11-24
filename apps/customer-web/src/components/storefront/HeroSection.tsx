'use client';

import { IconChevronLeft, IconChevronRight, IconLeaf, IconScooter, IconShield } from './icons';
import { scrollToSection } from './scroll';

/**
 * The hero keeps the design's plain `<img>` rather than `next/image`: the
 * stylesheet positions and crops it with `position:absolute` plus
 * `object-fit:cover`, and the wrapper `next/image` injects changes that
 * geometry. Swap it for `next/image` when the photo comes from Storage and a
 * remote loader is configured.
 */
export function HeroSection() {
  return (
    <section className="hero" id="hero">
      <div className="hero-copy">
        <span className="eyebrow">MÓN NGON NHÀ LÀM · GIAO MỖI NGÀY</span>
        <h1>
          Bữa sáng <em>trọn vị</em>
          <br />
          Món ngon mỗi ngày
        </h1>
        <p>Từ bánh mì, xôi, bún phở đến chả ốc, chả tôm và nhiều món ăn hấp dẫn khác.</p>
        <button className="btn btn-yellow" type="button" onClick={() => scrollToSection('menu')}>
          Đặt món ngay <span>→</span>
        </button>
        <div className="hero-features">
          <div className="hero-feature">
            <IconLeaf size={18} />
            <span>
              <strong>Tươi ngon</strong>
              <small>Mỗi ngày</small>
            </span>
          </div>
          <div className="hero-feature">
            <IconShield size={18} />
            <span>
              <strong>An toàn</strong>
              <small>Vệ sinh</small>
            </span>
          </div>
          <div className="hero-feature">
            <IconScooter size={18} />
            <span>
              <strong>Giao nhanh</strong>
              <small>Đúng giờ</small>
            </span>
          </div>
        </div>
      </div>

      <img className="hero-photo" src="/assets/hero.jpg" alt="Bánh mì, cà phê và món ngon" />

      {/*
       * Decorative only, like the dots below them: one photo, nothing to
       * page through yet. Not buttons, so they never claim an action they
       * don't perform.
       */}
      <span className="hero-nav prev" aria-hidden="true">
        <IconChevronLeft size={18} />
      </span>
      <span className="hero-nav next" aria-hidden="true">
        <IconChevronRight size={18} />
      </span>

      <div className="hero-dots">
        <i className="active" />
        <i />
        <i />
      </div>
    </section>
  );
}
