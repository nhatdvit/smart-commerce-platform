'use client';

import Link from 'next/link';
import type { ReactNode } from 'react';

export interface OperationalAlert {
  readonly key: string;
  /** Urgency, which decides the tint - never the only signal; see below. */
  readonly tone: 'danger' | 'warn' | 'info';
  readonly icon: ReactNode;
  /** The count, big enough to read across a counter. */
  readonly count: number;
  readonly label: string;
  readonly href: string;
}

/**
 * What needs a person, right now.
 *
 * Replaces the old "Bắt đầu ca sáng" banner, which was the same size whether
 * there were forty orders waiting or none. This strip appears only when
 * something is actually waiting, each line is a link straight to the screen
 * that deals with it, and a quiet morning renders nothing at all.
 *
 * Every line carries a number, a word and an icon as well as a tint: tone is
 * emphasis here, never the information itself.
 */
export function OperationalAlerts({ alerts }: { readonly alerts: readonly OperationalAlert[] }) {
  if (alerts.length === 0) {
    return null;
  }

  return (
    <section className="ops-strip" aria-label="Việc cần xử lý">
      <h2 className="sr-only">Việc cần xử lý</h2>
      {alerts.map((alert) => (
        <Link key={alert.key} className={`ops-item ops-${alert.tone}`} href={alert.href}>
          <span className="ops-icon" aria-hidden="true">
            {alert.icon}
          </span>
          <span className="ops-count">{alert.count}</span>
          <span className="ops-label">{alert.label}</span>
          <span className="ops-go" aria-hidden="true">
            →
          </span>
        </Link>
      ))}
    </section>
  );
}
