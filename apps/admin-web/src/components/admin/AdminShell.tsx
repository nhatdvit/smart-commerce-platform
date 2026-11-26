'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Fragment, useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { useIdentity } from '@/hooks/useIdentity';
import { forgetIdentity, ROLE_LABELS } from '@/lib/session-api';

import { useAdminData } from './AdminDataProvider';
import { AdminModals } from './AdminModals';
import {
  IconBell,
  IconChevronDown,
  IconClose,
  IconHamburger,
  IconHelp,
  IconPanelLeft,
  IconStorefront,
} from './icons';
import { NAV_GROUPS, navLabelFor } from './nav';
import { SignInPanel } from './SignInPanel';

/** Where the collapsed/expanded choice is remembered. */
const NAV_STATE_KEY = 'scp.admin.nav';

/**
 * The admin chrome: sidebar, breadcrumb topbar, modal host and toast.
 *
 * The delivered prototype swapped `main` in place from a page registry; here
 * each screen is a real route under `app/`, so the browser URL, the back
 * button and a deep link all work. The sidebar is rendered by the layout, so
 * navigating between screens does not remount the demo state.
 */
export function AdminShell({ children }: { children: ReactNode }) {
  const { pendingCount, sidebarOpen, setSidebarOpen, notify, toastMessage, session, signOut } =
    useAdminData();
  const pathname = usePathname();
  const identity = useIdentity();

  /*
   * A collapsed sidebar is a preference, so it outlives the page: a counter
   * screen is 1366px wide and the 254px rail is a tenth of it. Read after
   * mount rather than during render - the server has no localStorage, and
   * guessing would mean the first paint disagrees with the browser.
   */
  const [collapsed, setCollapsed] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const account = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setCollapsed(window.localStorage.getItem(NAV_STATE_KEY) === 'collapsed');
  }, []);

  const toggleCollapsed = useCallback((): void => {
    setCollapsed((current) => {
      const next = !current;

      window.localStorage.setItem(NAV_STATE_KEY, next ? 'collapsed' : 'expanded');

      return next;
    });
  }, []);

  // Escape closes the account menu, and so does a click anywhere else: a
  // popover that can only be dismissed by the control that opened it is a
  // trap for anyone who opened it by accident.
  useEffect(() => {
    if (!accountOpen) {
      return;
    }

    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === 'Escape') {
        setAccountOpen(false);
      }
    }

    function onPointerDown(event: MouseEvent): void {
      if (account.current !== null && !account.current.contains(event.target as Node)) {
        setAccountOpen(false);
      }
    }

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('mousedown', onPointerDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('mousedown', onPointerDown);
    };
  }, [accountOpen]);

  function closeSidebar() {
    setSidebarOpen(false);
  }

  // Nothing on these screens is public: every admin endpoint needs both a
  // session and a store-scoped permission, so without a session there is
  // nothing to show. The brief flash of an empty shell while the session is
  // being checked is avoided by waiting for the answer.
  if (session !== 'signed-in') {
    return (
      <>
        {session === 'signed-out' ? <SignInPanel /> : <div className="signin-shell" />}
        <div className={toastMessage === '' ? 'toast' : 'toast show'} role="status">
          {toastMessage}
        </div>
      </>
    );
  }

  return (
    <>
      <div className={collapsed ? 'app nav-collapsed' : 'app'}>
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
                    /*
                     * The collapsed rail's tooltip.
                     *
                     * The browser's own, not a styled one: the sidebar is a
                     * scroll container, and a scroll container clips both
                     * axes - a CSS tooltip sitting to the right of the rail
                     * was cut off at the rail's edge. The label also stays in
                     * the accessibility tree either way (see .nav-label).
                     */
                    title={collapsed ? item.label : undefined}
                  >
                    <span>
                      <item.Icon size={18} />
                    </span>
                    <span className="nav-label">{item.label}</span>
                    {item.badge === 'pending' && pendingCount > 0 ? <em>{pendingCount}</em> : null}
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
              <button
                type="button"
                className="collapse-nav"
                onClick={toggleCollapsed}
                aria-expanded={!collapsed}
                aria-label={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
                title={collapsed ? 'Mở rộng menu' : 'Thu gọn menu'}
              >
                <IconPanelLeft size={18} />
              </button>
              <nav className="crumb" aria-label="Đường dẫn">
                <span className="crumb-root">Quản trị cửa hàng</span>{' '}
                <span aria-hidden="true">/</span> <strong>{navLabelFor(pathname)}</strong>
              </nav>
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
              <div className="account" ref={account}>
                <button
                  type="button"
                  className="account-trigger"
                  aria-haspopup="menu"
                  aria-expanded={accountOpen}
                  onClick={() => setAccountOpen((open) => !open)}
                >
                  <span className="user-avatar" aria-hidden="true">
                    {identity?.initials ?? ''}
                  </span>
                  <span className="account-name">
                    {identity === null || identity.name === '' ? 'Tài khoản' : identity.name}
                  </span>
                  <IconChevronDown size={14} />
                </button>

                {accountOpen ? (
                  <div className="account-menu" role="menu">
                    <div className="account-head">
                      <strong>
                        {identity === null || identity.name === '' ? 'Tài khoản' : identity.name}
                      </strong>
                      {identity === null || identity.email === '' ? null : (
                        <small>{identity.email}</small>
                      )}
                      {identity === null || identity.role === null ? null : (
                        <span className="account-role">{ROLE_LABELS[identity.role]}</span>
                      )}
                    </div>
                    <button
                      type="button"
                      role="menuitem"
                      className="account-item"
                      onClick={() => {
                        setAccountOpen(false);
                        // The next operator must not be greeted by this
                        // one’s name, nor inherit what they could see.
                        forgetIdentity();
                        signOut();
                      }}
                    >
                      Đăng xuất
                    </button>
                  </div>
                ) : null}
              </div>
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
