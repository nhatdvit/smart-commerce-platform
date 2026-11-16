import { StorefrontApp } from '@/components/storefront/StorefrontApp';

export const metadata = {
  title: 'Phú Quý Food & Drink — Món ngon mỗi ngày',
};

/**
 * The storefront. One scrolling page, as designed - the catalogue, cart and
 * checkout are rendered from demo data until the Catalog, Cart and Order
 * modules expose their endpoints (CLAUDE.md section 8).
 */
export default function HomePage() {
  return <StorefrontApp />;
}
