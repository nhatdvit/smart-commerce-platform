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

## 2. Get the diff, and put it where the reviewers can read it

The three reviewers hold **no `Bash`** (SCP-000 review S4), so they cannot run
`git` themselves. You materialise the diff for them:

```bash
# origin/main, not main: a local main may be stale or absent, and then the
# reviewer reads main's own work as if it were this task's (SCP-000 review L4).
git fetch origin
git diff origin/main...HEAD --stat
mkdir -p .claude/reviews/$ARGUMENTS
git diff origin/main...HEAD > .claude/reviews/$ARGUMENTS/diff.patch
```

`diff.patch` is gitignored. Give each agent that path and the `--stat` summary
in its brief; they read it with `Read`.

An empty diff means nothing was implemented. Say so and stop.

### When a reviewer needs a command run

Dropping `Bash` also took away the probes: `pnpm audit` and `composer audit`
for the security reviewer, `EXPLAIN ANALYZE` for the performance one, and
running the code under review for anyone. Tell each agent it may ask, in its
report, for specific commands to be run — then run them, send the real output
back with `SendMessage`, and let it revise.

Be straight with yourself about what that costs. You are usually the session
that wrote or fixed the code, so a probe you run is evidence from an interested
party. The trade was deliberate: an agent with no write channel cannot author
its own clean verdict, which is the failure that actually ends a review's
worth. Weaker independence of _evidence_ was judged the better price than
weaker independence of _action_. Say in the report who ran what.

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

Call the chosen agents. All three hold only `Read`, `Glob` and `Grep` — no
`Write`, no `Edit`, no `Bash` — so none of them can fix what it finds, write
its own verdict, or touch the record that says a finding existed. That is a
boundary rather than an instruction, which is what the earlier `Bash` grant
made it impossible to claim.

Each returns its findings to you, with file, line, severity and the concrete
failure scenario for each. **You write the reports, not the agents.**
Transcribe each one under `.claude/reviews/$ARGUMENTS/`:

```
code-review.md
security-review.md        when called
performance-review.md     when called
```

If a general-purpose `/code-review` skill is installed in this environment,
run it and fold its confirmed findings in rather than re-deriving them. It is
**not** part of this repository and is often absent (SCP-000 review M5). When
it is not there, say so in your report in one line and move on - an automated
pass nobody can see the output of is worse than a stated gap.

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
