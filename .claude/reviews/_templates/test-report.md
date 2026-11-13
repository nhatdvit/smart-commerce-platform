# Test report — SCP-NNN

| | |
| --- | --- |
| **Task** | SCP-NNN |
| **Run** | YYYY-MM-DD |
| **By** | `qa-engineer` agent |
| **Verdict** | MEETS CRITERIA / FAILS / CANNOT VERIFY |

## What ran

Quote the real summary line from each command. "Tests pass" is not a result.

| Suite | Command | Result |
| --- | --- | --- |
| Style | `pnpm api:pint:check` | e.g. `PASS 89 files` |
| Static analysis | `pnpm api:stan` | e.g. `[OK] No errors` |
| PHP tests | `pnpm api:test` | e.g. `81 passed (181 assertions)` |
| Frontend | `pnpm lint && pnpm typecheck && pnpm build` | e.g. `12/12 tasks` |
| Formatting | `pnpm format:check` | |
| API contract | `pnpm openapi:conformance` | |

Environment: Docker stack up or not, which database the suite used.

## Acceptance criteria

Every criterion from the task, each mapped to the test that decides it. A
criterion with no test behind it is NOT VERIFIABLE, whatever the code looks
like.

| # | Criterion | Result | Decided by |
| --- | --- | --- | --- |
| AC1 | | PASS / FAIL / NOT VERIFIABLE | `tests/Feature/XTest.php::it_does_y` |

## Failures

#### F1 — <test name>

| | |
| --- | --- |
| **Where** | `tests/Feature/XTest.php:42` |
| **Breaks** | AC3 |

```
the actual assertion message
```

What the test expected and what it got. Not a proposed fix — that is the
implementer's call.

## Not verifiable, and why

Be specific. These are the gaps that must reach the pull request rather than
disappear:

| Criterion | Why it could not be verified |
| --- | --- |
| | No E2E harness exists in this repository |
| | No load or concurrency harness for this path |
| | The data needed does not exist in any fixture |

## Concurrency and failure paths

For a task touching money, stock or order state, say which test actually races
two transactions or simulates the failure, and name it. Reading the code and
concluding it looks correct is not verification, and should be reported as NOT
VERIFIABLE instead.

| Scenario from `CLAUDE.md` §12 | Covered by |
| --- | --- |
| Concurrent last-item purchase | |
| Price change during checkout | |
| Repeated create-order request | |
| Duplicate manual payment confirmation | |
| Invalid order transition | |
| Unauthorized confirmation | |
| Cross-store access | |
| Stock reservation expiry | |

## Bug fixes in this task

A fix is verified only when a test fails without it. For each fix, name that
test. If none exists, say the fix is unverified.
