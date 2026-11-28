'use client';

import Link from 'next/link';

import { sliceDescription, type StatusSlice } from '@/lib/dashboard-metrics';

/** Geometry of the ring: radius, thickness and the gap between segments. */
const RADIUS = 54;
const THICKNESS = 18;
const GAP = 3;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * Which list the orders screen should open for this slice.
 *
 * The groups are the orders screen's own (`?status=`), not the server's eight
 * statuses: clicking "Đang chuẩn bị" should land on the list that shows
 * exactly those orders, and that screen already knows how to group them.
 */
const SLICE_LINKS: Readonly<Record<string, string>> = {
  waiting: '/orders?status=placed',
  // The orders screen keeps CONFIRMED, PREPARING and READY in one "đã xác
  // nhận" group, so both of those slices land on the same list.
  preparing: '/orders?status=working',
  ready: '/orders?status=working',
  done: '/orders?status=completed',
  cancelled: '/orders?status=cancelled',
};

export interface OrderStatusChartProps {
  readonly slices: readonly StatusSlice[];
  readonly total: number;
  readonly rangeLabel: string;
}

/**
 * How the window's orders ended up.
 *
 * Five groups, not four: this system genuinely has `PREPARING` and `READY`,
 * and folding them into "đã xác nhận" would hide a state the kitchen works
 * to. They are the same five tones the status badges use everywhere else, so
 * a colour means the same thing on this screen as in the orders table.
 *
 * The ring is the summary; the list beside it is the data. Every segment is
 * named and counted in that list, so nothing here depends on telling two
 * colours apart.
 */
export function OrderStatusChart({ slices, total, rangeLabel }: OrderStatusChartProps) {
  let offset = 0;

  return (
    <section className="panel status-panel" aria-labelledby="order-status-title">
      <div className="panel-head">
        <div>
          <h2 id="order-status-title">Cơ cấu đơn hàng</h2>
          <p className="panel-sub">
            {rangeLabel} · {total} đơn đã tiếp nhận
          </p>
        </div>
      </div>

      {total === 0 ? (
        <p className="panel-empty">Chưa có đơn nào trong khoảng này.</p>
      ) : (
        <div className="donut-wrap">
          <svg
            className="donut"
            viewBox="0 0 140 140"
            role="img"
            aria-label={`Cơ cấu đơn hàng ${rangeLabel.toLowerCase()}: ${slices.map((slice) => `${slice.label} ${slice.count} đơn`).join(', ')}.`}
          >
            <circle className="donut-track" cx="70" cy="70" r={RADIUS} strokeWidth={THICKNESS} />
            {slices.map((slice) => {
              const length = (slice.count / total) * CIRCUMFERENCE;
              // The gap is carved out of the segment itself, so the ring keeps
              // its circumference and the segments still read as proportions.
              const drawn = Math.max(0, length - GAP);
              const circle = (
                <circle
                  key={slice.tone}
                  className={`donut-slice slice-${slice.tone}`}
                  cx="70"
                  cy="70"
                  r={RADIUS}
                  strokeWidth={THICKNESS}
                  strokeDasharray={`${drawn.toFixed(2)} ${(CIRCUMFERENCE - drawn).toFixed(2)}`}
                  strokeDashoffset={(-offset).toFixed(2)}
                />
              );

              offset += length;

              return circle;
            })}
            <text className="donut-value" x="70" y="66" textAnchor="middle">
              {total}
            </text>
            <text className="donut-unit" x="70" y="84" textAnchor="middle">
              đơn
            </text>
          </svg>

          <ul className="status-legend">
            {slices.map((slice) => (
              <li key={slice.tone}>
                <Link href={SLICE_LINKS[slice.tone] ?? '/orders'} title={sliceDescription(slice)}>
                  <span className={`legend-dot slice-${slice.tone}`} aria-hidden="true" />
                  <span className="legend-label">{slice.label}</span>
                  <span className="legend-count">{slice.count}</span>
                  <span className="legend-share">{Math.round((slice.count / total) * 100)}%</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
