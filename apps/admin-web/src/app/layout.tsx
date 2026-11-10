import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import { publicEnv } from '@/lib/env';
import '@/styles/globals.css';

export const metadata: Metadata = {
  title: {
    default: publicEnv.siteName,
    template: '%s - ' + publicEnv.siteName,
  },
  description: 'Store operations: menus, orders, kitchen, payments and reports.',
  // Staff tooling must never be indexed.
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="vi" suppressHydrationWarning>
      <body className="min-h-dvh">{children}</body>
    </html>
  );
}
