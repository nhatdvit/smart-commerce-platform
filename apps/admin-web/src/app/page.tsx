import { publicEnv } from '@/lib/env';

/**
 * Placeholder dashboard for Phase 01. Menu management, the order board,
 * kitchen display and manual payment confirmation land in later milestones
 * (CLAUDE.md sections 1 and 5).
 */
export default function DashboardPage() {
  return (
    <main className="container mx-auto flex min-h-dvh max-w-screen-md flex-col justify-center gap-6 px-4 py-12">
      <header className="space-y-2">
        <p className="text-sm font-medium uppercase tracking-wide text-muted-foreground">
          Phase 01 - Foundation
        </p>
        <h1 className="text-3xl font-semibold tracking-tight">{publicEnv.siteName}</h1>
        <p className="text-muted-foreground">
          No operations screens are implemented yet. Authentication, RBAC and store-scoped
          authorization must be in place before any admin data is exposed here.
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
