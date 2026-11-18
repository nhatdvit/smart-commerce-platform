---
name: status
description: Report where a task stands, or show the whole task board - status, branch, commits, test results, findings and what is next. Read-only.
argument-hint: <TASK-ID or "all">
---

# /status — where things stand

Target: **$ARGUMENTS** (empty or `all` means the whole board)

Read-only. This skill changes nothing: a status command that adjusts state is a
status command nobody trusts.

## One task

Read `.claude/tasks/$ARGUMENTS.md` and everything under
`.claude/reviews/$ARGUMENTS/`. Then report:

|              |                                                    |
| ------------ | -------------------------------------------------- |
| Status       | and when it last changed                           |
| Branch       | and whether it exists locally and on `origin`      |
| Commits      | SHAs and subjects from the activity log            |
| Tests        | the real counts from the test report, or "not run" |
| Findings     | counts by severity, blocking ones named            |
| Pull request | URL and CI state, if one is open                   |
| Blockers     | what and who can clear it                          |
| Next step    | the one command to run                             |

**Verify rather than quote.** The task file is a record and records drift.
Check the facts that are cheap to check:

```bash
git branch --list "*$ARGUMENTS*"
git log --oneline origin/main..HEAD
gh pr list --search "$ARGUMENTS" --state all --json number,url,state
gh pr checks <number>      # if a PR is open
```

If the file and reality disagree — the file says `IN_PROGRESS` but the branch
has no commits, or says `READY_FOR_PR` but no review report exists — report the
discrepancy as the finding. A ledger that is wrong is more dangerous than no
ledger, because people act on it.

## The whole board

Read `.claude/tasks/README.md` and every task file. Report a table grouped by
status, oldest first within each group, and then three things:

- Tasks `BLOCKED`, with what is blocking them. These are where the work is
  actually stuck.
- Tasks `IN_PROGRESS` with no commits in several days. These are where the
  ledger is probably lying.
- Tasks `READY_FOR_PR` with no pull request. These are finished work nobody can
  review.

Close with what to do next, and nothing else. No plan, no implementation, no
status changes.
