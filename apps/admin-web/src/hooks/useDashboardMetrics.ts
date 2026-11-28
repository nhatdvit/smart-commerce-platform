'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError } from '@/lib/api-client';
import { loadOrdersInRange, storeTimeZone } from '@/lib/dashboard-api';
import { computeMetrics, type DashboardMetrics } from '@/lib/dashboard-metrics';
import {
  buildBuckets,
  comparisonRange,
  customRange,
  presetRange,
  DEFAULT_TIMEZONE,
  type CivilDate,
  type DateRange,
  type RangePreset,
} from '@/lib/dashboard-range';

/**
 * How often an open window re-reads itself.
 *
 * A minute, not the five seconds the order queue polls at: this is a page of
 * figures somebody reads, not a counter somebody stands over, and each refresh
 * is a windowed read rather than one cheap page. A closed window - "hôm qua" -
 * is never re-read at all, because it cannot change.
 */
const REFRESH_MS = 60_000;

export type LoadStatus = 'loading' | 'ready' | 'error';

export interface DashboardData {
  readonly range: DateRange;
  readonly metrics: DashboardMetrics | null;
  /** The same figures for the comparison window; null when not comparable. */
  readonly previous: DashboardMetrics | null;
  readonly status: LoadStatus;
  /** A background re-read, with the previous figures still on screen. */
  readonly refreshing: boolean;
  /** The read hit its page cap, so the totals are of what was read. */
  readonly capped: boolean;
  readonly error: string;
  /** Epoch ms of the last successful read, for "cập nhật lúc". */
  readonly updatedAt: number | null;
  readonly selectPreset: (preset: Exclude<RangePreset, 'custom'>) => void;
  readonly selectCustom: (first: CivilDate, last: CivilDate) => void;
  readonly reload: () => void;
}

interface LoadedState {
  readonly metrics: DashboardMetrics | null;
  readonly previous: DashboardMetrics | null;
  readonly status: LoadStatus;
  readonly capped: boolean;
  readonly error: string;
  readonly updatedAt: number | null;
}

const INITIAL: LoadedState = {
  metrics: null,
  previous: null,
  status: 'loading',
  capped: false,
  error: '',
  updatedAt: null,
};

/**
 * The dashboard's own data, kept out of `AdminDataProvider` on purpose.
 *
 * The provider holds one shared order list that every screen reads and the
 * five-second poll rewrites. Asking it for "orders placed between these two
 * instants" would change what the orders screen is showing and fight that poll
 * for the same state. A window of figures is a different question from a work
 * queue, so it gets its own request, its own abort and its own refresh.
 */
export function useDashboardMetrics(): DashboardData {
  const [timeZone, setTimeZone] = useState(DEFAULT_TIMEZONE);
  const [range, setRange] = useState<DateRange>(() => presetRange('today', DEFAULT_TIMEZONE));
  const [state, setState] = useState<LoadedState>(INITIAL);
  const [refreshing, setRefreshing] = useState(false);

  const ticket = useRef(0);
  const inFlight = useRef<AbortController | null>(null);

  useEffect(() => {
    let active = true;

    void storeTimeZone().then((zone) => {
      if (active) {
        setTimeZone(zone);
      }
    });

    return () => {
      active = false;
    };
  }, []);

  // The first range is built before the store's timezone is known, because the
  // screen renders before the API answers. When the real zone arrives, the same
  // selection is rebuilt against it - which moves the day boundary, not the
  // operator's choice.
  useEffect(() => {
    setRange((current) =>
      current.timeZone === timeZone
        ? current
        : current.preset === 'custom'
          ? customRange(current.firstDay, current.lastDay, timeZone)
          : presetRange(current.preset, timeZone),
    );
  }, [timeZone]);

  const load = useCallback(async (target: DateRange, background: boolean): Promise<void> => {
    inFlight.current?.abort();

    const controller = new AbortController();
    inFlight.current = controller;
    ticket.current += 1;
    const mine = ticket.current;

    if (background) {
      setRefreshing(true);
    } else {
      // The figures already on screen stay there, dimmed by the view, so
      // switching range never collapses the layout to a skeleton.
      setState((current) => ({ ...current, status: 'loading', error: '' }));
    }

    try {
      const now = new Date();
      const current = await loadOrdersInRange(target, controller.signal);

      if (mine !== ticket.current) {
        return;
      }

      const metrics = computeMetrics(current.orders, buildBuckets(target, now));

      /*
       * The comparison is best-effort: it is a decoration on four cards, and
       * nothing about it is worth failing the screen for. A capped read on
       * either side drops it entirely rather than comparing two truncated
       * totals and calling the difference a trend.
       */
      let previous: DashboardMetrics | null = null;

      if (!current.capped) {
        try {
          const before = await loadOrdersInRange(comparisonRange(target, now), controller.signal);

          previous = before.capped ? null : computeMetrics(before.orders, []);
        } catch {
          previous = null;
        }
      }

      if (mine !== ticket.current) {
        return;
      }

      setState({
        metrics,
        previous,
        status: 'ready',
        capped: current.capped,
        error: '',
        updatedAt: Date.now(),
      });
    } catch (error) {
      if (controller.signal.aborted || mine !== ticket.current) {
        return;
      }

      const message =
        error instanceof ApiError || error instanceof Error
          ? error.message
          : 'Không tải được số liệu.';

      setState((currentState) => ({
        ...currentState,
        // Keeping the last good figures beats blanking the screen; the error
        // is shown next to them either way.
        status: currentState.metrics === null ? 'error' : 'ready',
        error: message,
      }));
    } finally {
      if (mine === ticket.current) {
        setRefreshing(false);
      }
    }
  }, []);

  useEffect(() => {
    void load(range, false);
  }, [range, load]);

  useEffect(() => () => inFlight.current?.abort(), []);

  useEffect(() => {
    // A window that has already ended cannot gain an order.
    if (range.toMs <= Date.now()) {
      return;
    }

    function refreshIfVisible(): void {
      if (document.visibilityState === 'visible') {
        void load(range, true);
      }
    }

    const timer = setInterval(refreshIfVisible, REFRESH_MS);
    document.addEventListener('visibilitychange', refreshIfVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', refreshIfVisible);
    };
  }, [range, load]);

  const selectPreset = useCallback(
    (preset: Exclude<RangePreset, 'custom'>): void => {
      setRange(presetRange(preset, timeZone));
    },
    [timeZone],
  );

  const selectCustom = useCallback(
    (first: CivilDate, last: CivilDate): void => {
      setRange(customRange(first, last, timeZone));
    },
    [timeZone],
  );

  const reload = useCallback((): void => {
    void load(range, false);
  }, [load, range]);

  return {
    range,
    metrics: state.metrics,
    previous: state.previous,
    status: state.status,
    refreshing,
    capped: state.capped,
    error: state.error,
    updatedAt: state.updatedAt,
    selectPreset,
    selectCustom,
    reload,
  };
}
