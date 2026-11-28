'use client';

import { useEffect, useRef, useState } from 'react';

import {
  fromInputValue,
  toInputValue,
  today,
  RANGE_LABELS,
  type CivilDate,
  type DateRange,
  type RangePreset,
} from '@/lib/dashboard-range';

import { IconCalendar, IconChevronDown } from '../icons';

const PRESETS: readonly Exclude<RangePreset, 'custom'>[] = [
  'today',
  'yesterday',
  'last7',
  'last30',
  'thisMonth',
];

export interface DateRangeFilterProps {
  readonly range: DateRange;
  readonly onPreset: (preset: Exclude<RangePreset, 'custom'>) => void;
  readonly onCustom: (first: CivilDate, last: CivilDate) => void;
}

/**
 * Which days the figures cover.
 *
 * Buttons with `aria-pressed`, not a `<select>`: this is the control the whole
 * screen hangs off, and on a phone at the counter a row of thumb-sized chips
 * is quicker than a picker. The custom range stays folded away until asked
 * for, because five presets answer it almost every time.
 */
export function DateRangeFilter({ range, onPreset, onCustom }: DateRangeFilterProps) {
  const [open, setOpen] = useState(false);
  const [first, setFirst] = useState('');
  const [last, setLast] = useState('');
  const [error, setError] = useState('');
  const panel = useRef<HTMLDivElement>(null);

  // The store's own today, so the picker cannot offer a tomorrow that has not
  // started yet where the shop is.
  const maxDay = toInputValue(today(range.timeZone));

  useEffect(() => {
    if (!open) {
      return;
    }

    function onKeyDown(event: KeyboardEvent): void {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    }

    function onPointerDown(event: MouseEvent): void {
      if (panel.current !== null && !panel.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('mousedown', onPointerDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('mousedown', onPointerDown);
    };
  }, [open]);

  function apply(): void {
    const from = fromInputValue(first);
    const to = fromInputValue(last);

    if (from === null || to === null) {
      setError('Chọn đủ ngày bắt đầu và ngày kết thúc.');

      return;
    }

    setError('');
    setOpen(false);
    onCustom(from, to);
  }

  return (
    <div className="range-filter" ref={panel}>
      <div className="range-chips" role="group" aria-label="Khoảng thời gian">
        {PRESETS.map((preset) => (
          <button
            key={preset}
            type="button"
            className={range.preset === preset ? 'chip is-on' : 'chip'}
            aria-pressed={range.preset === preset}
            onClick={() => {
              setOpen(false);
              onPreset(preset);
            }}
          >
            {RANGE_LABELS[preset]}
          </button>
        ))}

        <button
          type="button"
          className={range.preset === 'custom' ? 'chip is-on' : 'chip'}
          aria-expanded={open}
          aria-pressed={range.preset === 'custom'}
          onClick={() => setOpen((current) => !current)}
        >
          <IconCalendar size={13} />
          {range.preset === 'custom' ? range.label : 'Tùy chọn'}
          <IconChevronDown size={12} />
        </button>
      </div>

      {open ? (
        <div className="range-panel">
          <label>
            Từ ngày
            <input
              type="date"
              value={first}
              max={maxDay}
              onChange={(event) => setFirst(event.target.value)}
            />
          </label>
          <label>
            Đến ngày
            <input
              type="date"
              value={last}
              max={maxDay}
              onChange={(event) => setLast(event.target.value)}
            />
          </label>
          {error === '' ? null : (
            <p className="range-error" role="alert">
              {error}
            </p>
          )}
          <button type="button" className="btn btn-primary" onClick={apply}>
            Áp dụng
          </button>
        </div>
      ) : null}
    </div>
  );
}
