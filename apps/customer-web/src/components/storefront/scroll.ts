/** Smooth in-page navigation, as the delivered design's `data-jump` buttons did. */
export function scrollToSection(id: string): void {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
}

export function scrollToTop(): void {
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
