'use client';

/**
 * The shape of the window, at thumbnail size.
 *
 * No axes, no labels, no tooltip: a sparkline answers "which way has this been
 * going", and the exact figure is the big number right above it. It is
 * therefore `aria-hidden` - the value and the trend beside it already say
 * everything this draws, and a screen reader does not need the outline twice.
 *
 * `preserveAspectRatio="none"` stretches the path to whatever width the card
 * has; `vector-effect="non-scaling-stroke"` keeps the line 2px while that
 * happens, which is the whole trick that makes one viewBox fit every card.
 */
export function Sparkline({
  values,
  tone,
}: {
  readonly values: readonly number[];
  readonly tone: SparkTone;
}) {
  if (values.length < 2) {
    return null;
  }

  const peak = Math.max(...values, 0);
  const floor = Math.min(...values, 0);
  const span = peak - floor;
  const step = 100 / (values.length - 1);

  const points = values.map((value, index) => {
    const x = index * step;
    // 2 and 26 rather than 0 and 28: a flat line at either extreme would
    // otherwise be clipped in half by the stroke's own width.
    const y = span === 0 ? 14 : 26 - ((value - floor) / span) * 24;

    return `${x.toFixed(2)},${y.toFixed(2)}`;
  });

  return (
    <svg
      className={`spark spark-${tone}`}
      viewBox="0 0 100 28"
      preserveAspectRatio="none"
      aria-hidden="true"
      focusable="false"
    >
      <polyline
        points={points.join(' ')}
        fill="none"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}

export type SparkTone = 'gold' | 'blue' | 'teal' | 'navy';
