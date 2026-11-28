'use client';

import { useEffect, useMemo, useRef, useState, type PointerEvent } from 'react';

import type { SeriesPoint } from '@/lib/dashboard-metrics';
import { money } from '@/lib/money';

/** "1,2tr" / "350k" / "0" - an axis label has to fit, not to be exact. */
function compact(amount: number): string {
  if (amount >= 1_000_000) {
    const millions = amount / 1_000_000;

    return `${(millions >= 10 ? Math.round(millions) : Math.round(millions * 10) / 10)
      .toString()
      .replace('.', ',')}tr`;
  }

  if (amount >= 1_000) {
    return `${Math.round(amount / 1_000)}k`;
  }

  return String(Math.round(amount));
}

/**
 * The next round number above the peak, so the axis reads 0 / 500k / 1tr
 * rather than 0 / 437k / 874k.
 */
function niceCeiling(peak: number): number {
  if (peak <= 0) {
    return 100_000;
  }

  const magnitude = 10 ** Math.floor(Math.log10(peak));
  const steps = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10];
  const step = steps.find((candidate) => peak <= candidate * magnitude) ?? 10;

  return step * magnitude;
}

export interface RevenueChartProps {
  readonly points: readonly SeriesPoint[];
  readonly total: number;
  /** The same figure for the comparison window; null when not comparable. */
  readonly previousTotal: number | null;
  readonly comparisonLabel: string;
  readonly rangeLabel: string;
}

const GRID_LINES = 4;

/**
 * Revenue over the window.
 *
 * Drawn by hand in SVG rather than with a chart library: the project has none,
 * and two charts do not justify the first one. Measured rather than scaled -
 * the SVG is rendered at the container's real pixel width, so a 12px axis
 * label is 12px on a phone instead of a squeezed 6px one, which is what a
 * single fixed `viewBox` stretched to fit would have produced.
 */
