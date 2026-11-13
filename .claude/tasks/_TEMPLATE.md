# SCP-NNN — <title>

<!--
  Copy this file to .claude/tasks/SCP-NNN.md and fill it in. Keep every heading,
  even when a section is empty: "Security Findings: none, no security review was
  needed" is information, and a missing heading is not.

  The Activity log at the bottom is appended to by
  .claude/hooks/record-task-activity.mjs. It must stay last in the file, and the
  marker comment must stay exactly as it is, or the hook leaves the file alone.
-->

| Field | Value |
| --- | --- |
| **Task ID** | SCP-NNN |
| **Status** | BACKLOG |
| **Priority** | P2 |
| **Assigned Agent** | — |
| **Created** | YYYY-MM-DD |
| **Git Branch** | — |
| **Pull Request** | — |

Status is one of `BACKLOG`, `ANALYZING`, `PLANNED`, `APPROVED`, `IN_PROGRESS`,
`TESTING`, `REVIEW`, `READY_FOR_PR`, `DONE`, `BLOCKED`, `CHANGES_REQUESTED`,
`CANCELLED`. Priority is P0 (production is broken) to P3 (whenever).

## Description

What is being asked for, and why it matters. Two or three sentences. If the
reason is "a §15 decision was made", say which one and what was decided.

## Dependencies

- `SCP-NNN` — what this task needs from it, not just that it depends on it
- none

## Acceptance Criteria

Each one decidable: a QA agent must be able to mark it PASS or FAIL without
interpreting intent. Quote the invariant from `CLAUDE.md` §6 where one applies,
rather than paraphrasing it.

- [ ] AC1 —
- [ ] AC2 —

## Technical Plan

Written by the `software-architect` agent. Needs all of:

- **Modules and layers** touched
- **API contract** change, including the exact edit to `docs/api/openapi.yaml`
- **Database** changes: columns, types, constraints, indexes, migration order
- **Transaction boundaries**: what runs in one `DB::transaction()`, what is
  locked, which outbox events are written in the same transaction
- **Idempotency** for anything a client can retry
- **Authorization**: the permission code and the store-scope check
- **Tests required**, including the concurrency and failure cases
- **Risks**, each with what triggers it and what catches it

### Approval

| | |
| --- | --- |
| Needs Tech Lead approval | yes / no — and why |
| Approved by | — |
| Approved on | — |

`PLANNED → APPROVED` is set only by a human.

## Files Changed

| File | What changed |
| --- | --- |

## Test Results

Real output, not claims. From `.claude/reviews/SCP-NNN/test-report.md`.

| Suite | Command | Result |
| --- | --- | --- |

Criteria not verifiable, and why:

## Review Findings

From `.claude/reviews/SCP-NNN/code-review.md`.

The number in `Unresolved` is what the traceability CI gate reads: a pull
request is refused while CRITICAL or HIGH is anything but 0. Keep this table's
shape — it is parsed.

The cells ship as `—`, not `0`, and that is deliberate (SCP-000 review H2).
Pre-filled zeros made "clean review" the default state of every new task, so a
record that nobody had reviewed satisfied the one gate standing at the point of
no return. `—` fails the gate until a person writes a number, which is the
right way round: the gate should be waiting on the review, not assuming it.

| Severity | Unresolved | Note |
| --- | --- | --- |
| CRITICAL | — | not reviewed yet |
| HIGH | — | not reviewed yet |
| MEDIUM | — | not reviewed yet |
| LOW | — | not reviewed yet |

Findings rejected as not-a-defect, with the reasoning:

## Security Findings

From `.claude/reviews/SCP-NNN/security-review.md`, or "not required because the
change touches no auth, money, PII or store-scoped data".

## Performance Findings

From `.claude/reviews/SCP-NNN/performance-review.md`, or "not required".

## Blockers

What is blocking, and who can clear it. An unresolved `CLAUDE.md` §15 decision
is a legitimate blocker — and inventing the policy to get moving is not an
alternative to recording it.

## Completion Summary

Written when the pull request is merged. What shipped, what was deliberately
left out, and what the next task should pick up.

## Activity log

<!-- activity-log:appended-by-hook -->

| When (UTC) | What | Detail |
| --- | --- | --- |
