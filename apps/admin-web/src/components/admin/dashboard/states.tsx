'use client';

import type { ReactNode } from 'react';

import { IconAlert, IconReceipt } from '../icons';

/**
 * The shape of the screen before the figures arrive.
 *
 * Built from the real layout rather than a generic spinner, so nothing moves
 * when the data lands: the skeleton occupies exactly the boxes the content
 * will. That is the difference between a loading state and a layout shift.
 */
export function DashboardSkeleton() {
  return (
    <div className="dash-skeleton">
      <span className="sr-only" role="status">
        Đang tải số liệu tổng quan…
      </span>
      <div className="skel-stack" aria-hidden="true">
        <div className="skel-row skel-kpis">
          {[0, 1, 2, 3].map((index) => (
            <div className="skel skel-kpi" key={index} />
          ))}
        </div>
        <div className="skel-row skel-split">
          <div className="skel skel-chart" />
          <div className="skel skel-side" />
        </div>
        <div className="skel skel-table" />
      </div>
    </div>
  );
}

/** Shared frame for the two "nothing to show" cases below. */
function Placeholder({
  icon,
  title,
  children,
}: {
  readonly icon: ReactNode;
  readonly title: string;
  readonly children: ReactNode;
}) {
  return (
    <div className="dash-placeholder">
      <span className="dash-placeholder-icon" aria-hidden="true">
        {icon}
      </span>
      <strong>{title}</strong>
      <div>{children}</div>
    </div>
  );
}

export function DashboardEmptyState({ rangeLabel }: { readonly rangeLabel: string }) {
  return (
    <Placeholder icon={<IconReceipt size={22} />} title="Chưa có đơn nào trong kỳ này">
      <p>
        Không có đơn hàng nào được ghi nhận trong khoảng <b>{rangeLabel.toLowerCase()}</b>. Hãy chọn
        một khoảng thời gian khác để xem số liệu.
      </p>
    </Placeholder>
  );
}

export function DashboardErrorState({
  message,
  onRetry,
}: {
  readonly message: string;
  readonly onRetry: () => void;
}) {
  return (
    <Placeholder icon={<IconAlert size={22} />} title="Không tải được số liệu">
      <p>{message}</p>
      <button type="button" className="btn btn-primary" onClick={onRetry}>
        Thử lại
      </button>
    </Placeholder>
  );
}
