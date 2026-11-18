---
name: task
description: Create a tracked task record from a development request, with scope, dependencies and acceptance criteria. Use when a new piece of work is described and nothing is tracking it yet.
argument-hint: <what the work is>
---

# /task — open a tracked task

Request: **$ARGUMENTS**

If that is empty, ask what the work is and stop. A task with no description is
a row in a table, not a task.

## 1. Check it is not already tracked

Read `.claude/tasks/README.md`. If an open task already covers this, say which
one and ask whether to extend it instead of opening a second. Two tasks for one
change is how a ledger stops being trustworthy.

## 2. Delegate

Call the `task-manager` agent with:

- the request verbatim
- the next free `SCP-NNN` id from the index
- the instruction to read `docs/specifications/system-analysis.md` §2 for
  dependency order and `CLAUDE.md` §5 for module ownership
- the instruction to split anything spanning more than one module, or mixing
  backend and frontend, into separate tasks with explicit dependencies

## 3. Check what came back

Before reporting success, read the created file yourself and confirm:

- Status is `BACKLOG`
- Acceptance criteria are decidable — a QA agent can mark each one pass or fail
  without interpreting intent
- Anything touching money, stock, authorization or order state quotes the
  matching invariant from `CLAUDE.md` §6
- Dependencies name real task ids or say "none"
- An open question from `CLAUDE.md` §15 that blocks the work is recorded as a
  blocker rather than answered

If criteria are vague, send them back to the agent with what is missing. Vague
criteria are the single commonest reason a task later gets argued about.

## 4. Report

The task id, its title, the acceptance criteria, any dependencies or blockers,
and that the next step is `/plan <ID>`.

Do not plan or implement in this turn. Scoping and designing are separate on
purpose: a plan written in the same breath as the scope tends to justify the
scope rather than examine it.
