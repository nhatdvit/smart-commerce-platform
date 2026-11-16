import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import { publicEnv } from '@/lib/env';
import '@/styles/globals.css';
import '@/styles/storefront.css';

export const metadata: Metadata = {
  title: {
    default: publicEnv.siteName,
    template: '%s - ' + publicEnv.siteName,
  },
  description: 'Order Vietnamese breakfast for pickup or delivery.',
};

/** Mobile-first: lock the layout viewport to the device width. */
export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  // The delivered design's theme colour, not the Tailwind token.
  themeColor: '#fff8e8',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="vi" suppressHydrationWarning>
      <body>{children}</body>
    </html>
  );
}
