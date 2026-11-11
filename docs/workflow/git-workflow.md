# Git workflow

## Branches

```
feature/SCP-001-vietqr-payload
fix/SCP-014-duplicate-confirmation
refactor/SCP-022-extract-pricing
chore/SCP-000-ai-dev-workflow
```

`<type>/<TASK-ID>-<short-description>`, cut from `main`, lowercase and
hyphenated after the id.

The task id is **load-bearing**, not decoration:

- `.claude/hooks/record-task-activity.mjs` reads it to find which task file to
  append a commit to. No id, no activity log.
- `.github/workflows/task-traceability.yml` rejects a pull request from a
  branch without one.

One task, one branch. Work that turns out to belong to a different task gets
its own branch and its own record.

## Commits

Conventional Commits, with the task id at the end of the subject:

```
feat(payment): generate VietQR payload for bank transfer [SCP-001]
fix(order): release stock reservation when payment expires [SCP-014]
test(cart): cover concurrent purchase of the last portion [SCP-009]
refactor(pricing): move total calculation out of the controller [SCP-022]
docs(api): document the bank transfer instructions schema [SCP-001]
chore(ci): add the traceability gate [SCP-000]
```

| Type       | For                                                                          |
| ---------- | ---------------------------------------------------------------------------- |
| `feat`     | New behaviour someone asked for                                              |
| `fix`      | A defect, with the test that catches it                                      |
| `refactor` | Same behaviour, better structure. If behaviour changed, it is not a refactor |
| `test`     | Tests alone                                                                  |
| `docs`     | Documentation, including `openapi.yaml` and specs                            |
| `chore`    | Build, CI, tooling, dependencies                                             |

Scope is the module from `CLAUDE.md` §5, lowercased: `payment`, `order`, `cart`,
`catalog`, `menu`, `inventory`, `identity`, `store`, `fulfillment`, plus `api`,
`web`, `ci`, `infra`, `docs`.

The subject says what, in the imperative. The body says **why** — the diff
already shows what, and the reason is the part that is lost in six months.

## Before every commit

1. **Read the diff.** `git diff --stat`, then the diff itself. Not a formality:
   this is where a stray file gets caught.
2. **Confirm every file belongs to this task.** Never `git add -A` without
   having looked. An agent must never sweep a human's unrelated
   work-in-progress into a task commit.
3. **Run the checks the change calls for.**

   ```bash
   pnpm api:check                                 # backend
   pnpm lint && pnpm typecheck && pnpm build      # frontend
   pnpm format:check
   pnpm openapi:conformance                       # if the API contract moved
   ```

4. **Check for secrets.** No `.env`, key, dump or credential. A hook blocks
   this, but the hook is the backstop, not the process.
5. **Commit atomically.** One logical change. Two unrelated fixes are two
   commits, even when they are three lines each.
6. **Check the SHA reached the task log.** The `PostToolUse` hook appends it;
   if the branch name has no task id, it will not have.

## Operations that are refused

`.claude/hooks/guard-protected-ops.mjs` blocks these with `exit 2`:

| Blocked                                                     | Why                                  |
| ----------------------------------------------------------- | ------------------------------------ |
| `git push --force`, `-f`, `--force-with-lease`              | Rewrites history others hold         |
| `git push` with a `+refspec`                                | A force push in disguise             |
| `git filter-branch`, `filter-repo`                          | Rewrites all history                 |
| `git rebase -i`, `--onto`                                   | Rewrites commits                     |
| `git reset --hard origin/...`                               | Discards local commits irrecoverably |
| `git push --delete`                                         | Not reversible from here             |
| `git merge` while on `main`/`master`/`production`/`release` | Merge approval is a human gate       |
| `gh pr merge`                                               | A reviewer merges, after CI is green |
| `git commit --no-verify`                                    | Defeats the checks                   |

Merging `main` **into** a feature branch is allowed — that is the safe
direction, and the guard checks where `HEAD` is rather than which branch name
appears in the command.

`pnpm claude:selftest` proves all of this, in both directions: 21 blocked
commands with the rule that catches each, and 20 real commands from this
repository that must not be blocked.

## Pull requests

Opened by `/pr`, only when the task is `READY_FOR_PR` with no unresolved
CRITICAL or HIGH finding. The body carries evidence, not claims: acceptance
criteria with their verification status, real test output, review counts by
severity, anything NOT VERIFIABLE stated plainly, and rollback notes for
anything touching migrations, payments or order state.

Nothing merges automatically. `DONE` is set after a human merges.
