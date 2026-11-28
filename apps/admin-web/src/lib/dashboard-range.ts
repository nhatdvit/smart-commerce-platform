/**
 * The dashboard's time window, in the store's own timezone.
 *
 * "Hôm nay" is a question about the shop's day, not the viewer's: a manager
 * opening the dashboard from another timezone must see the same figures as the
 * cashier standing at the counter. So every boundary here is a civil date in
 * the store's zone (`GET /admin/store` -> `timezone`, default
 * `Asia/Ho_Chi_Minh`), converted to the UTC instants the API filters on
 * (CLAUDE.md section 6: store timestamps are UTC, schedules are evaluated in
 * store-local time).
 *
 * No date library: `Intl.DateTimeFormat` already knows every zone's offset,
 * including the ones that change twice a year, and shipping a tz database for
 * six boundary calculations would be a poor trade.
 */

/** Falls back to the one store there is until `/admin/store` answers. */
export const DEFAULT_TIMEZONE = 'Asia/Ho_Chi_Minh';

export type RangePreset = 'today' | 'yesterday' | 'last7' | 'last30' | 'thisMonth' | 'custom';

export const RANGE_LABELS: Readonly<Record<RangePreset, string>> = {
  today: 'Hôm nay',
  yesterday: 'Hôm qua',
  last7: '7 ngày gần nhất',
  last30: '30 ngày gần nhất',
  thisMonth: 'Tháng này',
  custom: 'Tùy chọn',
};

/** A civil date in the store's zone - no instant, no offset, just a calendar day. */
export interface CivilDate {
  readonly year: number;
  readonly month: number;
  readonly day: number;
}

export interface DateRange {
  readonly preset: RangePreset;
  readonly label: string;
  /** Inclusive lower bound, as the API's `placed_from`. */
  readonly fromIso: string;
  /** Exclusive upper bound, as the API's `placed_to`. */
  readonly toIso: string;
  /** Epoch milliseconds, for bucketing without re-parsing. */
  readonly fromMs: number;
  readonly toMs: number;
  /** Hourly for a single day, daily for anything longer. */
  readonly bucket: 'hour' | 'day';
  /** First and last civil day covered, for the custom-range inputs. */
  readonly firstDay: CivilDate;
  readonly lastDay: CivilDate;
  readonly timeZone: string;
}

/** One column of the revenue chart. */
export interface Bucket {
  readonly key: string;
  /** Axis label: "08:00" or "25/11". */
  readonly label: string;
  /** Spoken form for the screen-reader table: "08:00 ngày 25/11". */
  readonly fullLabel: string;
  readonly fromMs: number;
  readonly toMs: number;
}

interface ZonedParts extends CivilDate {
  readonly hour: number;
  readonly minute: number;
  readonly second: number;
}

/**
 * Cached because a chart with 30 buckets asks for the same zone 30 times, and
 * constructing a formatter is the expensive half of this file.
 */
const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
  let formatter = formatters.get(timeZone);

  if (formatter === undefined) {
    formatter = new Intl.DateTimeFormat('en-US', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      // h23 rather than hour12:false: the latter renders midnight as "24" in
      // some engines, which would put every midnight on the previous day.
      hourCycle: 'h23',
    });
  }

  formatters.set(timeZone, formatter);

  return formatter;
}

/** The wall clock an instant shows in the zone. */
export function zonedParts(instant: Date, timeZone: string): ZonedParts {
  const parts = formatterFor(timeZone).formatToParts(instant);
  const read = (type: Intl.DateTimeFormatPartTypes): number => {
    const value = parts.find((part) => part.type === type)?.value;

    return value === undefined ? 0 : Number.parseInt(value, 10);
  };

  return {
    year: read('year'),
    month: read('month'),
    day: read('day'),
    hour: read('hour'),
    minute: read('minute'),
    second: read('second'),
  };
}

/** How far the zone is from UTC at this instant, in milliseconds. */
function offsetMs(instant: Date, timeZone: string): number {
  const parts = zonedParts(instant, timeZone);
  const asIfUtc = Date.UTC(
    parts.year,
    parts.month - 1,
    parts.day,
    parts.hour,
    parts.minute,
    parts.second,
  );

  return asIfUtc - instant.getTime() + (instant.getTime() % 1000);
}

/**
 * The instant at which a civil day starts in the zone.
 *
 * Two passes: the first guesses with the offset at UTC midnight, the second
 * corrects it if that guess landed on the other side of a DST change. Vietnam
 * has no DST, but a store's timezone is configurable and this costs nothing.
 */
export function startOfDay(date: CivilDate, timeZone: string): number {
  const asIfUtc = Date.UTC(date.year, date.month - 1, date.day, 0, 0, 0);
  const firstGuess = asIfUtc - offsetMs(new Date(asIfUtc), timeZone);
  const corrected = asIfUtc - offsetMs(new Date(firstGuess), timeZone);

  return corrected;
}

/** The civil date `days` away from this one. */
export function addDays(date: CivilDate, days: number): CivilDate {
  const moved = new Date(Date.UTC(date.year, date.month - 1, date.day));
  moved.setUTCDate(moved.getUTCDate() + days);

  return {
    year: moved.getUTCFullYear(),
    month: moved.getUTCMonth() + 1,
    day: moved.getUTCDate(),
  };
}

/** Today, as the store's calendar reads it. */
export function today(timeZone: string, now: Date = new Date()): CivilDate {
  const parts = zonedParts(now, timeZone);

  return { year: parts.year, month: parts.month, day: parts.day };
}

function pad(value: number): string {
  return value < 10 ? `0${value}` : String(value);
}

