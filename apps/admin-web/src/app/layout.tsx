import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import { AdminDataProvider } from '@/components/admin/AdminDataProvider';
import { AdminShell } from '@/components/admin/AdminShell';
import '@/styles/globals.css';
import '@/styles/admin-shell.css';

export const metadata: Metadata = {
  title: {
    default: 'Phú Quý Admin — Quản lý bán hàng',
    template: '%s - Phú Quý Admin',
  },
  description: 'Staff administration for the Smart Commerce Platform.',
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="vi" suppressHydrationWarning>
      <head>
        {/*
         * Be Vietnam Pro, the design's typeface. Loaded with <link> rather
         * than the stylesheet's original @import (which a bundle would place
         * too late to take effect) and not with next/font, which would make
         * the Docker image build depend on reaching Google. The stylesheet
         * falls back to Arial when the font cannot be fetched.
         */}
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap"
        />
      </head>
      <body>
        <AdminDataProvider>
          <AdminShell>{children}</AdminShell>
        </AdminDataProvider>
      </body>
    </html>
  );
}
