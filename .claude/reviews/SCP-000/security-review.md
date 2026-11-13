# Security review — SCP-000

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-13 |
| **Reviewer** | `security-reviewer` agent |
| **Why reviewed** | infra config: CI workflow, enforcement hooks, agent tool grants |
| **Verdict** | CHANGES REQUIRED |

> Transcribed by the calling session from the agent's returned report, per
> `.claude/skills/review/SKILL.md`. The agent has no editing tool, by design.
> S1, S2, S3 and S5 were re-verified independently by the session before this
> file was written; see "Independent verification" at the end.

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 4 | yes |
| MEDIUM | 7 | no |
| LOW | 3 | no |

All six requested areas were covered; nothing was left partway. Paths below are relative to the repository root. Findings were confirmed by executing the shipped code — importing `scanDiff` and `evaluate` directly, and driving `guard-secrets.mjs` end to end as a hook in a throwaway git repository — not by reading patterns alone.

The diff adds no application code, no endpoint, no migration and no dependency, so nothing here is reachable by a customer and no money or customer data moves. Every finding is about the enforcement this change installs and the claims made for it. Two matter most because the claims are stronger than the code: `docs/workflow/quality-gates.md` records "Credential in a commit → ✅ hard block", and five agent definitions say they "cannot edit files". Neither is true as shipped, and a control people believe in is worse than one they don't.

## Findings

### S1 — `guard-secrets` sees an empty index for `git add -A && git commit`, the one idiom its own header says it was fixed for

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:182-192` |
| **Class** | secret exposure |
| **Severity** | HIGH |

**Who the attacker is.** No remote attacker. This is the last line of defence failing in the normal flow — an agent or developer committing a file that contains a real credential.

**What they send.** A single Bash call: `git add -A && git commit -m "chore: wip"` with the credential in a file that is not yet staged. `PreToolUse` fires *before* the shell runs, so `git add` has not happened. The command matches `/\bgit\s+commit\b/`, so the hook runs; `stagesEverything` is false (`-A` contains no lowercase `a`, and the regex has no `i` flag), so the hook scans `git diff --cached`, which at that instant does not contain the file.

**What they get.** Verified in a throwaway repo: with `gh_token=ghp_0123…AB` in an unstaged file, the hook exits **0** and the commit proceeds. The same token staged in a previous call gives exit **2** and the block message. Whether the guard works depends on whether staging and committing were split across two Bash calls. A credential committed this way stays in every clone and fork permanently.

**What to change.** Two parts. (1) When the command contains a staging step (`git add`, `git stage`, `-A`, `-a`, `--all`), scan the working tree, not the index: `git diff HEAD` plus the contents of `git ls-files --others --exclude-standard`. (2) The durable fix is a real `pre-commit` git hook that runs at the moment the index is final — that also covers `git commit` typed in a human terminal, run from a script, or issued by any tool other than `Bash`, none of which this hook can ever see. Keep the Claude hook as the second layer, not the only one.

### S2 — the content scan matches almost none of the credential names this repository actually uses, and the placeholder waiver is a substring test

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:73-103` (`ASSIGNMENT`, `PLACEHOLDERS`) |
| **Class** | secret exposure |
| **Severity** | HIGH |

**Who the attacker is.** Again the control failing, not an intruder: an agent pasting real values from `.env` into a file that is not gitignored while debugging — `infra/compose.prod.yaml`, a doc under `docs/`, a CI workflow, a `config/*.php` fallback.

**What they send.** Any assignment whose key is snake_case-prefixed. `ASSIGNMENT` requires `\b` immediately before `password|secret|api_key|token|…`, and `_` is a word character, so there is no boundary in `FOO_PASSWORD`. Verified with `scanDiff` — every one of these is **ALLOWED**:

```
MAIL_PASSWORD=Hx9kLm2Qp7vRtZw4          REDIS_PASSWORD=Hx9kLm2Qp7vRtZw4
POSTGRES_PASSWORD=Hx9kLm2Qp7vRtZw4      MINIO_ROOT_PASSWORD=Hx9kLm2Qp7vRtZw4
GRAFANA_ADMIN_PASSWORD=Hx9kLm2Qp7vRtZw4 MIGRATION_DB_PASSWORD=Hx9kLm2Qp7vRtZw4
APP_SECRET=… JWT_SECRET=… VIETQR_API_KEY=… BANK_CLIENT_SECRET=…
MAIL_PASSWORD: Hx9kLm2Qp7vRtZw4         (YAML form, same reason)
```

