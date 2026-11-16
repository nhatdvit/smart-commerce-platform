'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Fragment, type ReactNode } from 'react';

import { useAdminData } from './AdminDataProvider';
import { AdminModals } from './AdminModals';
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
            <div className="brand-mark">
              P<span>Q</span>
            </div>
            <div>
              <strong>PHÚ QUÝ</strong>
              <small>Commerce Admin</small>
            </div>
            <button
              type="button"
              className="close-nav"
              onClick={closeSidebar}
              aria-label="Đóng menu"
            >
              ✕
            </button>
          </div>

          <div className="store-card">
            <span className="store-avatar">🥐</span>
            <div>
              <strong>Bữa Sáng Phú Quý</strong>
              <small>● Đang hoạt động</small>
            </div>
            <span>⌄</span>
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
                    <span>{item.icon}</span> {item.label}
                    {item.badge === 'pending' ? <em>{pendingCount}</em> : null}
                  </Link>
                ))}
              </Fragment>
            ))}
          </nav>

          <div className="sidebar-bottom">
            <div className="help-icon">?</div>
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
                ☰
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
                ♧<b className="notify-dot" />
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
