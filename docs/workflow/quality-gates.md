# Quality gates

What actually stops bad work from shipping, and — the part that matters — what
only looks like it does.

## Enforcement, honestly

| Gate                                          | Mechanism                                                    | Enforced?                                                   |
| --------------------------------------------- | ------------------------------------------------------------ | ----------------------------------------------------------- |
| Design before code                            | `/implement` refuses a task that is not `APPROVED`           | ✅ skill checks the record                                  |
| Force push, history rewrite                   | `guard-protected-ops` → `exit 2`                             | ✅ hard block                                               |
| `migrate:fresh`, `migrate:refresh`, `db:wipe` | `guard-protected-ops`                                        | ✅ hard block                                               |
| Production migration or stack operation       | `guard-protected-ops`                                        | ✅ hard block                                               |
| `docker compose down -v`, volume removal      | `guard-protected-ops`                                        | ✅ hard block                                               |
| Writing `.env` from a script                  | `guard-protected-ops`                                        | ✅ hard block                                               |
| Credential in a commit                        | `guard-secrets` scans what the commit will include           | ⚠️ partial — see "What the secret scan does not catch"      |
| `gh pr merge` from an agent                   | `guard-protected-ops`                                        | ✅ hard block                                               |
| Style, static analysis, tests                 | `ci.yml` jobs `php-static`, `php-tests`, `web`               | ✅ CI, required                                             |
| API contract matches the code                 | `ci.yml` job `contract`                                      | ✅ CI                                                       |
| Task traceability on a PR                     | `task-traceability.yml`                                      | ✅ CI, once the PR exists                                   |
| No unresolved CRITICAL/HIGH at PR time        | `/pr` refuses, and `task-traceability.yml` checks the report | ✅ both                                                     |
| **Merge approval**                            | GitHub branch protection                                     | ❌ **not enabled — see below**                              |
| Production deploy                             | hook blocks the compose command                              | ⚠️ partial: the real deploy happens outside this repository |
| Review agent correctness                      | nothing                                                      | ❌ an agent's opinion is not proof                          |
| Review independence                           | the reviewing agent has no `Write`/`Edit`                    | ⚠️ partial — it holds `Bash`, which writes. See below       |

## What the secret scan does not catch

`guard-secrets` was recorded here as a hard block until the SCP-000 review
showed it was not one. The round that followed fixed what could be fixed, and
this section exists so the row above stays honest about the rest.

Fixed: the scan now reads the working tree, not the index, whenever the command
will stage files — `git add -A && git commit` used to be scanned before `git
add` had run, against an index that was still empty. `git -C` and `git -c`
before `commit` no longer skip it. Credential variable names are matched with
their prefixes, so `MAIL_PASSWORD` and `AWS_SECRET_ACCESS_KEY` are seen, not
only the one hand-added `DB_PASSWORD`. A value is excused as a placeholder only
when a marker is the whole value or starts it, so `Secret!Prod#2026aQ` is no
longer waved through for containing the word "secret".

Still true, and the reason this row is ⚠️ rather than ✅:

- It only sees commands issued through the `Bash` tool. A commit typed in a
  terminal, run from a script, or made by any other tool is invisible to it.
  The durable fix is a real `pre-commit` git hook, which runs when the index is
  final whatever started the commit. That is not installed yet.
- Every error path fails open. A diff larger than the subprocess buffer, a
  failed `git` call, or an unset `CLAUDE_PROJECT_DIR` all end in "no findings"
  rather than "could not tell".
- Pattern matching finds shapes it knows. A credential in a form nobody
  anticipated gets through, and no list of shapes closes that.

Treat it as a second layer that catches ordinary mistakes, not as the thing
standing between a credential and a public repository.

### The gap that matters

`main` has **no branch protection**:

```
$ gh api repos/nhatdvit/smart-commerce-platform/branches/main/protection
Branch not protected (HTTP 404)
```

So the merge gate is a convention. A local hook cannot stop a merge performed
in the GitHub UI, and nothing requires CI to be green before merging. Until
someone with admin rights configures it, "merge approval" in the workflow
documentation describes an intention, not a control.

What to enable, in repository Settings → Branches, a rule for `main`:

- Require a pull request before merging, with at least one approval
- Require status checks to pass: `CI` (the `ci-ok` job) and
  `Task traceability` — mark them required, not merely present
- Require branches to be up to date before merging
- Block force pushes and deletions
- Apply to administrators, or the rule protects everyone except the person most
  able to break it

This is deliberately left to a human. Changing a repository's security settings
is not something an automated workflow should do to itself.

## Backend

| Check                                    | Command                      | In CI        |
| ---------------------------------------- | ---------------------------- | ------------ |
| Code style                               | `pnpm api:pint:check`        | `php-static` |
| Static analysis, level 8                 | `pnpm api:stan`              | `php-static` |
| Unit, feature and integration tests      | `pnpm api:test`              | `php-tests`  |
| Migrations apply, roll back and re-apply | —                            | `php-tests`  |
| Generated OpenAPI is current             | `pnpm api:openapi` then diff | `php-tests`  |
| Code conforms to the designed contract   | `pnpm openapi:conformance`   | `contract`   |

Tests run against real PostgreSQL. SQLite is not an option: row locking,
partial indexes, CHECK constraints and `TIMESTAMPTZ` all behave differently,
and those are exactly the behaviours the payment and stock invariants rest on.

## Frontend

| Check            | Command             | In CI |
| ---------------- | ------------------- | ----- |
| Formatting       | `pnpm format:check` | `web` |
| Lint             | `pnpm lint`         | `web` |
| Types            | `pnpm typecheck`    | `web` |
| Production build | `pnpm build`        | `web` |

## Database

Checked by review, not by a tool, because no tool decides them:

- Migration safe on a table that already has rows
- Foreign keys, unique constraints and CHECK constraints that make an invariant
  impossible rather than unlikely
- Indexes for the queries actually added, and no index nothing queries
- Transaction boundaries wide enough to be correct, no wider
- Concurrency and idempotency covered by a test that really races, not by a
  reading of the code

## Security

| Check                    | How                                         | In CI      |
| ------------------------ | ------------------------------------------- | ---------- |
| Dependency advisories    | `composer audit`, `pnpm audit`              | `security` |
| Lockfile current         | —                                           | `security` |
| No credential committed  | `guard-secrets` hook                        | local      |
| Authorization boundaries | `security-reviewer` + the store-scope tests | review     |

## The workflow's own tests

```bash
pnpm claude:selftest
```

Assertions over both guards: that the destructive commands are blocked with
the right rule, that real commands from this repository are not, that
credentials are caught, and that this repository's own placeholders — the test
`APP_KEY`, the CI password, `.env.example`, compose interpolation — do not trip
the secret scanner.

The second half matters as much as the first. A guard with false positives gets
worked around, and a guard that is worked around protects nothing.

## What no gate can do

None of this makes a review agent right. `.claude/reviews/` records what an
agent thought on a given day; a test that ran and a CI run that passed are the
evidence. When a record and reality disagree, reality wins — which is why
`/status` re-checks the cheap facts against git and `gh` instead of quoting the
ledger back.
