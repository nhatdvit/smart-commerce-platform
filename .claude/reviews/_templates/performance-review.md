# Performance review — SCP-NNN

| | |
| --- | --- |
| **Task** | SCP-NNN |
| **Reviewed** | YYYY-MM-DD |
| **Reviewer** | `performance-reviewer` agent |
| **Why reviewed** | which trigger: migrations / new queries / list endpoint / job / resource relations |
| **Verdict** | ACCEPTABLE / CHANGES REQUIRED / BLOCKED |

Targets from `CLAUDE.md` §10: p95 read API under 300ms, p95 order-write under
800ms, excluding external providers. Judge against those numbers, not against
an impression.

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 0 | yes |
| MEDIUM | 0 | no |
| LOW | 0 | no |

## Findings

<!-- Quantify. "One query per order line, so 40 queries for a 40-line order"
     is a finding. "This could be slow" is not. -->

#### P1 — <one-line claim>

| | |
| --- | --- |
| **Where** | `path/to/file.php:123` |
| **Class** | N+1 / missing index / unbounded result / work inside transaction / lock scope / queue / caching / frontend |
| **Severity** | CRITICAL / HIGH / MEDIUM / LOW |

**The cost, with numbers.** Queries per request, rows scanned, bytes added to
the bundle.

**How it was measured.** `EXPLAIN ANALYZE`, a query log, a build report — or
state that it was read from the code and not measured.

**What to change.**

## Query checks

| Check | Status |
| --- | --- |
| No query inside a loop over a model collection | |
| API Resources do not lazy-load a relation per row | |
| Every new `where` / `order by` / join has an index that serves it | |
| Every new index is reachable by a query in this diff | |
| Query plans checked for the hot paths, not assumed | |

## Boundaries

| Check | Status |
| --- | --- |
| Every list endpoint is cursor-paginated | |
| No `->get()` that could return an unbounded set | |
| No HTTP call or long computation inside `DB::transaction()` | |
| Row locks no wider or longer than the invariant needs | |

## Queues and caching

| Check | Status |
| --- | --- |
| Work that does not belong in a request is queued | |
| Jobs have bounded retries and backoff | |
| Redis is not the source of truth for orders, payments or stock | |
| Every cache entry has an invalidation path | |
| Store-scoped values cached under store-scoped keys | |

A cache key that is not store-scoped for store-scoped data is a data leak, not
a performance finding — hand it to the security reviewer as well.

## Measured, not measured

Say plainly which findings were measured and which were read from the code.
An estimate presented as a measurement is the one thing this report must not do.
