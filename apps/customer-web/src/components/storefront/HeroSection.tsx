'use client';

import { IconLeaf, IconScooter, IconShield } from './icons';
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
    <section className="hero">
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
          <span>
            <IconScooter size={16} /> Giao nhanh
            <br />
            trong khu vực
          </span>
          <span>
            <IconLeaf size={16} /> Nguyên liệu
            <br />
            tươi ngon
          </span>
          <span>
            <IconShield size={16} /> An toàn
            <br />
            vệ sinh
          </span>
        </div>
      </div>

      <img className="hero-photo" src="/assets/hero.jpg" alt="Bánh mì, cà phê và món ngon" />

      <div className="hero-dots">
        <i className="active" />
        <i />
        <i />
      </div>
    </section>
  );
}
