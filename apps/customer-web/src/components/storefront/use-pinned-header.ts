'use client';

import { useEffect, useState } from 'react';

/**
 * The header starts in normal document flow (not stuck) so it never covers
 * the hero banner on the very first scroll - with the hero being the page's
 * first section, a header that is `position: sticky` from scroll position
 * zero has no "slack" and immediately overlaps it. Once the hero has
 * scrolled completely out of view, the header may safely pin itself to the
 * top for the rest of the page.
 */
export function usePinnedHeader(heroId: string): boolean {
  const [pinned, setPinned] = useState(false);

  useEffect(() => {
    const hero = document.getElementById(heroId);
    if (hero === null) {
      return undefined;
    }

    const observer = new IntersectionObserver(([entry]) => {
      setPinned(entry !== undefined && !entry.isIntersecting);
    });
    observer.observe(hero);

    return () => observer.disconnect();
  }, [heroId]);

  return pinned;
}
