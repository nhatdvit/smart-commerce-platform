/**
 * Cursor pagination is the default for list endpoints (CLAUDE.md §8).
 *
 * Field names are the wire names. The API speaks snake_case — see the
 * `PageMeta` schema in `docs/api/openapi.yaml` and the Pagination section of
 * `docs/api/README.md` — so these interfaces describe a response as it arrives,
 * not a camelCase translation of one. A client that renames fields on the way in
 * is free to, but the contract is what is written here.
 */
export interface CursorPageQuery {
  /** Opaque cursor returned by a previous page. Not a page number or an offset. */
  cursor?: string;
  /** Page size; the API clamps this to a documented maximum. */
  limit?: number;
}

export interface CursorPage<T> {
  items: T[];
  /** Null exactly when `has_more` is false. */
  next_cursor: string | null;
  has_more: boolean;
}
