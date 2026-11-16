# Security review — SCP-000 (second pass)

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-16 |
| **Reviewer** | `security-reviewer` agent (second pass, fix rounds 2 and 3) |
| **Why reviewed** | infra config: enforcement hooks, CI merge gate, agent tool grants |
| **Verdict** | CHANGES REQUIRED |

> **How this pass was conducted, and by whom.** This pass had `Read`, `Glob` and
> `Grep` and **no `Bash`** — the reviewer's own S4, acted on. Findings were
> derived by reading the shipped source against the repository's current files.
> Six probes were then run **by the calling session, not by the reviewer**
> (probes 1, 2, 3, 4, 6, 7); probe 5 was skipped — Windows, no `mkfifo`. Each
> finding records whether it is measured or read. The output the reviewer saw
> was sent by the session that also wrote the code under review — an interested
> party, which is the price of the S4 fix and is stated in `review/SKILL.md`.
>
> Credential-shaped examples are written with the value elided rather than
> literal, because a literal one makes this file uncommittable through the guard
> it reviews. That substitution is itself evidence for S15, and probe 1
> confirmed it: the previous review report scored three findings and the
> previous code review one.

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 3 | yes |
| MEDIUM | 5 | no |
| LOW | 3 | no |

Numbering continues from the first report (S1–S14). S6, S8, S9, S10, M2, M3, the LOW list, AC8 and AC10 are recorded open and are not re-reported.