/** "25/11" - how a Vietnamese shop writes a date on a whiteboard. */
export function shortDate(date: CivilDate): string {
  return `${pad(date.day)}/${pad(date.month)}`;
}

/** "2026-11-25", the form the custom-range `<input type="date">` speaks. */
export function toInputValue(date: CivilDate): string {
  return `${date.year}-${pad(date.month)}-${pad(date.day)}`;
}

/** Null for anything that is not a real calendar date. */
export function fromInputValue(value: string): CivilDate | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

  if (match === null) {
    return null;
  }

  const [year, month, day] = [
    Number.parseInt(match[1] ?? '', 10),
    Number.parseInt(match[2] ?? '', 10),
    Number.parseInt(match[3] ?? '', 10),
  ];

  const probe = new Date(Date.UTC(year, month - 1, day));

  // Rejects 31/02 and friends, which `Date.UTC` would roll over silently.
  return probe.getUTCMonth() + 1 === month && probe.getUTCDate() === day
    ? { year, month, day }
    : null;
}

function build(
  preset: RangePreset,
  firstDay: CivilDate,
  lastDay: CivilDate,
  timeZone: string,
  label?: string,
): DateRange {
  const fromMs = startOfDay(firstDay, timeZone);
  const toMs = startOfDay(addDays(lastDay, 1), timeZone);
  const oneDay =
    firstDay.year === lastDay.year &&
    firstDay.month === lastDay.month &&
    firstDay.day === lastDay.day;

  return {
    preset,
    label: label ?? RANGE_LABELS[preset],
    fromIso: new Date(fromMs).toISOString(),
    toIso: new Date(toMs).toISOString(),
    fromMs,
    toMs,
    bucket: oneDay ? 'hour' : 'day',
    firstDay,
    lastDay,
    timeZone,
  };
}

/** One of the six presets, resolved against the store's calendar. */
export function presetRange(
  preset: Exclude<RangePreset, 'custom'>,
  timeZone: string,
  now: Date = new Date(),
): DateRange {
  const current = today(timeZone, now);

  switch (preset) {
    case 'today':
      return build('today', current, current, timeZone);
    case 'yesterday': {
      const yesterday = addDays(current, -1);

      return build('yesterday', yesterday, yesterday, timeZone);
    }
    case 'last7':
      return build('last7', addDays(current, -6), current, timeZone);
    case 'last30':
      return build('last30', addDays(current, -29), current, timeZone);
    case 'thisMonth':
      return build('thisMonth', { ...current, day: 1 }, current, timeZone);
  }
}

/** A range the operator picked by hand; the ends are ordered for them. */
export function customRange(first: CivilDate, last: CivilDate, timeZone: string): DateRange {
  const ordered =
    startOfDay(first, timeZone) <= startOfDay(last, timeZone) ? [first, last] : [last, first];
  const [from, to] = ordered as [CivilDate, CivilDate];

  return build('custom', from, to, timeZone, `${shortDate(from)} – ${shortDate(to)}`);
}

/**
 * The window to compare against: the same length, immediately before, cut at
 * the same point through it.
 *
 * Cutting matters. At 09:00, "hôm nay" is two hours of trading; measuring it
 * against a whole yesterday would report a collapse in revenue every single
 * morning. So the comparison window ends as far into itself as the current one
 * has got - today-so-far against yesterday-to-the-same-hour.
 */
export function comparisonRange(range: DateRange, now: Date = new Date()): DateRange {
  const length = range.toMs - range.fromMs;
  const elapsed = Math.max(0, Math.min(now.getTime(), range.toMs) - range.fromMs);
  const fromMs = range.fromMs - length;
  const toMs = fromMs + elapsed;

  return {
    ...range,
    preset: 'custom',
    label: 'Kỳ trước',
    fromIso: new Date(fromMs).toISOString(),
    toIso: new Date(toMs).toISOString(),
    fromMs,
    toMs,
  };
}

/** How the trend is worded, which depends on what it compares against. */
export function comparisonLabel(preset: RangePreset): string {
  switch (preset) {
    case 'today':
      return 'so với hôm qua cùng giờ';
    case 'yesterday':
      return 'so với hôm kia';
    case 'last7':
      return 'so với 7 ngày trước đó';
    case 'last30':
      return 'so với 30 ngày trước đó';
    default:
      return 'so với kỳ trước';
  }
}

/**
 * The chart's columns.
 *
 * A day in progress stops at the current hour rather than drawing fourteen
 * empty columns to midnight: an empty column and a column of zero revenue look
 * identical, and only one of them is true.
 */
export function buildBuckets(range: DateRange, now: Date = new Date()): readonly Bucket[] {
  const buckets: Bucket[] = [];
  const end = Math.min(range.toMs, Math.max(range.fromMs, now.getTime()));

  if (range.bucket === 'hour') {
    const dayLabel = shortDate(range.firstDay);

    for (let hour = 0; ; hour += 1) {
      const fromMs = range.fromMs + hour * 3_600_000;

      if (hour > 23 || fromMs > end) {
        break;
      }

      const label = `${pad(hour)}:00`;

      buckets.push({
        key: `h${hour}`,
        label,
        fullLabel: `${label} ngày ${dayLabel}`,
        fromMs,
        toMs: fromMs + 3_600_000,
      });
    }

    return buckets;
  }

  for (let offset = 0; ; offset += 1) {
    const date = addDays(range.firstDay, offset);
    const fromMs = startOfDay(date, range.timeZone);

    if (fromMs >= range.toMs) {
      break;
    }

    const label = shortDate(date);

    buckets.push({
      key: toInputValue(date),
      label,
      fullLabel: `Ngày ${label}`,
      fromMs,
      toMs: startOfDay(addDays(date, 1), range.timeZone),
    });
  }

  return buckets;
}
