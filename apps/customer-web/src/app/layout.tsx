import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import { publicEnv } from '@/lib/env';
import '@/styles/globals.css';

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
  themeColor: '#f97316',
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="vi" suppressHydrationWarning>
      <body className="min-h-dvh">{children}</body>
    </html>
  );
}