The round did real work, and the measurements confirm it: `pnpm claude:selftest` → **117 passed, 0 failed** (run by the calling session, 2026-11-16; the code reviewer's L1 notes one duplicated clean fixture, so 116 distinct). S1's index/working-tree defect, S2's word-boundary defect, S5, M1, M7, M10 and the `/code-review` fiction are genuinely fixed, and the documentation that previously claimed a hard block and non-editing agents now mostly tells the truth. Three things did not land, all three now confirmed by probe rather than argued: **the S2 fix over-fires on 24 of this repository's own files (S15)**, **the S1 fix still has an unscanned commit idiom (S16)**, and **the S3 fix still leaves four open roads to `main` (S17)**. Nothing in the diff touches application code, payments, money or customer data.

## Findings

### S15 — the widened credential rule refuses 24 of this repository's own files, including the shared idempotency-key and guest-order-token constants

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:91` (`ASSIGNMENT`), `:124-147` |
| **Class** | control degradation → secret exposure |
| **Severity** | HIGH — held deliberately; the reasoning is at the end of this finding |
| **Verification** | **measured.** Probe 1: `scanDiff` over every tracked file with each line treated as added → **33 findings in 24 files, none of them a credential** |

**Who the attacker is.** Nobody. This is the failure the file's own header names: "a developer who learns to work around the guard … and the guard has lost all of its value." It is a regression introduced by the S2 fix in round 2: `key` and `token` joined the credential words with an arbitrary prefix chain, the value floor dropped from 8 to 5, and `secret`/`password`/`test` stopped excusing a value as a placeholder.

**What they send.** An ordinary commit. Probe 1's output, with the lines read directly:

| File and line | Line | Count |
| --- | --- | --- |
| `packages/api-contracts/src/headers.ts:4,6` | the exported `HEADER_IDEMPOTENCY_KEY` and `HEADER_GUEST_ORDER_TOKEN` constants | 2 |
| `apps/api/app/Shared/Infrastructure/Audit/SensitiveDataRedactor.php:76` | a `foreach` over a payload binding `$key` and `$value` | 1 |
| both `next.config.mjs:19-21` | a security-header entry binding `key` to `X-Content-Type-Options` | 1 each |
| `apps/api/config/sanctum.php:86` | the `validate_csrf_token` middleware entry | 1 |
| `apps/api/public/.htaccess:14` | the `RewriteRule` forwarding the XSRF token header | 1 |
| `apps/api/config/scramble.php:52` | a config array binding `key` to `scramble.openapi` | 1 |
| `apps/api/tests/Feature/IdempotencyTest.php:96` | a test array binding `key` | 2 |
| `apps/api/tests/Unit/SensitiveDataRedactorTest.php:17,61` | two redactor fixtures binding `$key` and `token` | 2 |
| `apps/api/tests/…/AuditTrailTest.php` | reported by probe 1; not present in the checkout the reviewer read, so no line claimed | 1 |
| `EnsureIdempotency.php:39`, `StorePermissionChecker.php:50`, `EnvironmentValidator.php:60` | three ordinary `$key = …` assignments | 1 each |
| `README.md:86`, `docs/development.md:118`, both `.env.example`, `infra/scripts/bootstrap-dev.sh:21,24` | the documented `php artisan key:generate --show` setup command | 6 |
| `.github/workflows/ci.yml:50,138` | the `actions/cache` step's `key` | 2 |
| `pnpm-lock.yaml:2338` | the `path-key` dependency entry | 1 |
| `infra/scripts/check-openapi-conformance.mjs:92` | a local `const key = …` | 1 |
| `.npmrc` | no credential in it at all — four pnpm settings and a filesystem note. S11's unfixed second half: `FORBIDDEN_PATHS` still flags **any** `.npmrc` on the path rule | 1 |
| both review reports **added by this diff** | the quoted evidence in each | 3 + 1 |

Mechanism for the worst one: for a `foreach` binding `$key` to `$value`, group 1 matches `$`, the prefix group matches zero times, `key` matches, the separator is accepted, and a six-character value no marker excuses is captured. Every added PHP key/value loop, every React `key` prop, every security-header entry, every edit to a line containing the documented setup command, and the shared API-contract header constants are refused.

**What they get.** `exit 2` on routine work, with a message that says "the pattern needs widening in `.claude/hooks/guard-secrets.mjs` — fix it there". `--no-verify` does not help (this is a Claude hook, not a git hook) and `git -C` no longer helps (S5 closed), so the two available escapes are: edit `guard-secrets.mjs` to weaken it — which S8 records nothing prevents, and which the block message actively recommends — or commit from a terminal, outside the `Bash` tool, where the guard does not exist at all. Either way the credential scan stops running, which is S1 and S2 by a different road.

**What to change.** Three small changes, in order of value. (1) Drop bare `key` and bare `token` from `ASSIGNMENT`; keep `api_key`, `apikey`, `private_key`, `access_token`, `auth_token`, `*_secret`, `*password` and `app_key` — require a credential-naming prefix for the two generic words. (2) Restore a 12-character floor **and** an entropy or character-class condition for the generic rule, so a verb, a version number, a header name, a header constant and a bare `$value` cannot qualify; keep the 5-character floor only for the unmistakable `CREDENTIAL_SHAPES`. (3) Narrow the `.npmrc` path rule to added lines containing an auth token or password setting, as the first report asked. Then add probe 1's 24 files to `selftest.mjs` as allow fixtures — the one new clean fixture tests the prefix-separator property and not the bare-identifier case, which is why a green 117-assertion suite missed all of this.

**Why HIGH and not MEDIUM**, since the record carries the number and the gate reads it. A false positive exposes nothing by itself, which is the argument for MEDIUM and the reason the reviewer offered it. Four things outweigh it. (i) The expected consequence is not noise but the removal of the control: 33 blocks across 24 files, including the files the next two tasks must change, with `exit 2` and a message recommending the remedy that degrades it. (ii) That consequence is exactly S1 and S2, both rated HIGH last round and both paid for with a fix round; a defect that re-opens two HIGHs is HIGH. (iii) It is measured, not predicted, and it fires on the next commit touching `apps/`. (iv) It is a regression this diff introduces, so the record's "S2 closed" is wrong in a way a reader would rely on. The code reviewer rates the same defect HIGH independently, by a different method. The reviewer will defend HIGH, and would move to MEDIUM only if the content rules were made advisory while the path rules stayed blocking.

### S16 — `git commit <path>` still scans an empty index, so a credential in a modified tracked file reaches history unseen

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:316-320` (`stages`), `:247-251` (`scanIndex`) |
| **Class** | secret exposure |
| **Severity** | HIGH |
| **Verification** | **measured.** Probe 2, throwaway repo: `git commit conf.yaml -m "chore: conf"` → **exit 0, unscanned, commit proceeds**; `git add -A && git commit -m "chore: conf"` on the same file → exit 2, blocked |

**Who the attacker is.** The same actor as S1: an agent or developer committing a file into which a real value was pasted while debugging.

**What they send.** One Bash call: `git commit apps/api/config/services.php -m "chore(api): configure mail [SCP-1]"`, with a mail password added to that tracked file and nothing staged. `git commit <pathspec>` commits the **working-tree** content of those paths, bypassing the index. The `stages` test looks for `add`/`stage`/`--all`/`-a` and finds none, so it takes the `scanIndex()` branch and reads an empty `git diff --cached`. Same for `git commit --only <path>` and `git commit -i <path>`.

**What they get.** Exit 0, the commit proceeds, the credential is in history and in every clone and fork. The same outcome as S1 through a sibling idiom; the round fixed the `git add -A &&` spelling and left this one. The `quality-gates.md` "does not catch" list does not name it, so a reader is told the staging case is handled and this case does not exist.

**What to change.** Treat a `git commit` with a non-option argument after the subcommand as a staging command and take the `scanWorkingTree()` branch; or default to `scanWorkingTree()` and use `scanIndex()` only for a bare `git commit` with no pathspec — the working-tree scan is a superset except when staged content deliberately differs from the working tree, which is rare and errs safe. The durable fix remains the real `pre-commit` hook.

### S17 — four routes still reach `main` with no pull request, including the fully-qualified refspec

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:147-172` (`push-to-protected`), `:273-278` (`auto-merge-pr-api`) |
| **Class** | broken change control |
| **Severity** | HIGH |
| **Verification** | **measured.** Probe 3, `evaluate()` driven directly: 8 of 10 ALLOWED. Corrected from the draft — the `+refs/heads/…` shape **is** caught, by `force-refspec` |

**Who the attacker is.** An agent with `Bash` on a task branch — `git-release` and `qa-engineer` both hold it, as does the calling session — including one asked to "get this live", or one acting on text it read in a file.

**What they send.** Probe 3's measured result:

```
ALLOWED            git push origin refs/heads/main
ALLOWED            git push origin HEAD:refs/heads/main
force-refspec      git push origin +refs/heads/f:refs/heads/main   <- blocked, incidentally
ALLOWED            gh api -X POST /repos/o/r/merges -f base=main -f head=f
ALLOWED            gh api -X PATCH /repos/o/r/git/refs/heads/main -f sha=abc
push-to-protected  git push origin main                            <- control, blocked
```

The first two defeat `push-to-protected` because all three of its sub-tests require the literal word `main` either immediately after a colon or as a whitespace-delimited token two positions after `push`; `refs/heads/` sits in between, and `named` is then true, so the HEAD-is-protected fallback does not fire either. The `+` form is blocked **by the wrong rule, for the wrong reason** — `force-refspec` matches the leading `+`, not the destination — which is the finding restated: nothing in the ruleset judges where a push lands once the ref is spelled in full. The two `gh api` calls are not force pushes and are not `/pulls/N/merge`, the only API shape `auto-merge-pr-api` knows; both move the trunk server-side. A GraphQL merge mutation is also uncovered — not probed, so read-only reasoning.

**What they get.** Unreviewed code on `main` with no pull request, so `task-traceability.yml` never runs and no reviewer sees the diff. There is no server-side backstop: `main` has no branch protection (AC10, open).

**What to change.** Judge the *resolved destination ref*, not the spelling: normalise `refs/heads/X` → `X`, then compare the right-hand side of any refspec and the last positional argument against `PROTECTED_BRANCHES`. Add the repository-merge and ref-update API shapes to `auto-merge-pr-api`, and a rule for a GraphQL merge mutation. Add all five commands to `selftest.mjs` — and assert the rule id, so a catch by `force-refspec` cannot be mistaken for `push-to-protected` working.

### S18 — the segment split is not the pure narrowing the comment claims

| | |
| --- | --- |
| **Where** | `guard-protected-ops.mjs:63-79` (the `SEGMENTS` comment), `:287-311` |
| **Class** | enforcement bypass |
| **Severity** | MEDIUM |
| **Verification** | **measured.** Probe 3: a commit whose message contains a pipe, one whose message contains a semicolon, and `git -c user.name=…` before `commit` on `main` — all three ALLOWED |

The comment claims splitting "can only ever make each piece smaller and the match narrower — it cannot let a dangerous command through … because that command is still wholly inside one piece." That fails when the separator is inside the dangerous command's own quoted argument: the split puts `git commit` in one piece and `--no-verify` in another, and `skip-hooks` needs both. Passing `-c user.name=…` with an embedded separator splits `git` from `commit`, which takes out `commit-on-protected` as well.

The narrowing itself is correct and worth keeping for the cases it was built for; what is wrong is the reasoning for why it is *safe*, and that reasoning is written into the file as a justification for not handling quotes.

**What to change.** Do not split inside single or double quotes — a dozen lines of quote-state scanning, and the rest of the design stays. Failing that, replace the comment's safety claim with the counter-example so the next person does not rely on it, and keep a whole-line second pass for the verb-plus-flag rules.

### S19 — the S11 fixture exemption is wider than "two exact paths"

| | |
| --- | --- |
| **Where** | `guard-secrets.mjs:185-187` (`FIXTURE_FILES`, `isFixtureFile`), `:204-211` |
| **Class** | enforcement bypass |
| **Severity** | MEDIUM |
| **Verification** | **measured.** Probe 4: a header with a trailing space → `[]`; a quoted `+++` header after `selftest.mjs` → `[]`; control → 1 finding |

**Who the attacker is.** Someone deliberately hiding a credential — already priced for in the two named files. The finding is that the exemption reaches files that were never named, which the record claims is impossible.

Two shapes, both confirmed. (1) A file named `selftest.mjs ` with one trailing space: `isFixtureFile` applies `.trim()` before the set lookup, so the exemption matches a different file on disk. (2) Any path git chooses to quote — a path with non-ASCII characters is rendered quoted, which fails the `+++ b/` prefix test, so `currentFile` is **not updated** and the following added lines are judged under the previous file's identity. Diff order is by path, so a new file sorting after `selftest.mjs` inherits its exemption. In `scanWorkingTree()` the same quoting makes `statSync` throw and `catch { continue }` drops the file **silently** — a credential in any Vietnamese-named file is never read at all. The git-quoting half is read, not measured: probe 4 fed the parser a quoted header directly rather than making git produce one.

**What to change.** Compare the untrimmed path, normalise it, and reset `currentFile` to a sentinel on any `+++ ` line that does not parse, so an unparsed header *disables* the exemption rather than extending it. Use `git ls-files -z` and split on NUL. And narrow the set to `selftest.mjs` alone: `guard-secrets.mjs` is exempt only because the 5-character floor made its own doc comment trip the rule — elide the values in that comment and drop the file.

### S20 — the new working-tree scan is unbounded, follows symlinks and reads non-text, and every failure mode is open

| | |
| --- | --- |
| **Where** | `guard-secrets.mjs:261-286`, `.claude/settings.json:22` (`timeout: 30`) |
| **Class** | enforcement bypass |
| **Severity** | MEDIUM |
| **Verification** | code path by reading. **Probe 5 was skipped — Windows, no `mkfifo`** — so the harness's behaviour on a hook that hangs, times out or crashes is **unmeasured** |

A commit that stages, with one of: (a) a few hundred untracked files just under the 2MB per-file cap — there is no aggregate cap, and the accumulating string throws `RangeError: Invalid string length` outside any `try`; (b) an untracked symlink or FIFO whose reported size is 0 but which never reaches EOF, so `readFileSync` hangs or exhausts memory; (c) any file over 2MB, skipped wholly and silently — a `.sql` seed dump carrying a connection URL is exactly that shape.

The hook then crashes with a non-2 exit, or is cancelled at 30 seconds. On the documented hook contract neither blocks the tool call, so the commit proceeds with the scan never having reported — but that last step is the unmeasured one. `readFileSync` also follows symlinks with no `lstat` check, so the hook reads files outside the repository when a link points there.

**What to change.** Cap the total read and stop with an explicit "could not scan everything" failure rather than silence; `lstat` and skip anything that is not a regular file; skip files whose first bytes contain a NUL. And settle the question the recorded S6 also asks: on any error, exit 2 rather than 0.

### S21 — credential forms the scan still cannot see, including the ones the first report asked for

| | |
| --- | --- |
| **Where** | `guard-secrets.mjs:37-45`, `:48-59`, `:91` |
| **Class** | secret exposure |
| **Severity** | MEDIUM |
| **Verification** | by reading |

- **URL userinfo.** A `DATABASE_URL`, `REDIS_URL`, `MAIL_URL` or Sentry DSN carrying a password in the authority. No key word matches and no `CREDENTIAL_SHAPES` rule covers it. Laravel reads `DATABASE_URL` and `REDIS_URL` natively, and the first report's S2 remedy asked for this shape explicitly; it was not added while S2 was marked closed.
- **A value containing a space.** The value class excludes whitespace, so a passphrase yields a short candidate and then fails the floor.
- **A value on the next line** — YAML block scalars, a PHP array split across lines. The scan is line-by-line.
- **An ignored file force-added.** `git add -f apps/api/.env && git commit` leaves the file in neither `git diff HEAD --name-only` nor `git ls-files --others --exclude-standard`, so `FORBIDDEN_PATHS` — the strongest rule in the file — never sees it. `guard-protected-ops` has no rule for `git add -f` either.
- **Non-text containers.** An archive, a SQLite file or an office document read as UTF-8 yields replacement characters; a credential inside is invisible. Inherent, but not stated anywhere.
- **`.env.testing`, `.env.ci`, `.env.docker`, `.env.backup`** — `FORBIDDEN_PATHS` covers only four suffixes. Mitigated by `.gitignore`, so this matters only together with `-f`.

**What to change.** Add a URL-userinfo shape to `CREDENTIAL_SHAPES`; allow a quoted value to contain spaces; extend `FORBIDDEN_PATHS` to any `.env.*` other than `.env.example`; and for the `-f` case build the `names` list without `--exclude-standard` (path rules only), or add `git add -f` to `guard-protected-ops`. Anything not fixed belongs in the `quality-gates.md` "does not catch" list.

### S22 — the S4 fix moved the write channel rather than removing it

| | |
| --- | --- |
| **Where** | `.claude/skills/review/SKILL.md:42-55`; `docs/workflow/quality-gates.md:25`; the three reviewer definitions |
| **Class** | separation of duties / indirect prompt injection |
| **Severity** | MEDIUM |
| **Verification** | by reading. This review round is itself an instance of the mechanism: the reviewer named six commands and six were run |

**Who the attacker is.** Whoever can get text in front of a reviewer. The reviewers are pointed at `diff.patch`, the task record and `docs/`; on a fork pull request the diff is attacker-authored, and a vendored file or a crafted task record works from inside.

**What they send.** Text in review material that causes the agent's report to contain a command request — the skill's own protocol — such as a `node -e` that writes a file, or a `curl` to an external host. Step 2 says to run what the agent asks for and send the output back. There is no instruction to vet the command, no allowlist, and the session asked to run it holds unrestricted `Bash` and is, by the skill's own admission, usually the session that wrote the code.

**What they get.** Arbitrary command execution by proxy — the capability S4 was closed by removing. The documentation then states the stronger claim: `quality-gates.md:25` reads "structural: no write channel exists". No *direct* channel exists, and that is a real improvement — a reviewer can no longer write its own verdict unilaterally. But "no write channel exists" is the same category of overstatement the last round's verdict refused to merge. Two smaller items in the same shape: the three reviewers can `Read` any absolute path, including the real `.env`, and their returned text is transcribed verbatim into a file that gets committed — so the report is an uninspected channel in both directions.

**What to change.** In the skill, make the requested command untrusted input: run only from a named allowlist, refuse anything containing a redirect, `tee`, `sed -i`, a git write, `node -e`, `curl` or `rm`, and say who ran what — the skill already asks for the last part, and this report does it. Change the `quality-gates.md` cell to "no direct write channel; probes are proxied by the calling session — see S22".

### S23 — the gate's new evidence checks are satisfied by copying the shipped template

| | |
| --- | --- |
| **Where** | `check-task-traceability.mjs:183-196`, `:202-216`; `.claude/reviews/_templates/*.md` |
| **Class** | CI gate bypass |
| **Severity** | LOW |
| **Verification** | **measured.** Probe 6: the unmodified template is **2377 characters** and the Verdict test returns **true** |

Copying the template into a task's review directory clears the 400-character floor and satisfies the Verdict test with no review performed. The acceptance-criteria half is satisfied by the AC id appearing *anywhere* in `Test Results`, including inside a sentence saying it was not checked. So the checks raise the word count of a forged verdict, not its cost. They do have real value against a different failure — the half-finished record where someone forgot to write the report — and `_TEMPLATE.md` shipping `—` instead of `0` is a genuine improvement, because that one cannot be satisfied by inaction.

**What to change.** Give the templates a sentinel the gate rejects, exactly as the severity table now does: ship a `NOT REVIEWED` verdict and fail on it. Require `Test Results` to carry the AC id on a line that also carries a verdict word. The structural fix remains S9's.

### S24 — an unknown or detached HEAD is still treated as unprotected

| | |
| --- | --- |
| **Where** | `guard-protected-ops.mjs:82-92`, `:136-183`, `:306-307` |
| **Class** | enforcement bypass |
| **Severity** | LOW |
| **Verification** | **measured.** Probe 3: `git merge feature/x` with `branch=''` → ALLOWED |

`currentBranch()` returns `''` when `git` fails and `HEAD` when detached, and neither is in `PROTECTED_BRANCHES`, so `commit-on-protected`, the no-refspec arm of `push-to-protected` and `merge-into-protected` all stand down. The first report recommended treating an unknown branch as protected; the round added two new rules that inherit the gap instead. Related: the `moved` regex requires `git checkout`/`git switch` adjacency, so a `-C`-prefixed checkout does not move `standingOn`.

### S25 — the commit filter now runs a full working-tree scan on commands that merely mention the word

| | |
| --- | --- |
| **Where** | `guard-secrets.mjs:309,316-320` |
| **Class** | control degradation |
| **Severity** | LOW |
| **Verification** | by reading |

Widening the filter for S5 also matches `git log --grep=commit` and `git add -A && echo "ready to commit"`. With `add` present, the latter takes the `scanWorkingTree()` branch: every untracked file read, inside a 30-second budget, for a command that commits nothing — and given S15's 33 findings, the unrelated command is then blocked with a message about credentials. Require the word to be a subcommand, keeping the `-C`/`-c` coverage S5 bought.

## Payment integrity

`CLAUDE.md` §6. N/A throughout: the 43 files are workflow definitions, hooks, docs, the CI gate and two `package.json` script entries. There is no route, no controller, no migration and no payment code in the diff.

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

Three process-level notes bear on payments. Two are recorded: S10 means a future change to store-scoped admin code can reach a pull request with no security review required, and S9 means the review that is required is authored by the actor it judges. The third is new and measured: probe 1 flagged the shared idempotency-key and guest-order-token constants, the idempotency middleware, the Sanctum config and the CSRF rewrite rule. Those are the surfaces named in SCP-001 and SCP-002. The pressure to disable the credential guard will therefore arrive with the first real payment change, which is why S15 is rated where it is.

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
| No hardcoded credential in the diff | PASS — the credential-shaped values are GitHub's and AWS's documented examples, an invented `base64:` APP_KEY and invented passwords, all in `selftest.mjs` fixtures and in the quoted evidence of the previous review report. The whole diff was grepped for live token prefixes and every hit is one of those. Probe 1's 33 findings were inspected against the same list: none is a credential |
| `.env` not in the diff | PASS — 43 files, none is `.env` or `.env.*` |
| No credential, payment payload or PII written to a log | PARTIAL, unchanged — `guard-protected-ops.mjs:339` still echoes the whole blocked command, and for the `write-dotenv` rules that command contains by definition the value being written. Print the rule id and target path instead |
| No internal detail in an error response | PASS — no HTTP surface in the diff |

One observation this table should carry: both review reports added by this diff are flagged by the guard the diff ships — the security review three times, the code review once (probe 1), on inert invented values quoted as evidence. That is why the examples in this report have their values elided, and it is the cleanest demonstration of S15 available: the artifact recording the defect cannot be committed through the control it describes.

## Dependencies

**Not run.** `pnpm audit` and `composer audit` were not executed — the reviewer has no `Bash`, and did not ask for them because this change alters no manifest and no lockfile: `package.json` gains two script entries and no dependency, and the lockfiles are not in the diff. Nothing here should be read as a statement that the dependency tree is clean.

The `/code-review` skill is not shipped by this repository and was not run; the three reviewer definitions and `review/SKILL.md` now say so themselves, which closes M5 correctly.

## Probes: who ran what, and what is still unmeasured

All six probes were run by the **calling session** on 2026-11-16, not by the reviewer, which specified them and saw the output quoted below and nothing else.

| Probe | Result | Finding |
| --- | --- | --- |
| 1 — `scanDiff` over every tracked file, each line treated as added | **33 findings in 24 files, none a credential** | S15 confirmed, and worse than the predicted list: `.htaccess`, `config/sanctum.php`, `headers.ts` (×2) and an `AuditTrailTest.php` were not predicted |
| 2 — `git commit <path>` vs `git add -A && git commit`, throwaway repo | **exit 0 unscanned** vs exit 2 blocked | S16 confirmed by observation |
| 3 — `evaluate()` over 10 commands | **8 of 10 ALLOWED**; control blocked | S17 confirmed and corrected, S18 confirmed, S24 confirmed |
| 4 — `scanDiff` with a trailing-space and a quoted `+++` header | `[]`, `[]`, control 1 finding | S19 confirmed |
| 5 — FIFO/hang behaviour | **skipped — Windows, no `mkfifo`** | S20's fail-open half remains unmeasured |
| 6 — template length and Verdict test | **2377 chars, true** | S23 confirmed |
| 7 — `pnpm claude:selftest` | **117 passed, 0 failed** (116 distinct per the code reviewer's L1) | the suite is green, and that is a measured fact rather than a number copied from the task file |

**Still unmeasured.** For S20, the question that decides its severity is what this Claude Code build does with a `PreToolUse` hook that exceeds its timeout or exits non-zero. Two Windows-executable substitutes: create several hundred untracked files just under 2MB and time the hook on a staging commit; and run the hook from a directory that is not a git repository at all. The first should take many seconds or throw; the second should print exit `0` — a commit allowed because the scan could not run, which is the recorded S6 confirmed on this platform. If the first returns promptly with exit 2, S20 drops to LOW.

## Not defects

- **The S1 working-tree rewrite itself.** Reading `git diff HEAD` plus untracked contents is the right shape, and synthesising a diff for untracked files is the right call. S16, S19, S20 and S21 are about edges of that design, not the design. Probe 2's second half shows it works for the idiom it was built for.
- **`--exclude-standard` for content scanning.** Correct; the gap is only that the same list feeds the *path* rules, where `git add -f` then has no backstop (S21).
- **The 2MB per-file cap** as a concept. Sound; the missing aggregate cap and the silent skip are the problem.
- **`base64:` decoding in `looksLikePlaceholder`.** It keeps the documented test `APP_KEY` allowed while a random key is caught. The substring test on decoded bytes is inconsistent with the "whole value or start" rule the round established, but random key bytes match with probability about 2 × 10⁻⁸ and steering it requires choosing the key — below the bar.
- **Carrying branch state across segments.** The right mechanism, better than matching the pair by text. S18 is about quoting, not about this.
- **Reading `APP_ENV` and `compose.prod` from the whole line** while reading `--env=` from the segment. Correct split of concerns.
- **Judging `.env` writes by target path.** The right way round, and the quoted form is handled.
- **`_TEMPLATE.md` shipping `—`.** The best change in round 2: it cannot be satisfied by inaction, which is the property S23's character floor lacks.
- **`git fetch origin` and `origin/main...HEAD`** throughout the skills. Right fix, consistently applied.
- **Dropping `Bash` from the three reviewers.** The direct hole is closed and the record states the cost plainly instead of glossing it. S22 is about the replacement channel, not the decision — which was correct, for the reason the skill gives: losing independence of evidence hurts less than losing independence of action. This review is the test case, and it worked: the reviewer could not confirm anything itself, so it asked, and six probes came back — three of which changed what it wrote.
- **`qa-engineer` and `git-release` keeping `Bash`.** Deliberate, documented in the same row as the claim, and unavoidable for what they do.

## The documentation, judged against last round's verdict

Last round the diff could merge and the documentation could not. Both claims named then are now true:

- `quality-gates.md:16` reads partial with a "What the secret scan does not catch" section, and all three gaps it lists are real. It is honest in register and incomplete in content: it does not name `git commit <path>` (S16), `git add -f` (S21), git-quoted filenames (S19), or the false-positive load that will push people off the guarded path (S15).
- The agent descriptions no longer claim "cannot edit files". The three reviewers declare `tools: Read, Glob, Grep` and their bodies match; `qa-engineer` and `git-release` say "holds no `Write` or `Edit` tool", which is exactly true. `docs/workflow/README.md`'s new Tools column and its "by prompt means the scope is an instruction, not a permission" line are the most honest paragraphs in the set.
- `git-workflow.md` replacing stale assertion counts with "run it for the current count" and "read a pass as no known regression, not as the rules are right" is correct — and S15, S16 and S17 are three more instances of exactly that, all found under a 117-assertion green suite.

One cell is now differently wrong: `quality-gates.md:25` "structural: no write channel exists" (S22). It is the only claim in the table that promises more than the mechanism delivers, and it is the same failure mode as last round's two, caught one round earlier.

One inconsistency worth a one-line fix: `security-reviewer.md`'s opening line still says it holds `Bash`, two paragraphs above the new section saying it does not. The frontmatter binds, so the behaviour is right — but an agent reading its own prompt is told both things.

## Verdict and whether this can go to a pull request

**CHANGES REQUIRED. Open the pull request; do not merge it as a claim that the credential guard is usable.**

Nothing in this diff is reachable by a customer, moves money, crosses a store boundary or exposes customer data. Merging it creates no live vulnerability, and rounds 2 and 3 made the workflow materially better than what it replaced: the S1 staging defect, the S2 name-matching defect, S5, M1, M7, M10 and the false documentation are all genuinely closed — probe 2's second half and probe 7 are the evidence — and dropping `Bash` from the three reviewers converted the central claim of the whole workflow from a sentence into a tool grant.

What should not merge unchanged is `guard-secrets.mjs`. **S15** is a regression this round introduced, and it is measured, not argued: 33 blocks across 24 files, none a credential, including `.npmrc`, `.htaccess`, `config/sanctum.php`, both Next.js security-header configs, the shared idempotency-key and guest-order-token constants, the repository's own documented setup command, and both review reports in this diff. The block is `exit 2` and its message recommends weakening the pattern. The realistic response is that someone commits outside the `Bash` tool, after which the guard catches nothing and S1 and S2 are back. Three small edits fix it, and the allow fixtures to pin it are listed in the finding.

**S16** and **S17** must not be recorded as closed. Each is a residual of a finding this round marked fixed, each is one more spelling of the same idiom, and probes 2 and 3 observed both. S17's measured detail matters for the record: the `+refs/heads/…` shape is caught by `force-refspec`, which judges the leading `+` and not the destination — so the catch is incidental, and the rule that is supposed to protect the trunk still does not look at where a push lands.

Operationally, per `/review` step 6: three HIGH findings mean `CHANGES_REQUESTED`, not `READY_FOR_PR`, and the gate will refuse the pull request while `Review Findings` carries a non-zero HIGH. That is the gate working.

Finally, the limit at the top of this report, now with its result: every finding was derived by reading, and six were then put to a probe run by someone else. Three of those probes changed the text — probe 1 added four files that were not predicted, probe 3 corrected S17, and probe 5 could not run at all, so S20's fail-open claim stays unmeasured and is labelled as such. That is the shape the S4 fix leaves: the reviewer cannot confirm its own findings, and the record has to say who did.
