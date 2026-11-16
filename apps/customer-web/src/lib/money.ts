/**
 * Vietnamese đồng formatting.
 *
 * The grouping is built by hand rather than with `toLocaleString('vi-VN')`:
 * the storefront is pre-rendered on the server and hydrated in the browser,
 * and any difference between the two ICU data sets would surface as a
 * hydration mismatch. VND has no minor unit, so integer grouping with a dot
 * separator reproduces the `vi-VN` output exactly.
 *
 * Display only. Authoritative amounts come from the API as integer đồng
 * (CLAUDE.md section 6).
 */
export function money(amount: number): string {
  const rounded = Math.round(amount);
  const sign = rounded < 0 ? '-' : '';
  const grouped = Math.abs(rounded)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, '.');

  return sign + grouped + 'đ';
}
