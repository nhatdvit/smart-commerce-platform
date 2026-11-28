'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';

import type { StaffIdentity } from '@/lib/session-api';
import { canManageMenu } from '@/lib/session-api';

import { IconBell, IconMenuBook, IconReceipt } from '../icons';
import { useTodayLabel } from '../primitives';
import { DateRangeFilter, type DateRangeFilterProps } from './DateRangeFilter';

/**
 * "Chào buổi sáng" - resolved after mount.
 *
 * The server renders this component too, and the server's clock is not the
 * viewer's: baking a greeting into the HTML would make it disagree with the
 * browser on the first paint. Empty until mounted, exactly like the date
 * above it (see `useTodayLabel`).
 */
function useGreeting(): string {
  const [greeting, setGreeting] = useState('');

  useEffect(() => {
    const hour = new Date().getHours();

    setGreeting(hour < 11 ? 'Chào buổi sáng' : hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
  }, []);

  return greeting;
}

export interface DashboardHeaderProps extends DateRangeFilterProps {
  readonly identity: StaffIdentity | null;
  /** Orders waiting on staff right now, for the quick action's badge. */
  readonly pendingCount: number;
}

export function DashboardHeader({
  identity,
  pendingCount,
  range,
  onPreset,
  onCustom,
}: DashboardHeaderProps) {
  const todayLabel = useTodayLabel();
  const greeting = useGreeting();
  const name = identity?.name ?? '';

  return (
    <header className="dash-header">
      <div className="dash-header-top">
        <div>
          <p className="eyebrow">{todayLabel}</p>
          <h1>Tổng quan kinh doanh</h1>
          <p className="dash-greeting">
            {greeting === '' || name === ''
              ? 'Theo dõi doanh thu, đơn hàng và khả năng phục vụ của cửa hàng.'
              : `${greeting}, ${name}. Đây là tình hình kinh doanh của cửa hàng.`}
          </p>
        </div>

        <div className="dash-actions">
          <Link className="btn btn-primary" href="/orders/new">
            <IconBell size={15} /> Xử lý đơn mới
            {pendingCount > 0 ? <em className="btn-badge">{pendingCount}</em> : null}
          </Link>
          {canManageMenu(identity?.role ?? null) ? (
            <Link className="btn" href="/menu">
              <IconMenuBook size={15} /> Quản lý thực đơn
            </Link>
          ) : null}
          <Link className="btn" href="/orders">
            <IconReceipt size={15} /> Xem đơn hàng
          </Link>
        </div>
      </div>

      <DateRangeFilter range={range} onPreset={onPreset} onCustom={onCustom} />
    </header>
  );
}
