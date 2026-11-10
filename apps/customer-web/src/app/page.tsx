import { publicEnv } from '@/lib/env';

/**
 * Placeholder home page for Phase 01. The scheduled menu, cart and checkout
 * flows land in the MVP milestone (CLAUDE.md section 1).
 */
export default function HomePage() {
  return (
    <main className="container mx-auto flex min-h-dvh max-w-screen-sm flex-col justify-center gap-6 px-4 py-12">
      <header className="space-y-2">
        <p className="text-sm font-medium uppercase tracking-wide text-muted-foreground">
          Phase 01 - Foundation
        </p>
        <h1 className="text-3xl font-semibold tracking-tight">{publicEnv.siteName}</h1>
        <p className="text-muted-foreground">
          The customer ordering experience is not implemented yet. This app boots so the workspace,
          Docker stack and CI pipeline can be verified end to end.
        </p>
      </header>

      <section className="rounded-lg border border-border p-4">
        <h2 className="text-sm font-semibold">API endpoint</h2>
        <code className="mt-1 block break-all text-sm text-muted-foreground">
          {publicEnv.apiBaseUrl}
        </code>
      </section>
    </main>
  );
}