`POSTGRES_PASSWORD`, `MIGRATION_DB_PASSWORD`, `REDIS_PASSWORD`, `GRAFANA_ADMIN_PASSWORD`, `AWS_SECRET_ACCESS_KEY` and `APP_KEY` are the real variable names in this project's `.env.example`. The scan catches exactly one of the project's own password variables — `DB_PASSWORD`, present as a hand-added special case.

The `PLACEHOLDERS` half compounds it. `looksLikePlaceholder` passes when the value merely *contains* a marker, and the markers include `secret`, `password`, `test`, `none`, `null`, `change`. Verified allowed: `DB_PASSWORD=Secret!Prod#2026aQ`, `DB_PASSWORD=Password#Prod2026!`, `DB_PASSWORD=Hx9kLm2Qp7vtestRtZw4`, `DB_PASSWORD=Hx9kLm2QnoneRtZw4`, `DB_PASSWORD=exchange-2026-Ks93ja`, `'client_secret' => 'gKt82mSecretQ91x'`. A credential is waved through for containing the word for what it is. And no `CREDENTIAL_SHAPES` rule covers what this stack leaks in practice: `APP_KEY=base64:…`, `postgres://user:pass@host`, a 40-char `AWS_SECRET_ACCESS_KEY`, a Sentry DSN, or a generic high-entropy blob.

**What they get.** The production database password, the object-store keys or the app signing key published in git history, with the guard reporting nothing. `.gitignore` catches files literally named `.env*`; it does not catch a credential pasted into a tracked YAML, PHP or Markdown file, which is the realistic path.

**What to change.** Match the key as a suffix (`[A-Za-z0-9_-]*(password|passwd|secret|token|api[_-]?key|key)`), and bound the false-positive risk on the value side instead: require the placeholder marker to match the whole value or anchor at its start, drop `test`/`secret`/`password`/`none`/`null` from the list, and whitelist this repository's actual placeholders by exact value (the `phpunit.xml` APP_KEY, `scp_test`, `change_me_in_local`, `${…}`, `env(…)`). Add shapes for `base64:` keys, URL userinfo and 40-char AWS secrets. Extend `FORBIDDEN_PATHS` to any `.env.*` other than `.env.example` (`.env.backup`, `.env.testing`, `.env.docker`, `.env.ci` all pass today). Add every bypass above to `selftest.mjs` as a must-block case.

