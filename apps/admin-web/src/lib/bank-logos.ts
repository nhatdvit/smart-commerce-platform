/**
 * Bank logos for the bank picker, purely decorative.
 *
 * Fetched straight from VietQR's own public bank directory
 * (api.vietqr.io/v2/banks) in the browser - not through our API, and not
 * something our backend's `/admin/known-banks` depends on. Which BINs are
 * selectable is still decided entirely by the server's own
 * `KnownBank::DIRECTORY` (CLAUDE.md section 6); this only adds a picture next
 * to a name already on that list, matched by BIN. If the fetch fails or is
 * slow, the picker still works - it just shows no logos, the same way a
 * missing favicon never blocks a page.
 */

const BANKS_URL = 'https://api.vietqr.io/v2/banks';

interface VietQrBankListResponse {
  readonly data?: readonly { readonly bin?: string; readonly logo?: string }[];
}

/** bin -> logo URL. Fetched once per page life and shared by every caller. */
let logosRequest: Promise<Readonly<Record<string, string>>> | undefined;

export async function loadBankLogos(): Promise<Readonly<Record<string, string>>> {
  logosRequest ??= fetch(BANKS_URL)
    .then((response) => (response.ok ? (response.json() as Promise<VietQrBankListResponse>) : null))
    .then((body) => {
      const logos: Record<string, string> = {};

      for (const bank of body?.data ?? []) {
        if (typeof bank.bin === 'string' && typeof bank.logo === 'string') {
          logos[bank.bin] = bank.logo;
        }
      }

      return logos;
    })
    .catch(() => ({}));

  return logosRequest;
}
