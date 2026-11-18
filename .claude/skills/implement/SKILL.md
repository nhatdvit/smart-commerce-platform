---
name: implement
description: Implement an approved task on its own branch, delegating to the Laravel or Next.js developer agent, then run the checks. Requires the task to be APPROVED. Use after /plan has been approved.
argument-hint: <TASK-ID>
---

# /implement — build the approved plan

Task: **$ARGUMENTS**

## 1. Validate state — this gate is the point

Read `.claude/tasks/$ARGUMENTS.md`.

| Current status                 | What to do                                                            |
| ------------------------------ | --------------------------------------------------------------------- |
| `APPROVED`                     | Proceed                                                               |
| `IN_PROGRESS`                  | Say work is already underway; ask whether to continue it              |
| `CHANGES_REQUESTED`            | Stop. Use `/fix $ARGUMENTS`, which carries the findings               |
| `PLANNED`                      | **Stop.** The plan has not been approved. Say so and point at `/plan` |
| `BACKLOG`, `ANALYZING`         | Stop. There is no plan to implement                                   |
| `BLOCKED`, `CANCELLED`, `DONE` | Stop and report why                                                   |

Do not proceed on a task that is merely planned, however obvious the work
looks. The approval is what separates this workflow from one where an agent
designs and builds in one unexamined motion.

## 2. Branch

Current branch must be the task's own. If it is not, ask the `git-release`
agent to cut one from `main`:

```
feature/$ARGUMENTS-<short-description>
fix/$ARGUMENTS-<short-description>
refactor/$ARGUMENTS-<short-description>
chore/$ARGUMENTS-<short-description>
```

The task id in the branch name is required: the activity-log hook and the
traceability CI job both read it. Record the branch in the task file.

Never implement on `main`, and never on a branch that belongs to another task.

## 3. Pick the implementer from the plan

| Files in the plan                                             | Agent                                                          |
| ------------------------------------------------------------- | -------------------------------------------------------------- |
| `apps/api/**`                                                 | `laravel-developer`                                            |
| `apps/customer-web/**`, `apps/admin-web/**`, `packages/ui/**` | `nextjs-developer`                                             |
| Both                                                          | Both, backend first — the frontend needs the contract to exist |

Give the agent the task id, the approved plan verbatim, the acceptance
criteria, and the instruction not to go beyond the plan. Work found along the
way that is out of scope becomes a new task, not a bigger diff.

Set the status to `IN_PROGRESS`.

## 4. Run the checks yourself

Do not take the agent's word for it. Run what the change calls for:

```bash
pnpm api:check                                  # backend: Pint, PHPStan, Pest
pnpm lint && pnpm typecheck && pnpm build       # frontend
pnpm format:check
pnpm openapi:conformance                        # if the API contract moved
```

If the API contract moved, `pnpm api:openapi` must have been run and
`docs/api/openapi.yaml` updated. CI fails otherwise.

## 5. Record and hand on

Write into the task file: files changed, branch, what you ran and its real
output. Set the status to `TESTING`.

If a check failed, say so and leave the status at `IN_PROGRESS`. A failing
check is not a detail to mention after declaring the work done.

Report what was built, what ran, and that the next step is `/test $ARGUMENTS`.
