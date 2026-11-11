---
name: test
description: Verify a task against its acceptance criteria by running the real suites, and write the test report. Use after /implement and before /review.
argument-hint: <TASK-ID>
---

# /test — verify against the criteria

Task: **$ARGUMENTS**

## 1. Validate state

Read `.claude/tasks/$ARGUMENTS.md`.

| Current status                                | What to do                                                     |
| --------------------------------------------- | -------------------------------------------------------------- |
| `TESTING`                                     | Proceed                                                        |
| `IN_PROGRESS`                                 | Proceed, but say the implementation reported itself unfinished |
| `REVIEW`, `READY_FOR_PR`                      | Proceed — re-verifying is always allowed                       |
| `BACKLOG`, `ANALYZING`, `PLANNED`, `APPROVED` | Stop. There is nothing built to test                           |
| `BLOCKED`, `CANCELLED`                        | Stop                                                           |

## 2. Make sure the stack is up

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml ps
pnpm infra:up      # if it is not
```

If Docker is not running, stop and say the suites could not be run. Do not
reason about what they would have reported — that is the one failure mode this
whole step exists to prevent.

## 3. Delegate

Call the `qa-engineer` agent with the task id and its acceptance criteria. It
has no editing tools, so it cannot make a test pass by changing it.

Instruct it to write `.claude/reviews/$ARGUMENTS/test-report.md` from
`.claude/reviews/_templates/test-report.md`, and to map every acceptance
criterion to PASS, FAIL or NOT VERIFIABLE with the test that decides it.

## 4. Check the report is evidence, not assertion

Read it. It must quote real output — "81 passed (181 assertions)" — not "tests
pass". If a criterion is NOT VERIFIABLE, the reason must be concrete: there is
no E2E harness in this repository, there is no load harness, the data does not
exist. "Could not be checked" with no reason is not acceptable.

Be sceptical of a clean report on a change that touches concurrency, money or
authorization. Ask which test actually races two transactions, and read it.

## 5. Record

Write the counts and the verdict into the task file's `Test Results`.

- All criteria PASS → set `REVIEW`, next step `/review $ARGUMENTS`
- Any FAIL → leave `IN_PROGRESS`, report the failures, next step `/fix $ARGUMENTS`
- Criteria NOT VERIFIABLE → say so explicitly in the report to the Tech Lead.
  A task can still proceed, but the gap goes in the pull request, not into
  silence.

Report the real numbers, the failures, and the gaps.
