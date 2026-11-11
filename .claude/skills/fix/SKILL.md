---
name: fix
description: Send confirmed review findings back to the implementing agent, apply the fixes, and re-run the affected tests. Use when /review or /test came back with blocking findings.
argument-hint: <TASK-ID>
---

# /fix — close the review findings

Task: **$ARGUMENTS**

## 1. Validate state

Read `.claude/tasks/$ARGUMENTS.md`.

| Current status                   | What to do                                                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `CHANGES_REQUESTED`              | Proceed                                                                                                            |
| `IN_PROGRESS` with failing tests | Proceed                                                                                                            |
| `READY_FOR_PR`                   | Stop. Nothing is blocking. If you want to fix a MEDIUM or LOW anyway, say so and treat it as a small scoped change |
| Anything earlier                 | Stop                                                                                                               |

## 2. Gather the findings

Read everything under `.claude/reviews/$ARGUMENTS/`. Build one list, ordered
CRITICAL, HIGH, then anything lower you have decided to take.

For each finding, decide and say which it is:

- **Confirmed** — fix it.
- **Not a defect** — say why, in the task file. A reviewer can be wrong, and
  recording the disagreement is better than silently ignoring it.
- **Out of scope** — a real problem, but not this task's. Open a new task
  rather than growing this diff.

Do not fix a finding you have not understood. A change made to silence a
reviewer is how a defect becomes two defects.

## 3. Delegate the fixes

Call the agent that implemented the code — `laravel-developer` or
`nextjs-developer` — with the confirmed findings, each with its file, line and
failure scenario, and the instruction to fix those and nothing else.

Each fix needs a test that would have caught the defect. A fix with no such
test is not a fix, it is a hope; the QA agent will say the same thing in the
next step, so do it now.

## 4. Re-run what the fix touched

```bash
pnpm api:check                                  # backend
pnpm lint && pnpm typecheck && pnpm build       # frontend
```

Run the whole relevant suite, not just the one test that was failing. Fixes
that break something else are common, and that is exactly what a full run
catches.

## 5. Record and loop

Write into the task file: which findings were fixed, which were rejected and
why, which became new tasks, and the commit SHAs.

Then set `REVIEW` and go back to `/review $ARGUMENTS`. The loop repeats until
no CRITICAL or HIGH remains. That is not bureaucracy: a fix is a change, and an
unreviewed change is how the thing a reviewer already caught comes back.

If after two rounds the same finding is still open, stop and ask the Tech Lead.
Either the finding is wrong or the design is, and a third attempt at the same
fix will not settle which.
