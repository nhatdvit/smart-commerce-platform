---
name: plan
description: Produce the technical plan for a tracked task - modules, API contract, database changes, transaction boundaries, tests and risks - then stop for Tech Lead approval. Use after /task and before /implement.
argument-hint: <TASK-ID>
---

# /plan — design before code

Task: **$ARGUMENTS**

## 1. Validate state

Read `.claude/tasks/$ARGUMENTS.md`. If it does not exist, say so and stop.

| Current status         | What to do                                                                               |
| ---------------------- | ---------------------------------------------------------------------------------------- |
| `BACKLOG`, `ANALYZING` | Proceed                                                                                  |
| `PLANNED`              | Say a plan exists; ask whether to revise it                                              |
| `APPROVED` or later    | Stop. Re-planning approved work silently invalidates the approval the Tech Lead gave     |
| `BLOCKED`              | Stop and report the blocker. Planning around a blocker produces a plan nobody can act on |
| `CANCELLED`            | Stop                                                                                     |

Set the status to `ANALYZING` before delegating, so a parallel session can see
the task is being worked on.

## 2. Delegate

Call the `software-architect` agent with the task id, its acceptance criteria,
and the instruction to write into the `Technical Plan` section of the task file
and nowhere else.

## 3. Check what came back

The plan is not usable unless it answers all of these. Send it back if any is
missing:

- Which modules and which layer inside each
- The API contract change, including the exact edit to `docs/api/openapi.yaml`
- Database changes with types, constraints and indexes
- **Transaction boundaries** — what runs inside one `DB::transaction()`, where
  rows are locked, which outbox events are written in the same transaction
- Idempotency for anything a client can retry
- The permission code and the store-scope check, both named
- The tests the plan requires, including the concurrency and failure cases from
  `CLAUDE.md` §12
- Risks, each with what would trigger it and what would catch it

If the task cannot be planned without a decision from `CLAUDE.md` §15, set
`BLOCKED`, name the decision, and stop. Do not invent business policy to
produce a complete-looking plan.

## 4. Stop for approval

Set the status to `PLANNED` and present to the Tech Lead:

- The plan, in short
- What it changes architecturally, if anything
- The risks
- Whether it needs approval beyond "start work": a module boundary change, new
  infrastructure, a destructive migration, or anything touching payment or
  authorization architecture

Then stop and ask. **`PLANNED → APPROVED` is not yours to set.** Record the
approver and the date in the task file once they answer, and only then is
`/implement` available.

This gate exists because the cheapest moment to catch a wrong design is before
anyone writes the code it implies.
