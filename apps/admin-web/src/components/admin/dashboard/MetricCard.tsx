'use client';

import Link from 'next/link';
import { useId, type ReactNode } from 'react';

import { formatTrend } from '@/lib/dashboard-metrics';

import { IconHelp } from '../icons';
import { Sparkline, type SparkTone } from './Sparkline';

export interface MetricCardProps {
  readonly label: string;
  /** Already formatted - the card never decides how money is written. */
  readonly value: string;
  readonly icon: ReactNode;
  readonly tone: SparkTone;
  /** How this figure is calculated, shown on hover and on keyboard focus. */
  readonly hint: string;
  /** Fraction against the comparison window; null hides the trend entirely. */
  readonly trend?: number | null;
  readonly trendLabel?: string;
  /** Shown instead of the trend when there is nothing to compare against. */
  readonly foot?: string;
  readonly spark?: readonly number[];
  readonly action?: { readonly href: string; readonly label: string };
}

/**
 * One KPI.
 *
 * Deliberately not a link, although three of the four have somewhere to go: a
 * card with a help button and an action inside it cannot also be one big
 * anchor without nesting interactive elements, which no screen reader reads
 * predictably. The action is its own link in the footer instead.
 */
export function MetricCard({
  label,
  value,
  icon,
  tone,
  hint,
  trend,
  trendLabel,
  foot,
  spark,
  action,
}: MetricCardProps) {
  const hintId = useId();
  const rising = trend !== null && trend !== undefined && trend >= 0;

  return (
    <article className={`kpi kpi-${tone}`}>
      <div className="kpi-head">
        <span className="kpi-label">
          {label}
          <button type="button" className="kpi-hint" aria-describedby={hintId}>
            <IconHelp size={12} />
            <span className="sr-only">Cách tính {label}</span>
            <span className="kpi-bubble" role="tooltip" id={hintId}>
              {hint}
            </span>
          </button>
        </span>
        <span className="kpi-icon" aria-hidden="true">
          {icon}
        </span>
      </div>

      <strong className="kpi-value">{value}</strong>

      <div className="kpi-foot">
        {trend === null || trend === undefined ? (
          <span className="kpi-note">{foot ?? ''}</span>
        ) : (
          <>
            {/*
             * Three signals, not one colour: the arrow, the word a screen
             * reader reads, and the tint. Colour alone would be the one that
             * fails for the people who most need the difference to be obvious.
             */}
            <span className={rising ? 'kpi-trend up' : 'kpi-trend down'}>
              <span aria-hidden="true">{rising ? '↑' : '↓'}</span>
              <span className="sr-only">{rising ? 'Tăng' : 'Giảm'}</span>
              {formatTrend(trend)}
            </span>
            <span className="kpi-note">{trendLabel ?? ''}</span>
          </>
        )}
      </div>

      {spark === undefined ? null : <Sparkline values={spark} tone={tone} />}

      {action === undefined ? null : (
        <Link className="kpi-action" href={action.href}>
          {action.label} <span aria-hidden="true">→</span>
        </Link>
      )}
    </article>
  );
}
