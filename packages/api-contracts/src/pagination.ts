/** Cursor pagination is the default for list endpoints (CLAUDE.md §8). */
export interface CursorPageQuery {
  /** Opaque cursor returned by a previous page. */
  cursor?: string;
  /** Page size; the API clamps this to a documented maximum. */
  limit?: number;
}

export interface CursorPage<T> {
  items: T[];
  nextCursor: string | null;
  hasMore: boolean;
}