export function RevenueChart({
  points,
  total,
  previousTotal,
  comparisonLabel,
  rangeLabel,
}: RevenueChartProps) {
  const frame = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  const [hover, setHover] = useState<number | null>(null);

  useEffect(() => {
    const element = frame.current;

    if (element === null) {
      return;
    }

    const observer = new ResizeObserver((entries) => {
      for (const entry of entries) {
        setWidth(entry.contentRect.width);
      }
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const height = width > 0 && width < 520 ? 190 : 250;
  const pad = useMemo(
    () => ({ top: 16, right: 14, bottom: 28, left: width < 420 ? 44 : 58 }),
    [width],
  );

  const geometry = useMemo(() => {
    if (width === 0 || points.length === 0) {
      return null;
    }

    const plotWidth = Math.max(10, width - pad.left - pad.right);
    const plotHeight = Math.max(10, height - pad.top - pad.bottom);
    const ceiling = niceCeiling(Math.max(...points.map((point) => point.revenue)));
    const step = points.length === 1 ? 0 : plotWidth / (points.length - 1);

    const coordinates = points.map((point, index) => ({
      x: pad.left + (points.length === 1 ? plotWidth / 2 : index * step),
      y: pad.top + plotHeight - (point.revenue / ceiling) * plotHeight,
      point,
    }));

    const line = coordinates
      .map(
        (entry, index) => `${index === 0 ? 'M' : 'L'}${entry.x.toFixed(1)} ${entry.y.toFixed(1)}`,
      )
      .join(' ');

    const first = coordinates[0];
    const last = coordinates[coordinates.length - 1];
    const baseline = pad.top + plotHeight;
    const area =
      first === undefined || last === undefined
        ? ''
        : `${line} L${last.x.toFixed(1)} ${baseline} L${first.x.toFixed(1)} ${baseline} Z`;

    /*
     * Which buckets get an axis label.
     *
     * Chosen by distance rather than by every-nth: "every third" still
     * collides once the plot is 300px wide, and the last bucket - the one a
     * reader looks for first - was landing on top of its neighbour. A label
     * is kept only if it clears the previous one, and the last one wins any
     * argument with the label before it.
     */
    const minGap = width < 520 ? 54 : 68;
    const labelled: number[] = [];
    let lastLabelX = Number.NEGATIVE_INFINITY;

    coordinates.forEach((entry, index) => {
      if (entry.x - lastLabelX >= minGap) {
        labelled.push(index);
        lastLabelX = entry.x;
      }
    });

    const lastIndex = coordinates.length - 1;

    if (!labelled.includes(lastIndex)) {
      const previous = labelled[labelled.length - 1];
      const previousX =
        previous === undefined ? Number.NEGATIVE_INFINITY : (coordinates[previous]?.x ?? 0);

      if ((coordinates[lastIndex]?.x ?? 0) - previousX < minGap) {
        labelled.pop();
      }

      labelled.push(lastIndex);
    }

    return { coordinates, line, area, ceiling, baseline, plotHeight, labelled };
  }, [points, width, height, pad]);

  const active = hover === null ? null : (geometry?.coordinates[hover] ?? null);

  function trackPointer(event: PointerEvent<HTMLDivElement>): void {
    if (geometry === null || geometry.coordinates.length === 0) {
      return;
    }

    const bounds = event.currentTarget.getBoundingClientRect();
    const x = event.clientX - bounds.left;
    let nearest = 0;

    for (let index = 1; index < geometry.coordinates.length; index += 1) {
      const candidate = geometry.coordinates[index];
      const best = geometry.coordinates[nearest];

      if (candidate !== undefined && best !== undefined) {
        if (Math.abs(candidate.x - x) < Math.abs(best.x - x)) {
          nearest = index;
        }
      }
    }

    setHover(nearest);
  }

  const summary = `Doanh thu ${rangeLabel.toLowerCase()}: ${money(total)}.`;

  return (
    <section className="panel chart-panel" aria-labelledby="revenue-chart-title">
      <div className="panel-head">
        <div>
          <h2 id="revenue-chart-title">Doanh thu theo thời gian</h2>
          <p className="panel-sub">{rangeLabel}</p>
        </div>
        <div className="chart-total">
          <strong>{money(total)}</strong>
          {previousTotal === null ? (
            <span className="panel-sub">Chưa có kỳ trước để so sánh</span>
          ) : (
            <span className="panel-sub">
              Kỳ trước: {money(previousTotal)} <span className="dim">· {comparisonLabel}</span>
            </span>
          )}
        </div>
      </div>

      <div
        className="chart-frame"
        ref={frame}
        onPointerMove={trackPointer}
        onPointerLeave={() => setHover(null)}
      >
        {geometry === null ? null : (
          <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            role="img"
            aria-label={summary}
          >
            <defs>
              <linearGradient id="revenue-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--yellow)" stopOpacity="0.38" />
                <stop offset="100%" stopColor="var(--yellow)" stopOpacity="0.02" />
              </linearGradient>
            </defs>

            {Array.from({ length: GRID_LINES + 1 }, (_, index) => {
              const ratio = index / GRID_LINES;
              const y = pad.top + geometry.plotHeight * ratio;
              const value = geometry.ceiling * (1 - ratio);

              return (
                <g key={index}>
                  <line className="chart-grid" x1={pad.left} y1={y} x2={width - pad.right} y2={y} />
                  <text className="chart-axis" x={pad.left - 10} y={y + 4} textAnchor="end">
                    {compact(value)}
                  </text>
                </g>
              );
            })}

            <path className="chart-area" d={geometry.area} fill="url(#revenue-fill)" />
            <path className="chart-line" d={geometry.line} />

            {geometry.labelled.map((index) => {
              const entry = geometry.coordinates[index];

              return entry === undefined ? null : (
                <text
                  key={entry.point.key}
                  className="chart-axis"
                  x={entry.x}
                  y={height - 8}
                  textAnchor={
                    index === 0
                      ? 'start'
                      : index === geometry.coordinates.length - 1
                        ? 'end'
                        : 'middle'
                  }
                >
                  {entry.point.label}
                </text>
              );
            })}

            {active === null ? null : (
              <g>
                <line
                  className="chart-cross"
                  x1={active.x}
                  y1={pad.top}
                  x2={active.x}
                  y2={geometry.baseline}
                />
                <circle className="chart-dot" cx={active.x} cy={active.y} r={5} />
              </g>
            )}
          </svg>
        )}

        {active === null ? null : (
          <div
            className="chart-tip"
            style={{
              left: `${Math.min(Math.max(active.x, 70), Math.max(70, width - 70))}px`,
            }}
          >
            <strong>{active.point.fullLabel}</strong>
            <span>{money(active.point.revenue)}</span>
            <span className="dim">{active.point.orders} đơn</span>
          </div>
        )}
      </div>

      {/*
       * The same numbers as a table, for a screen reader and for anyone who
       * wants the figure rather than the shape. Visually hidden, not absent.
       */}
      <table className="sr-only">
        <caption>{summary}</caption>
        <thead>
          <tr>
            <th scope="col">Thời gian</th>
            <th scope="col">Doanh thu</th>
            <th scope="col">Số đơn</th>
          </tr>
        </thead>
        <tbody>
          {points.map((point) => (
            <tr key={point.key}>
              <th scope="row">{point.fullLabel}</th>
              <td>{money(point.revenue)}</td>
              <td>{point.orders}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
