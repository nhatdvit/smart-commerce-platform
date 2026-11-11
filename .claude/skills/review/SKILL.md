---
name: review
description: Review a task's diff independently - code, and security or performance when the change calls for it - consolidate the findings and decide whether it can go to a pull request. Use after /test is green.
argument-hint: <TASK-ID>
---

# /review — independent review of the diff

Task: **$ARGUMENTS**

## 1. Validate state

Read `.claude/tasks/$ARGUMENTS.md`.

| Current status         | What to do                                                                                                                                                                        |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `REVIEW`               | Proceed                                                                                                                                                                           |
| `TESTING`              | Check `.claude/reviews/$ARGUMENTS/test-report.md` exists and is green. If not, run `/test` first — reviewing untested code wastes the reviewer on defects a test would have found |
| `READY_FOR_PR`         | Proceed; re-review is allowed                                                                                                                                                     |
| Anything earlier       | Stop. There is no diff to review                                                                                                                                                  |
| `BLOCKED`, `CANCELLED` | Stop                                                                                                                                                                              |

## 2. Get the diff

```bash
git diff main...HEAD --stat
git diff main...HEAD
```

An empty diff means nothing was implemented. Say so and stop.

## 3. Decide which reviewers the change needs

Code review always. The other two by what the diff actually touches:

| Trigger in the diff                                                                                          | Also call              |
| ------------------------------------------------------------------------------------------------------------ | ---------------------- |
| `Modules/Payment/**`, `Modules/Identity/**`, auth, tokens, `store_bank_accounts`, anything with money or PII | `security-reviewer`    |
| migrations, new queries, list endpoints, jobs, `Resource` classes touching relations                         | `performance-reviewer` |
| changes to `.env.example`, compose, nginx, CI                                                                | `security-reviewer`    |

Say which you chose and why. Calling all three on a typo fix burns budget;
skipping security on a payment change is the expensive mistake.

## 4. Delegate, in parallel

Call the chosen agents. All three are read-only, so none can fix what it finds
and none can approve its own work — the implementer does not review itself.

Each writes its report under `.claude/reviews/$ARGUMENTS/`:

```
code-review.md
security-review.md        when called
performance-review.md     when called
```

Also run the repository's own `/code-review` skill and fold its confirmed
findings in rather than re-deriving them.

## 5. Consolidate

Read the reports yourself. Then:

- Merge duplicates — three agents often find one defect.
- Sanity-check severity. A broken invariant is CRITICAL whatever the report
  says; a naming preference is LOW however firmly it is argued.
- Drop findings with no concrete failure scenario, or mark them as suspicions.
  A reviewer that cannot say what input produces the wrong output has found a
  smell, not a defect.
- Write the consolidated counts into the task file's `Review Findings` and
  `Security Findings`.

## 6. Decide

| Outcome                                       | Status              | Next              |
| --------------------------------------------- | ------------------- | ----------------- |
| No CRITICAL, no HIGH                          | `READY_FOR_PR`      | `/pr $ARGUMENTS`  |
| Any CRITICAL or HIGH                          | `CHANGES_REQUESTED` | `/fix $ARGUMENTS` |
| Blocking finding that cannot be resolved here | `BLOCKED`           | Ask the Tech Lead |

MEDIUM and LOW findings do not block. Record them; if they are not being fixed
now, say which ones and why, so the decision is visible rather than forgotten.

Report counts by severity, every blocking finding in full, and the verdict.