### S3 — nothing stops a direct push to `main`; the guard blocks the two merge routes and leaves the simplest one open

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:57-176` (`RULES`) |
| **Class** | broken change control |
| **Severity** | HIGH |

**Who the attacker is.** An agent with `Bash` on a task branch — including one asked to "get this live", or one acting on instructions it read in a file.

**What they send.** `git push origin HEAD:main`. Verified with `evaluate()`: **ALLOWED**, as are `git push origin main`, `git push origin feature/x:main` and a bare `git push` while HEAD is on `main`. The ruleset blocks `gh pr merge`, `gh api …/pulls/N/merge` and `git checkout main && git merge …` — every indirect route to main except the one-liner that goes straight there.

**What they get.** Unreviewed code on `main` with no pull request, so `task-traceability.yml` (a `pull_request` trigger) never runs and no reviewer sees the diff — the outcome `merge-into-protected` and `auto-merge-pr` exist to prevent. This is not the recorded AC10 item: branch protection would stop it server-side, but the omission is in the local ruleset this diff owns, and the enforcement table in `docs/workflow/quality-gates.md` reads as if the merge path were covered.

**What to change.** Add a rule for `git push` whose refspec target is `main`/`master`/`production`/`release`, or any `git push` with no explicit refspec while `currentBranch()` is protected. Note that `currentBranch()` returns `''` on failure and `'HEAD'` when detached, and `merge-into-protected` then falls through — verified `git merge feature/x` is ALLOWED with `branch=''` or `branch='HEAD'`. Treat an unknown branch as protected rather than as safe.

### S4 — "review agents cannot edit files" is false: `Bash` writes, and the CI gate only checks that the evidence file exists

| | |
| --- | --- |
| **Where** | `.claude/agents/{code-reviewer,security-reviewer,performance-reviewer,qa-engineer,git-release}.md` frontmatter; `.claude/settings.json`; `infra/scripts/check-task-traceability.mjs:94-156` |
| **Class** | separation of duties / integrity of the evidence trail |
| **Severity** | HIGH |

**Who the attacker is.** An agent optimising for a green result, or an agent acting on injected text in material it is told to read — a task record, a diff, a vendored file, a PR body. Reviewers read exactly the attacker-reachable surface.

**What they send.** Any Bash call that writes: `printf '…' > file`, `sed -i`, `tee`, `node -e "require('fs').writeFileSync(…)"`, `git apply`, `git checkout -- file`. All five agents are granted `tools: Read, Glob, Grep, Bash`; `.claude/settings.json` has no `permissions` block; `guard-protected-ops` has no rule for writing anything except `.env`, and misses the path-qualified form of even that (S7).

**What they get.** The `qa-engineer` can edit the assertion it is judging, which its own description says is impossible. A reviewer can write `.claude/reviews/<ID>/code-review.md` and `security-review.md` saying SAFE TO SHIP, and set `.claude/tasks/<ID>.md` to `Status: READY_FOR_PR` with `CRITICAL | 0` and `HIGH | 0`. That is precisely and only what `check-task-traceability.mjs` verifies: the files exist, the status parses, the counts read `0`. Both the separation of duties and the merge gate collapse to self-attestation by the same process that did the work.

**What the claim is worth, and what would make it true.** As written it is worth nothing mechanically — it is a statement of intent that holds while the agent cooperates. It can only be made true by the tool grant or the sandbox, not by prose. (1) Drop `Bash` from `code-reviewer`, `security-reviewer` and `performance-reviewer`; materialise the diff to a file in the calling session and let them `Read` it. (2) `qa-engineer` genuinely must run tests — run it against a worktree mounted read-only with a writable temp dir, which is the only form that actually holds; a `permissions.deny` list of write-shaped commands is not equivalent, because a shell has unbounded spellings. (3) Make the evidence non-self-attested: have CI run the suite and derive pass/fail itself rather than checking that a report file exists, and require the review artefacts to be added by a different actor (CODEOWNERS on `.claude/reviews/**`, or a signed commit). (4) If the `PreToolUse` payload identifies the calling subagent, a hook could enforce this — the agent did not verify that it does, so do not rely on it. Until one of these lands, delete "Read-only", "cannot edit files" and "cannot approve its own work" from the five descriptions: they are the part a human would trust.

### S5 — `git -C` or `git -c` before `commit` skips the secret scan entirely

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:182` |
| **Class** | secret exposure |
| **Severity** | MEDIUM |

**Who the attacker is.** An agent using an ordinary git idiom; also anyone deliberately bypassing.

**What they send.** `git -C . commit -m "chore: wip"` or `git -c user.name=x commit -m wip`. The filter is `/\bgit\s+commit\b/`, which requires `commit` to follow `git` immediately.

**What they get.** Verified: with the `ghp_…` token **staged**, both forms exit **0** where plain `git commit` exits 2. `guard-protected-ops` already learned this lesson for one case (`bypass-hooks-path` covers `git -c core.hooksPath=`); the secrets guard did not.

**What to change.** `/\bgit\b[^\n]*\bcommit\b/`, and add both spellings to `selftest.mjs`. The `pre-commit` hook in S1 removes the whole class, because it does not depend on recognising the command.

### S6 — every error path in both guards fails open, including conditions a normal commit can reach

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:151-162,173-179`; `.claude/hooks/guard-protected-ops.mjs:204-211`; `.claude/settings.json:10,20` |
| **Class** | enforcement bypass |
| **Severity** | MEDIUM |

**Who the attacker is.** Nobody needs to attack; the conditions occur on their own.

**What they send.** A commit whose staged diff exceeds `maxBuffer: 32 * 1024 * 1024` — a vendored asset, a lockfile regeneration, a large fixture. `git()` catches the `ENOBUFS` throw and returns `''`, so the content scan runs over an empty string and reports nothing (`names` is a separate, small call, so only the path rules survive). Same shape for a `git` failure, unparseable stdin (verified: empty stdin → exit 0), and `CLAUDE_PROJECT_DIR` being unset, which turns the registered command into `node "/.claude/hooks/…"` — a non-2 exit, which does not block.

**What they get.** A credential committed alongside a large diff, with the guard silently satisfied.

**What to change.** Distinguish "clean" from "could not tell", exactly as `check-task-traceability.mjs:160-183` already does for `origin/<base>`: on any error, exit 2 saying the scan could not run. Raise or remove `maxBuffer` by streaming, and assert `CLAUDE_PROJECT_DIR` is set. A hook that times out (`timeout: 30`) is cancelled rather than converted into exit 2 — the agent could not verify that in this harness; check it against your Claude Code version.

### S7 — irreversible operations outside the ruleset, judged against the accident bar it claims

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:57-176` |
| **Class** | destructive-operation control |
| **Severity** | MEDIUM |

**Who the attacker is.** An agent cleaning up, resetting a local environment or "starting fresh" — the accident the file says it exists to price. Not judged against a determined bypass; `git pu""sh --force` getting through is accepted and listed under Not defects.

**What they send.** Verified **ALLOWED** by `evaluate()`:

- `git reset --hard HEAD~3`, `git reset --hard`, `git clean -fdx`, `git checkout -- .`, `git stash clear`, `git branch -D` — irreversible loss of uncommitted and unpushed work. `git clean -fdx` deletes the untracked real `.env`, the file three other rules exist to protect.
- `rm -rf <path>` in any form. Not covered at all.
- `docker compose exec postgres psql -U scp -c "DROP TABLE orders CASCADE"`, `dropdb scp`, `docker compose exec redis redis-cli FLUSHALL`, `artisan tinker --execute="DB::statement('DROP TABLE …')"` — the artisan verbs are blocked, the direct routes to the same outcome are not.
- `echo "APP_KEY=…" >> apps/api/.env` and `cp apps/api/.env.example apps/api/.env` — both `.env` rules require `.env` to sit immediately after the redirect or argument separator, so any path-qualified form passes. `apps/api/.env.example` exists, so `apps/api/.env` is a file an agent would plausibly write. (`cd apps/api && cp .env.example .env` *is* blocked, which shows the rule is position-dependent rather than target-dependent.)
- `php artisan migrate --force --database=production` — the production test looks only at `APP_ENV=`, `--env=` and `compose.prod`.
- Anything behind an indirection: `pnpm run <script>`, `bash infra/scripts/<x>.sh`, `make`. The guard sees the wrapper, not what it runs.

**What they get.** Local data loss, a dropped development database, or a clobbered credential file — none of it recoverable, all of it squarely in the accident class the ruleset advertises.

**What to change.** Add rules for `rm -rf`, `git clean -f`, `git reset --hard` without a remote, `git checkout -- .`, the `psql`/`dropdb`/`redis-cli` destructive verbs and `--database=production`. Make the `.env` rules match the *target path* (`(^|[\s'"=/])\.env(\s|$|;|'|")` anywhere in the command, any directory) instead of the position after the operator. Nothing can be done about wrapper scripts from a command-string matcher — say so in the header, since it is the largest hole in the accident bar and the one a reader will not guess.

### S8 — nothing mechanically restricts the agents that can edit the enforcement itself

| | |
| --- | --- |
| **Where** | `.claude/agents/laravel-developer.md`, `.claude/agents/nextjs-developer.md` (no `tools:` key); `.claude/agents/{task-manager,software-architect}.md` (`Write, Edit`); `.claude/settings.json` (no `permissions`) |
| **Class** | enforcement bypass |
| **Severity** | MEDIUM |

**Who the attacker is.** Any agent with `Edit`, including the two developer agents, which declare no `tools:` key at all and therefore inherit the full default tool set — the inverse of the care taken over the reviewers' (ineffective) lists.

**What they send.** An edit to `.claude/hooks/guard-secrets.mjs`, `.claude/settings.json`, `.github/workflows/task-traceability.yml` or `infra/scripts/check-task-traceability.mjs`. The hook scripts are ordinary repository files read from disk on every invocation, so weakening one takes effect on the next Bash call; `guard-protected-ops` has no rule covering writes to them, and the `Edit` tool never reaches the Bash guard anyway. `task-manager`'s "only `.claude/tasks/**`" and `software-architect`'s "never `apps/`, `packages/`, `infra/`" are prose, not permissions.

**What they get.** The guards silently off, with only human diff review to notice.

**What to change.** Give the developer agents explicit `tools:` lists. Add a committed `permissions.deny` for `Edit(.claude/hooks/**)`, `Edit(.claude/settings.json)`, `Edit(.github/workflows/**)` and `Edit(infra/scripts/check-task-traceability.mjs)`, and a `CODEOWNERS` entry so GitHub requires a named human to approve changes to those paths.

### S9 — the traceability gate is modifiable by the pull request it gates

| | |
| --- | --- |
| **Where** | `.github/workflows/task-traceability.yml:17-51`; `infra/scripts/check-task-traceability.mjs` |
| **Class** | CI gate bypass |
| **Severity** | MEDIUM |

**Who the attacker is.** Anyone who can push the branch, or an agent working on it.

**What they send.** A commit in the same branch that changes `process.exit(1)` to `process.exit(0)` in `check-task-traceability.mjs`, or deletes `.github/workflows/task-traceability.yml`, or weakens `SECURITY_TRIGGERS`. `pull_request` runs the workflow and the script from the head ref.

**What they get.** A green "Task traceability" check that proves nothing. The required evidence is likewise authored in the same PR (S4). This is adjacent to the recorded AC10 gap but not the same item: it would survive branch protection unless the check is marked *required* and ownership of these paths is pinned.

**What to change.** `CODEOWNERS` for `.github/**`, `infra/scripts/**` and `.claude/**`; mark the check required with "require branches to be up to date"; consider running the gate from a `workflow_run` or reusable workflow pinned to `main@<sha>` so the code that judges the PR is not supplied by the PR.

### S10 — the security-review trigger list misses store isolation, authorization and migrations

| | |
| --- | --- |
| **Where** | `infra/scripts/check-task-traceability.mjs:169-177` |
| **Class** | gate coverage |
| **Severity** | MEDIUM |

**Who the attacker is.** Not an attacker — a change that reaches a PR with no security review required, and therefore none done.

**What they send.** A PR that touches store-context resolution middleware, a `Policy`/`Gate`, an admin list endpoint under `Modules/Store` or `Modules/Order`, a guest-order token, an idempotency key store, `routes/api.php`, a migration, or a GitHub Actions workflow. None of those paths match `Modules/Payment/`, `Modules/Identity/`, `store_bank_account`, `/payment/i`, `/auth/i`, `.env.example` or `infra/(nginx|compose)`.

**What they get.** The gate passes without `security-review.md`, for the two risk classes `CLAUDE.md` §8 ranks beside payments: store isolation and broken object authorization.

**What to change.** Add `Modules/(Store|Order|Cart|Fulfillment)/`, `Policies?/`, `Middleware/`, `routes/`, `database/migrations/`, and the words `policy`, `gate`, `token`, `guest`, `idempotenc`, `store_id`, plus `.github/workflows/`. Keep the trigger list and `.claude/skills/review/SKILL.md`'s table in step; they disagree today.

### S11 — `guard-secrets` refuses to commit its own test file, and `.npmrc`, which is tracked

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:36-58`; `.claude/hooks/selftest.mjs:150-179`; tracked `.npmrc` |
| **Class** | control degradation |
| **Severity** | MEDIUM |

**Who the attacker is.** None. This is the failure mode the file's own header names: "a developer who learns to work around the guard … and the guard has lost all of its value."

**What they send.** Any commit touching `.claude/hooks/selftest.mjs`. Running `scanDiff` over this diff with the project's own scanner returns five findings, all in that one file:

```
.claude/hooks/selftest.mjs  -  looks like a private key block
.claude/hooks/selftest.mjs  -  looks like a GitHub token
.claude/hooks/selftest.mjs  -  looks like a AWS access key id
.claude/hooks/selftest.mjs  -  DB_PASSWORD is assigned a value that does not look like a placeholder
.claude/hooks/selftest.mjs  -  client_secret is assigned a value that does not look like a placeholder
```

All five are fixtures (GitHub's and AWS's documented example values, plus invented passwords) — no live credential is in this diff. Separately, `FORBIDDEN_PATHS` flags any `.npmrc`, and `.npmrc` is tracked in this repository (`git ls-files` confirms) and holds no credential.

**What they get.** The file that proves the guard works cannot be committed through the guard, and the block message instructs the developer to widen the pattern — the suggested remedy degrades the control. The same applies to every future `.npmrc` change.

**What to change.** Exempt `.claude/hooks/selftest.mjs` by path (keeping the fixtures where they are deliberately inert), or move the fixtures into an exempt data file. Narrow the `.npmrc` rule to added lines containing `_auth`, `_authToken` or `_password` rather than the filename.

### S12 — the untrusted pull-request title is echoed into the Actions log, where `::` is a command prefix

| | |
| --- | --- |
| **Where** | `infra/scripts/check-task-traceability.mjs:67,203-205` |
| **Class** | log injection — **suspicion, precondition unverified** |
| **Severity** | LOW |

**Who the attacker is.** Anyone who can open or edit a pull request, including from a fork (`types: [edited]` re-runs the check).

**What they send.** A title beginning with a newline followed by `::error::` / `::add-mask::` / `::stop-commands::`. Both places the title is printed prefix it with text (`pr title …`, `  [pr title] "…"`), so a workflow command can only be parsed if the title can carry a line break.

**What they get.** At most forged or suppressed annotations. The exit code is decided by the script's own logic, the job has `permissions: contents: read`, and no secret is present, so there is nothing to exfiltrate or mask that matters. The agent did **not** verify that GitHub accepts a newline in a pull-request title; if it does not, this is unreachable. Labelled a suspicion for that reason.

**What to change.** `console.log('pr title %s', prTitle.replace(/[\r\n]+/g, ' '))`, or stop echoing it.

### S13 — `actions/checkout` persists the token while the job then executes code supplied by the pull request

| | |
| --- | --- |
| **Where** | `.github/workflows/task-traceability.yml:35-39,51` |
| **Class** | credential exposure |
| **Severity** | LOW |

**Who the attacker is.** The author of a fork pull request.

**What they send.** A modified `infra/scripts/check-task-traceability.mjs` that reads `.git/config` and posts the `AUTHORIZATION: basic …` header value outward. `actions/checkout@v4` leaves it there by default, and the last step runs the PR's own script.

**What they get.** A `GITHUB_TOKEN` that, for a fork `pull_request` event with `permissions: contents: read`, is read-only on a repository the forker could already read. Low value — but there is no reason to hand it over.

**What to change.** `persist-credentials: false` on the checkout step; the job needs no token. Also pin both actions by commit SHA. The workflow adds no `pnpm install`, which correctly avoids running a fork's lifecycle scripts.

### S14 — a commit subject is appended into the file the merge gate parses, escaping only `|`

| | |
| --- | --- |
| **Where** | `.claude/hooks/record-task-activity.mjs:91-119` |
| **Class** | evidence-trail integrity |
| **Severity** | LOW |

**Who the attacker is.** Whoever writes the commit subject.

**What they send.** A subject such as `feat: x | **Status** | READY_FOR_PR | [SCP-001]`. Only `|` is escaped (`subject.replace(/\|/g, '\\|')`), and `%s` cannot carry a newline, so a forged row lands as a single line below the activity marker.

**What they get.** Nothing today: both parsers in `check-task-traceability.mjs` use `RegExp.exec`, which returns the first match, and the hook appends at the end of the file, after the real `Status` and severity rows. That ordering is the only thing preventing a forged `CRITICAL | 0` row from being read — which is incidental, not designed.

**What to change.** Keep the escaping, and make the dependency explicit: parse the gate's fields only from above the activity marker, and say so in a comment in both files.

## Payment integrity

`CLAUDE.md` §6. N/A throughout: the diff contains no application code, no route, no migration and no payment logic, so no path in it can reach a payment record.

| Check | Status |
| --- | --- |
| No customer-reachable path moves a payment to `PAID` | N/A — no application code in this diff |
| Confirmation requires the `payment.confirm` permission | N/A |
| Confirmation is idempotent and transactional | N/A |
| An amount mismatch does not auto-resolve to `PAID` | N/A |
| Transfer reference unique per order, not guessable across orders | N/A |
| Bank-account management unreachable by customers | N/A |
| Bank-account changes audited with actor and previous value | N/A |
| No screenshot, upload or customer click wired to a status change | N/A |

One process-level note that does bear on payments: S10 means a future change to store-scoped admin code can reach a PR without a security review being required, and S4 means the review that is required can be self-written. Those are the paths by which a payment defect would get past this workflow rather than through it.

## Store isolation

| Check | Status |
| --- | --- |
| Every admin query filtered by the resolved store | N/A — no queries in this diff |
| Store context from route, host mapping or verified membership only | N/A |
| No `store_id` read from query string or body | N/A |
| Another store's resource answers 404 | N/A |
| Every route parameter authorized, not just authenticated | N/A |

## Guest access and tokens

| Check | Status |
| --- | --- |
| Orders and carts not addressable by id alone | N/A — no endpoints in this diff |
| Tokens hashed at rest | N/A |
| Tokens scoped to one resource and expiring | N/A |
| Idempotency key cannot read back another caller's response | N/A |

## Secrets and logging

| Check | Status |
| --- | --- |
| No hardcoded credential in the diff | PASS — the five hits from the project's own scanner are all in `.claude/hooks/selftest.mjs` and are documented example values (`gho_16C7…`, `AKIAIOSFODNN7EXAMPLE`) plus invented passwords. See S11 |
| `.env` not in the diff | PASS — 41 files, none is `.env` or `.env.*`; `.env.example` is untouched |
| No credential, payment payload or PII written to a log | PARTIAL — `guard-protected-ops.mjs:217-222` echoes `Command: ${command}` on a block. For the `write-dotenv` rules the blocked command contains, by definition, the credential being written, so the value is reproduced in the session transcript and in anything that captures hook stderr. It is already in the transcript as the model's own output, so the marginal exposure is small; print the matched rule and the target path, not the whole command |
| No internal detail in an error response | PASS — no HTTP surface. `check-task-traceability.mjs` prints only paths and its own messages |

## Dependencies

Not run, and not required: the diff changes no manifest and no lockfile. `package.json` gains two script entries (`claude:selftest`, `claude:traceability`) and no dependency; `pnpm-lock.yaml`, `apps/api/composer.json` and `composer.lock` are untouched. `pnpm audit` and `composer audit` were not executed, so do not read this section as a statement that the dependency tree is clean — only that this change does not alter it.

## Not defects

- **Area 3, the workflow trigger.** `pull_request`, not `pull_request_target`; `permissions: contents: read`; and the attacker-controlled title reaches the script through `env: PR_TITLE` rather than `${{ }}` inside `run:`, with a comment saying exactly why. No script injection from a fork. The `concurrency` group interpolates `github.event.pull_request.number`, an integer, in a YAML key rather than a shell. This is the part of the change that is right.
- **Area 4, branch-controlled strings reaching a shell.** They do not. Both Node scripts use `execFileSync('git', [...])` with an argv array and no `shell: true`, so branch names, base refs, task ids and PR titles never reach a shell. Nor can an argument be mistaken for an option: `baseRef` is always embedded as `origin/${baseRef}`, which cannot begin with `-`. `PR_TITLE` is only compared with `includes()` and printed.
- **Path traversal from a branch name.** `taskId` comes from `BRANCH_PATTERN`, anchored and limited to `[A-Z][A-Z0-9]+-\d+`, before `join('.claude','tasks', …)`. The `--task` flag can escape the directory, but it is supplied by the person running the command on their own machine and only affects `existsSync`/`readFileSync` of a `.md` path whose contents are never printed — not an attack.
- **Area 5, hook registration.** Both hooks are registered on `Bash` as separate matcher entries and both run; the `${CLAUDE_PROJECT_DIR}` reference is quoted, expanded by Claude Code rather than a shell, and its value is the project directory, not input. Correct as written; the only issue is the unset case, folded into S6. The filter-in-the-script choice (rather than an `if:` prefix pattern) is right, and the header's reasoning for it is sound — S1 and S5 are about where the scan looks, not about this decision.
- **Deliberate bypasses of `guard-protected-ops`.** `git pu""sh --force`, base64-encoded commands, a wrapper script. The header states the bar is accidents, not intent, and these are above it. S7 lists only the shapes that fall below that bar.
- **`$ARGUMENTS` interpolated into `gh pr create` in `.claude/skills/pr/SKILL.md`.** The value is the task id the operator typed in their own session; there is no second party.

## On the absence of a `permissions` block in `.claude/settings.json`

Half agreed, and the half that is wrong matters.

Agreed for `allow`: `.claude/settings.json` is committed and trusted by every clone, so a shipped allowlist would silently grant those permissions to every machine that checks the repository out, including a maintainer with a different threat model. Tool permissions are the operator's setting and a repository should not quietly widen them. Leaving `allow` out is the right call and the stated reason is sound.

Not agreed for `deny`: a committed `deny` list can only ever *remove* capability. It cannot widen an operator's settings, so the argument that justifies omitting `allow` does not reach it. Three deny rules would turn prose into enforcement at no cost to the operator — covering `.claude/hooks/**`, `.claude/settings.json` and `.github/workflows/**` (S8, S9). A `Write`/`Edit` deny for `.env` would match what `guard-protected-ops` already tries to do for Bash, since the `Edit` tool never passes through that hook at all. What a `deny` list cannot do is make S4 true — a shell has too many ways to write a file for an allow/deny list over command text to be a boundary. That one needs the tool grant or a read-only mount.

## Verdict and whether this can go to a pull request

**CHANGES REQUIRED. Yes, open the pull request — but not as a claim that the workflow is enforced.**

Nothing in this diff is exploitable by a customer, moves money, crosses a store boundary or exposes customer data; it adds no application code, no endpoint, no migration and no dependency. Merging it does not create a live vulnerability, and the workflow it installs is a clear improvement on nothing.

What must not merge unchanged is the documentation, because the two claims a human will rely on are false as shipped:

1. `docs/workflow/quality-gates.md` — "Credential in a commit | `guard-secrets` scans the staged diff | ✅ **hard block**". S1 and S2 reduce this to a best-effort scan that misses the repository's own credential variable names and the commit idiom its own header was rewritten for. Either fix S1 and S2 in this PR, or downgrade the row to ⚠️ partial with the gaps named, in the same honest register the rest of that table already uses for AC10.
2. The five agent descriptions — "Read-only", "cannot edit files", "cannot approve its own work". S4. `Bash` writes files. Either remove `Bash` from the three pure reviewers in this PR, or delete the claim.

Fix S1, S2 and S3 before anyone treats the hooks as a control; they are small, local, and each has a test case waiting in `selftest.mjs`. S4 is the finding with the longest tail, because the whole workflow's value rests on review being independent, and today it is independent only by the goodwill of the reviewing agent.

## Independent verification by the calling session

The session re-ran the four most consequential claims against the shipped code
before saving this report, rather than taking them on trust. All four hold.

| Claim | How it was checked | Result |
| --- | --- | --- |
| S2 — project credential names pass | `scanDiff` imported directly | `MAIL_PASSWORD`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `AWS_SECRET_ACCESS_KEY` all **allowed**; only `DB_PASSWORD` blocked |
| S2 — placeholder substring waiver | same | `DB_PASSWORD=Secret!Prod#2026aQ`, `=Password#Prod2026!`, `=Hx9kLm2Qp7vtestRtZw4` all **allowed** |
| S3 — direct push to main | `evaluate()` imported directly | `git push origin HEAD:main`, `git push origin main`, `git push` on `main`, and `git merge feature/x` with `branch=''` all **allowed** |
| S1, S5 — which commits are scanned, and how | both regexes read out of `guard-secrets.mjs` at runtime and applied | `git add -A && git commit` → scans `--cached`; `git -C . commit` and `git -c user.name=x commit` → hook **skipped** entirely |

S1 bears directly on the fix round recorded in `.claude/tasks/SCP-000.md`. That
round removed the `if: "Bash(git commit *)"` pre-filter so the hook would *run*
on `git add -A && git commit`. It does now run — and then scans an index that,
at `PreToolUse` time, does not yet contain the file. The fix was necessary and
insufficient, and the record overstated what it closed.
