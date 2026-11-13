# Code review — SCP-NNN

| | |
| --- | --- |
| **Task** | SCP-NNN |
| **Reviewed** | YYYY-MM-DD |
| **Reviewer** | `code-reviewer` agent |
| **Diff** | `git diff origin/main...HEAD`, N files, +N/-N |
| **Verdict** | READY / CHANGES REQUESTED / BLOCKED |

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 0 | yes |
| MEDIUM | 0 | no |
| LOW | 0 | no |

One or two sentences: does this implement the approved plan, and is it safe to
put in a pull request.

## Did it implement what was approved?

Compare the diff against the `Technical Plan` in `.claude/tasks/SCP-NNN.md`.
Clean code that does something other than what was approved is a finding, not a
matter of taste. Note anything in the diff that the plan does not cover, and
anything in the plan the diff does not do.

## Findings

### CRITICAL

<!--
  One block per finding. A finding without a concrete failure scenario is a
  suspicion - say so, or drop it. "Inputs X with state Y produces Z, which is
  wrong because W" is a finding; "this looks fragile" is not.
-->

#### C1 — <one-line claim>

| | |
| --- | --- |
| **Where** | `path/to/file.php:123` |
| **Category** | correctness / invariant / authorization / migration / error-handling / maintainability |
| **Source** | manual review, or the `/code-review` skill when it is installed |

**What is wrong.**

**Failure scenario.** Inputs and state that produce the wrong result.

**What to change.**

### HIGH

### MEDIUM

### LOW

## Invariant checklist

`CLAUDE.md` §6, for the ones this diff could affect. Mark N/A where the diff
cannot touch it, rather than ticking it unexamined.

| Invariant | Status |
| --- | --- |
| Money is integer đồng, never float | |
| Price, modifiers, menu schedule and availability revalidated server-side | |
| Checkout idempotent, key persisted with its result | |
| Overselling prevented by row lock or conditional update, not Redis alone | |
| Order lines hold immutable snapshots | |
| Order state and payment state are separate machines | |
| Business state and outbox event written in one transaction | |
| Admin path checks permission **and** store scope | |
| Cross-store resource answers 404, not 403 | |
| Domain layer free of Eloquent, facades and HTTP | |

## Not defects

Things that look wrong and are not, with why. This section stops the next
reviewer re-raising them.
