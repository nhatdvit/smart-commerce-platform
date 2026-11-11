---
name: pr
description: Prepare and open the pull request for a task that passed review, with acceptance criteria, real test output and review summary in the body. Requires READY_FOR_PR and no unresolved CRITICAL or HIGH. Never merges.
argument-hint: <TASK-ID>
---

# /pr — open the pull request

Task: **$ARGUMENTS**

## 1. Validate state and findings

Read `.claude/tasks/$ARGUMENTS.md` and everything under
`.claude/reviews/$ARGUMENTS/`.

| Condition                                 | What to do                                                                     |
| ----------------------------------------- | ------------------------------------------------------------------------------ |
| Status is `READY_FOR_PR`                  | Proceed                                                                        |
| Status is `REVIEW` or `CHANGES_REQUESTED` | Stop. Review is not finished                                                   |
| Status is `TESTING` or earlier            | Stop                                                                           |
| Any unresolved CRITICAL or HIGH finding   | **Stop.** This is the gate; it does not get waived because the work feels done |
| No `code-review.md`                       | Stop. An unreviewed change does not go to a pull request                       |
| No `test-report.md`                       | Stop                                                                           |

## 2. Check prerequisites rather than assume them

```bash
gh auth status
git status --short
git log --oneline main..HEAD
```

If `gh` is not authenticated or lacks permission, say exactly what is missing
and stop. Do not describe a pull request as opened when it was not.

The working tree must be clean and the branch pushed. If there are uncommitted
changes, ask the `git-release` agent to commit them — after checking they all
belong to this task.

## 3. Compose the body

It is read by someone deciding whether to merge, so it carries evidence, not
claims:

- **What changed and why**, in a few sentences.
- **Acceptance criteria**, each with PASS or NOT VERIFIABLE and the test that
  decides it.
- **Test output**, the real summary lines.
- **Review summary**: counts by severity, which agents reviewed, what was
  rejected as not-a-defect and why.
- **Anything NOT VERIFIABLE**, stated plainly. A gap in the open is a gap a
  reviewer can weigh; a gap in silence is a surprise in production.
- **Rollback**, for anything touching migrations, payments or order state.
- **Task id** and a link to `.claude/tasks/$ARGUMENTS.md`.

## 4. Open it

Delegate to the `git-release` agent, or run it yourself:

```bash
gh pr create --base main --title "<type>(<scope>): <description> [$ARGUMENTS]" --body-file <file>
```

No `--draft` unless asked. Record the URL in the task file — the PostToolUse
hook does this automatically; check that it did.

## 5. Report CI, do not predict it

```bash
gh pr checks <number>
```

Report what it says now. If checks are still queued, say they are queued.

## 6. Stop

**Do not merge.** `gh pr merge` is blocked by a hook, deliberately: merging is
a human decision after CI is green and a reviewer has read the diff.

Leave the status at `READY_FOR_PR`. `DONE` is set once the pull request is
merged, and a merged pull request is the evidence — a green CI run is not.

Tell the Tech Lead the PR is open, what CI currently says, and that merging is
theirs.
