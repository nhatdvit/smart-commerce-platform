'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Fragment, type ReactNode } from 'react';

import { useAdminData } from './AdminDataProvider';
import { AdminModals } from './AdminModals';
import {
  IconBell,
  IconChevronDown,
  IconClose,
  IconHamburger,
  IconHelp,
  IconStorefront,
} from './icons';
import { NAV_GROUPS, navLabelFor } from './nav';

/**
 * The admin chrome: sidebar, breadcrumb topbar, modal host and toast.
 *
 * The delivered prototype swapped `main` in place from a page registry; here
 * each screen is a real route under `app/`, so the browser URL, the back
 * button and a deep link all work. The sidebar is rendered by the layout, so
 * navigating between screens does not remount the demo state.
 */
export function AdminShell({ children }: { children: ReactNode }) {
  const { pendingCount, sidebarOpen, setSidebarOpen, notify, toastMessage } = useAdminData();
  const pathname = usePathname();

  function closeSidebar() {
    setSidebarOpen(false);
  }

  return (
    <>
      <div className="app">
        <aside className={sidebarOpen ? 'sidebar open' : 'sidebar'}>
          <div className="brand">
            {/*
             * The emblem from the supplied lockup. The design's yellow "PQ"
             * tile is gone: the emblem carries its own colour, and stacking it
             * on the tile would box a logo inside a second logo.
             */}
            <img
              className="brand-logo"
              src="/brand/logo-mark.webp"
              alt="Bếp nhà mình"
              width={240}
              height={180}
            />
            <div>
              <strong>BẾP NHÀ MÌNH</strong>
              <small>Commerce Admin</small>
            </div>
            <button
              type="button"
              className="close-nav"
              onClick={closeSidebar}
              aria-label="Đóng menu"
            >
              <IconClose size={18} />
            </button>
          </div>

          <div className="store-card">
            <span className="store-avatar">
              <IconStorefront size={22} />
            </span>
            <div>
              <strong>Bếp nhà mình</strong>
              <small>● Đang hoạt động</small>
            </div>
            <span>
              <IconChevronDown size={16} />
            </span>
          </div>

          <nav>
            {NAV_GROUPS.map((group) => (
              <Fragment key={group.title}>
                <div className="nav-title">{group.title}</div>
                {group.items.map((item) => (
                  <Link
                    key={item.href}
                    href={item.href}
                    className={item.href === pathname ? 'active' : undefined}
                    onClick={closeSidebar}
                  >
                    <span>
                      <item.Icon size={18} />
                    </span>{' '}
                    {item.label}
                    {item.badge === 'pending' ? <em>{pendingCount}</em> : null}
                  </Link>
                ))}
              </Fragment>
            ))}
          </nav>

          <div className="sidebar-bottom">
            <div className="help-icon">
              <IconHelp size={18} />
            </div>
            <div>
              <strong>Cần hỗ trợ?</strong>
              <small>Hướng dẫn sử dụng</small>
            </div>
          </div>
        </aside>

        <div
          className={sidebarOpen ? 'overlay open' : 'overlay'}
          role="presentation"
          onClick={closeSidebar}
        />

        <div className="workspace">
          <header className="topbar">
            <div className="top-left">
              <button
                type="button"
                className="icon-button menu-toggle"
                aria-label="Mở menu"
                onClick={() => setSidebarOpen(true)}
              >
                <IconHamburger size={20} />
              </button>
              <div className="crumb">
                Quản trị cửa hàng <span>/</span> <strong>{navLabelFor(pathname)}</strong>
              </div>
            </div>
            <div className="top-actions">
              <span className="live-pill">
                <i /> Đang nhận đơn
              </span>
              <button
                type="button"
                className="icon-button"
                title="Thông báo"
                onClick={() => notify(`Bạn có ${pendingCount} đơn hàng đang chờ xác nhận.`)}
              >
                <IconBell size={19} />
                <b className="notify-dot" />
              </button>
              <div className="user-avatar">AD</div>
            </div>
          </header>

          <main>{children}</main>
        </div>
      </div>

      <div className={toastMessage === '' ? 'toast' : 'toast show'} role="status">
        {toastMessage}
      </div>

      <AdminModals />
    </>
  );
}
