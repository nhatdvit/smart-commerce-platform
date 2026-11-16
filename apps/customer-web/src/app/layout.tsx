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
      <head>
        {/*
         * Be Vietnam Pro: a typeface drawn for Vietnamese, so ă/ơ/ư and the
         * stacked tone marks are designed rather than synthesised. The design
         * package asked for Inter but never loaded it, leaving the site on
         * Arial. Loaded by <link> rather than next/font so the Docker image
         * build does not depend on reaching Google; the stylesheet still falls
         * back to Arial when the font cannot be fetched.
         */}
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800;900&display=swap"
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
