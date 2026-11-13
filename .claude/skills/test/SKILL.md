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

Instruct it to run the suites and return, in its final report: every acceptance
criterion mapped to PASS, FAIL or NOT VERIFIABLE, the command that decides each
one, and the command's real output.

**You write the file, not the agent.** Transcribe what it returned into
`.claude/reviews/$ARGUMENTS/test-report.md` using
`.claude/reviews/_templates/test-report.md`, and keep its numbers verbatim.
The agent deliberately has no editing tools — that is what stops it making a
test pass by changing it — so telling it to write its own report asks for a
file it cannot produce, and the report quietly goes missing (SCP-000 test
report, N2). Transcribing is not a rubber stamp: step 4 is you reading what you
are about to write down.

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
