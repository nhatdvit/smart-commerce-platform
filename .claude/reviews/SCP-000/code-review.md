# Code review — SCP-000

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-13 |
| **Reviewer** | `code-reviewer` agent |
| **Diff** | `git diff origin/main...HEAD`, 41 files, +4827/-1 |
| **Verdict** | CHANGES REQUESTED |

> Transcribed by the calling session from the agent's returned report, per
> `.claude/skills/review/SKILL.md`. The agent has no editing tool, by design.
> H1, H2, M4, M7 and M10 were re-verified independently by the session before
> this file was written; see "Independent verification" at the end.

## Coverage, honestly

Fully read and exercised: both guards (`evaluate` and `scanDiff` driven in-process over ~55 crafted commands and diffs), `record-task-activity.mjs`, `selftest.mjs` (ran it: 82 passed), `check-task-traceability.mjs` (ran it against this branch: exit 1, 7 problems), `.github/workflows/task-traceability.yml`, `.claude/settings.json`, the task ledger (`README.md`, `_TEMPLATE.md`, `SCP-000.md`, structure of `SCP-001/002`), all four review templates, `docs/workflow/{README,git-workflow,quality-gates}.md`, the diffs to `CLAUDE.md`/`.gitignore`/`.prettierignore`/`package.json`, and skills `pr`, `review`, `test`, `implement`, `fix`, `status`, `task`.

Partially covered: `.claude/skills/plan/SKILL.md` (grepped, not read line by line) and the agent definitions (frontmatter and the report/tooling sections of all nine; not every body read in full).

Not covered: no PHP or frontend suite was run (no code in this diff needs one), and no attempt was made to observe a hook firing end to end — that is AC8, known open.

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 2 | yes |
| MEDIUM | 10 | no |
| LOW | 7 | no |

This installs what the Phase A plan approved — skills orchestrate, hooks hard-block, CI gates the pull request — and the layering, the honesty of the documentation and the closure of the earlier N2 finding are all in good order. It cannot go to a pull request yet: two widened command patterns block this repository's own documented commit and pull-request idioms, which is the failure mode that teaches people to route around a guard; and the traceability gate — the one hard gate at merge time — reports success on a record whose review never happened. Counts above exclude the `guard-secrets` defects already recorded in `security-review.md`.

## Did it implement what was approved?

Yes, with two deviations worth recording.

Against the `Technical Plan` in `.claude/tasks/SCP-000.md`: orchestration lives in skills, not in agent definitions (no agent instructs another agent to delegate); enforcement is split three ways as approved (skills validate state, hooks `exit 2`, `task-traceability.yml` gates the PR); `.claude/settings.json` carries hooks only and no `permissions` block; `ci.yml` is untouched and the gate is a separate workflow; hooks are Node, per AC7. N2 is properly closed — all four read-only agents and both `/test` and `/review` now agree that the **skill** writes the report and the agent returns it. The `%p` merge-commit skip is correct: merge commit `4b74fc0` has two parents and is filtered, the six single-parent commits are still checked (verified by running the gate).

Deviations:

1. The diff carries `.claude/tasks/SCP-002.md` — 841 lines, a complete approved plan for a different task, and the largest file in the change. Neither the plan nor the `Files Changed` table mentions it (M9).
2. `/review` step 4 and the code-review template tell the reviewer to run a `/code-review` skill that exists neither in `.claude/skills/` nor in this environment (M5). It is the one instruction across the eight skills that cannot be carried out.

AC conformance: the criteria the diff claims are met are met, with these qualifications — AC4's `.env`-write and destructive-migration coverage is narrower than its wording (M1, M7), AC5's "the guards do not block ordinary work" is contradicted by H1, and AC6's credential half is contradicted by the recorded security findings. AC8 and AC10 are known-open and not re-raised.

## Already recorded, independently confirmed — not counted as new

From `security-review.md`, all re-verified against the shipped code during this pass, so a later reader knows two reviewers agree: the `--cached` scan sees nothing for `git add -A && git commit` at `PreToolUse` time; `ASSIGNMENT`'s `\b` cannot match `MAIL_PASSWORD`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `AWS_SECRET_ACCESS_KEY`; `looksLikePlaceholder` waives any value merely containing `test`, `secret`, `password`, `none` (reproduced `DB_PASSWORD=mysecretpw1A`, `DB_PASSWORD: Testing2026!xQ`, `'api_key' => 'latest9f3kd81nvQ'` and `DB_PASSWORD: Ab12<cd34ef` as ALLOW); `git -C . commit` skips the hook; `git push origin HEAD:main` is allowed outright (`git push origin feature/x:main` likewise). One shape adjacent to the last of these is **not** in that report and is carried below as M10.

## Findings

### CRITICAL

None. This diff is tooling: its defects are gaps in new controls, not regressions against `main`.

### HIGH

#### H1 — The guard blocks this repository's own commit and pull-request idioms

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:152` (`skip-hooks`), `:62` (`force-push`), `:90` (`delete-remote-branch`) |
| **Category** | correctness / maintainability |

**What is wrong.** Three widened patterns pair `\bgit\s+(commit|push)\b` with `[^\n]*` and then a short-flag class (`\s-[A-Za-z]*n[A-Za-z]*\b`, `...f...`, `...d...`). `[^\n]*` crosses `&&`, so the flag that triggers the rule can belong to a *different* command later on the same line, and `-n`, `-f`, `-d` are ordinary flags of `git log`, `head`, `tail` and `gh pr create`.

**Failure scenario.** Verified with `evaluate(command, 'feature/x')`:

```
git add -A && git commit -m "x" && git log -n 1 --oneline   -> BLOCK(skip-hooks)
git commit -m "x" && git log --oneline -n 3                 -> BLOCK(skip-hooks)
git push -u origin HEAD && gh pr create -d -f -B main       -> BLOCK(force-push)
git push -u origin feature/x && gh pr create --fill -d      -> BLOCK(delete-remote-branch)
git push origin feature/x && rm -rf node_modules            -> BLOCK(force-push)
```

The first two are the commit-then-confirm shape `docs/workflow/git-workflow.md:54-76` prescribes — step 6 is literally "check the SHA reached the task log". The next two are `/pr` steps 2 and 4 run as one line, with `gh pr create`'s own `-d`/`-f`. The refusal text says "Skipping hooks defeats the checks this workflow exists to run", which is untrue of the command and gives the user nothing to act on, so the lesson taken is that the guard is unreliable — the outcome the file's own header warns against. AC5's 20 allow fixtures contain no compound command, so the suite cannot see this (see M8).

**What to change.** Evaluate per command segment rather than per line: split on `&&`, `||`, `;`, `|` and run `evaluate` on each piece — this tightens every existing rule at once. At minimum replace `[^\n]*` with `[^\n;&|]*` in the three patterns. Add the five commands above as allow fixtures and keep `git commit -nm x` as a block fixture so the narrowing does not undo F2.

#### H2 — The traceability gate passes on a record with no review and two empty report files

| | |
| --- | --- |
| **Where** | `infra/scripts/check-task-traceability.mjs:118-136` and `:148-156`; enabled by `.claude/tasks/_TEMPLATE.md:94-97` |
| **Category** | correctness (silent pass in a gate) |

**What is wrong.** Both substantive checks are satisfiable without a review having happened. The findings check demands `CRITICAL` and `HIGH` equal `0`, and `_TEMPLATE.md` ships those cells pre-filled with `0` — so "clean review" is the default state of every new task file. The evidence check is `existsSync` only: a zero-byte `code-review.md` and `test-report.md` pass. Nothing parses the acceptance-criteria checkboxes, the `Approval` row, or any verdict inside the reports.

**Failure scenario.** Take `SCP-002.md` exactly as it ships in this diff (status `APPROVED`, `| CRITICAL | 0 | |`, `| HIGH | 0 | |` straight from the template, no review ever run). Implement it, skip `/test` and `/review`, set `Status` to `READY_FOR_PR`, `touch .claude/reviews/SCP-002/{test-report,code-review}.md`, open the PR: the gate exits 0 and prints "Traceable: SCP-002 has a record, evidence and clean findings." It behaves identically with every acceptance criterion unticked and `Approved by | —`. The one mechanism that refuses at the point of no return certifies work nobody tested, reviewed or approved, while `docs/workflow/quality-gates.md:21` records it as enforcing "No unresolved CRITICAL/HIGH at PR time ✅ both".

**What to change.** Three additions in the script's existing style: (1) change `_TEMPLATE.md`'s severity cells to `—`, the value SCP-000 chose deliberately and which the gate already rejects, so a number must be written by someone; (2) require each report to be non-empty, to contain a parseable `Verdict`, and to carry severity counts matching the task record; (3) fail on any unticked `- [ ]` under `Acceptance Criteria` unless `Test Results` names it NOT VERIFIABLE. Until (1) and (2) land, soften the claim in `quality-gates.md:21` rather than leaving it overstated.

### MEDIUM

#### M1 — The `.env` write rules only match a bare, unquoted `.env` in the current directory

**Where** `.claude/hooks/guard-protected-ops.mjs:140` (`write-dotenv`), `:159-160` (`write-dotenv-indirect`). **Category** correctness.

Both patterns require the match to start at `.env` with at most a `./` prefix, so any directory component or surrounding quote defeats them. Verified: `echo "APP_KEY=abc" >> apps/api/.env` → allow; `cp .env.example apps/api/.env` → allow; `echo x > "apps/api/.env"` → allow. Meanwhile `printf x > .env` and `cp .env.example ./.env` are blocked, so the rule looks sound. `apps/api/.env` is a real location here — `apps/api/.env.example` is tracked and `apps/api/.gitignore:3` ignores `apps/api/.env` — and a Laravel-conventional agent writes exactly there, destroying a developer's local credentials and `APP_KEY` (which makes existing encrypted values unreadable). `docs/workflow/quality-gates.md:15` records this as a hard block.

Match the filename at a path boundary and tolerate quotes in both rules, e.g. `(["']?)(?:[\w./-]*\/)?\.env(?:\.\w+)?\1(\s|$|;)`, and extend to `.env.local`/`.env.production`. Test the *destination* argument for `cp`/`mv`/`install`/`sed -i`: the current rule also false-positives on `cp .env .env.backup`, which reads `.env` rather than writing it.

#### M2 — A failed `git commit` writes a false ledger row, and `--amend` writes a second one

**Where** `.claude/hooks/record-task-activity.mjs:91-107`. **Category** correctness (record integrity).

The recorder reads `git log -1` and deliberately ignores the hook payload — which is also the only thing that says whether the command succeeded. `PostToolUse` fires when the Bash call completes, including a non-zero exit, so after a commit git refused, `git log -1` is the *previous* commit, written with `new Date()` as its timestamp.

On a branch whose last commit is not already in the log, run `git commit -m "docs(order): note the cutoff [SCP-014]"` with nothing staged. Git exits 1 with "nothing to commit"; the hook appends a row naming the previous commit at a time it did not happen. Separately `git commit` then `git commit --amend` yields two rows, the first naming a sha no longer in the branch — the comment at `:94-97` claims the short-sha comparison covers "a `commit --amend`", which it cannot, since amending changes the sha.

Read the exit status from the payload and exit 0 on failure; take the timestamp from `%cI`; on amend, replace the row whose sha is no longer reachable (`git cat-file -e`) instead of appending.

#### M3 — The duplicate guard greps the whole file, so a sha mentioned in prose is never logged

**Where** `.claude/hooks/record-task-activity.mjs:99`. **Category** correctness (record integrity).

The guard searches the entire task record for the short sha in backticks, not the Activity-log table. Any 7-character sha quoted anywhere in the record permanently suppresses its row. `SCP-000.md` discusses commits `58869f9` and `aa567c7` in prose, so any firing that resolves to either sha records nothing. Writing about a commit in the record excludes it from the record's own log, which inverts the intent — and the paragraph documenting "no row was appended" is itself a reason no row can be appended.

Slice the file at `MARKER` and search only the text after it.

#### M4 — The severity-row regex is not scoped to the Review Findings section

**Where** `infra/scripts/check-task-traceability.mjs:119`. **Category** correctness (silent pass).

`new RegExp('\\|\\s*CRITICAL\\s*\\|\\s*([^|]*)\\|')` runs against the whole file and takes the first match. The script already has the right idiom twenty lines on — the section extraction used for `Blockers` at `:139`.

A record that quotes the review report's own summary table (`| CRITICAL | 0 | yes |`) anywhere above `## Review Findings` — which `/review` step 5 and the template encourage, since counts are transcribed from the report — passes the gate even when the real table below reads `| CRITICAL | 1 | not fixed |`. That this table has already been mis-shaped once (N3) is evidence the shape is not stable enough to parse positionally.

Extract `## Review Findings` with the same section regex used for `Blockers` and match the severity rows inside it only.

#### M5 — `/review` and the report template require a `/code-review` skill that does not exist

**Where** `.claude/skills/review/SKILL.md:62-63`; `.claude/reviews/_templates/code-review.md:46`; `.claude/agents/code-reviewer.md:77-79`. **Category** maintainability (unexecutable instruction).

"Also run the repository's own `/code-review` skill and fold its confirmed findings in" names a skill that is not among the eight in `.claude/skills/` and is not installed here. `/review SCP-002` reaches step 4, the step cannot be performed, and the session either drops it silently or reports an automated pass that never ran. The template then offers `Source | manual review / /code-review skill` as a value, inviting a claim nobody can check. This review hit it directly: the step was requested and could not be run.

Drop the reference from all three files, or add the install step to `docs/workflow/README.md` and make its absence a stated, visible skip in `/review`.

#### M6 — "Can edit files? no" is behavioural, not structural: all four read-only agents have `Bash`

**Where** `docs/workflow/README.md:37-56`; `CLAUDE.md:335`; `.claude/agents/qa-engineer.md:3-4` and the three reviewers. **Category** correctness of a stated guarantee.

*Consolidated with S4 in `security-review.md`, which rates it HIGH; recorded here for the documentation half.* `docs/workflow/README.md:53-55` says the read-only column holds "not by convention, but because the tools are not in its definition", and `CLAUDE.md:335` states as project law that "Review is done by agents that cannot edit files". All four have `tools: Read, Glob, Grep, Bash`, and Bash writes files. The same table's "`.claude/tasks/` only" and "plans and `docs/` only" for `task-manager` and `software-architect` are equally unenforced — both hold unrestricted `Write, Edit`.

`/test SCP-014` calls `qa-engineer`; one Pest assertion fails; the agent runs `sed -i 's/assertSame(1200/assertSame(1300/' …` and reports a green suite with real output. AC2 as worded ("no file-editing tools") is literally true — the overclaim is entirely in what the documentation builds on top of it, which is where a reader's trust is placed.

#### M7 — `migrate:reset` is allowed while `migrate:refresh` is blocked

**Where** `.claude/hooks/guard-protected-ops.mjs:110-114`. **Category** correctness.

`destructive-migration` enumerates `migrate:fresh|migrate:refresh|db:wipe`. `php artisan migrate:reset` rolls every migration back — the table-dropping half of the blocked `migrate:refresh`. Verified: allow. An agent asked to "reset the local database" picks the verb that matches the words of the request, and the dev database with its seeded demo orders is gone. `CLAUDE.md` §7's "or destructive schema operations" covers it; the pattern does not.

#### M8 — The self-test's fixture space is one command wide and one key name deep

**Where** `.claude/hooks/selftest.mjs:39-128`, `:150-183`, `:182`. **Category** maintainability (test adequacy).

The suite reports 82 passed and is the stated evidence for AC4, AC5 and AC6 — but its fixtures vary only the thing each rule already matches. Every one of the 61 ops fixtures is a single command, so no assertion can see a pattern reaching across `&&` (H1). Every `guard-secrets` block fixture uses `DB_PASSWORD`, `client_secret` or a token shape, so none can see the keyword-boundary gap found for `MAIL_PASSWORD`/`POSTGRES_PASSWORD`, and no fixture pairs a real credential with a value containing `test`/`secret`, so the placeholder waiver is unexercised in the block direction. Two smaller weaknesses compound it: the secret assertions check only `scanDiff(input).length > 0`, so a regressed pattern stays green whenever another rule happens to fire on the same line, and `docker compose down` is asserted allowed twice (`:110`, `:123`), so the headline number double-counts.

Three defects of three different kinds were shipped under a green 82-assertion suite and a `quality-gates.md` claim that it "proves all of this, in both directions". The suite's shape is why: it pins the canonical spelling of each rule, which is precisely what F2 was raised about, one layer up.

Add the fixture *classes* the rules can fail in, not more spellings: at least one compound command per `git` rule in both directions; one block fixture per credential key name the `ASSIGNMENT` rule claims; one real credential whose value contains each placeholder marker. Assert the specific rule name rather than a non-empty array, and drop the duplicate.

#### M9 — Another task's approved plan rides along in this branch, and `Files Changed` does not mention it

**Where** `.claude/tasks/SCP-000.md:105-121`; `.claude/tasks/SCP-002.md` (841 new lines); `docs/workflow/git-workflow.md:22-24`. **Category** maintainability / plan conformance.

Commit `95cabde` added `SCP-002.md`, a complete approved technical plan for a different task. The `Files Changed` table still reads "`.claude/tasks/` | new — index, template, SCP-000, SCP-001", omitting the largest file in the diff. "One task, one branch" is this branch's own rule. If SCP-000's PR is rejected or reverted, SCP-002's plan and its Tech Lead approval row go with it, and the ledger loses an approval that was genuinely given. The gate cannot see it: the commit carries `[SCP-000]`, which is all it checks.

Move `SCP-002.md` to its own branch, or keep it and add the row to `Files Changed` with one sentence saying why. The silence is the part that is wrong.

#### M10 — Nothing refuses a commit made while HEAD is on a protected branch

**Where** `.claude/hooks/guard-protected-ops.mjs:57-176`, `:94-103`. **Category** correctness / authorization. *Adjacent to S3 in `security-review.md`; reported separately because the fix is a different rule.*

The rule set reasons about `git merge` and `gh pr merge` while standing on a protected branch, but not about `git commit` there. The `branch` argument is already threaded into `evaluate` for the merge rule, so the information needed is present and unused. Verified: `evaluate('git commit -m "x [SCP-1]"', 'main')` → allow, and `evaluate('git push', 'main')` → allow. An agent that lands on `main` commits and pushes straight to the trunk, and because `main` has no branch protection (AC10), nothing downstream stops it. `docs/workflow/git-workflow.md:89` lists only the merge shape, so the documentation presents this as closed.

One rule: refuse `git commit` when `branch ∈ PROTECTED_BRANCHES`, with the reason naming `/implement` step 2 ("cut a branch first").

### LOW

#### L1 — The documented self-test counts are stale

`docs/workflow/quality-gates.md:104`; `docs/workflow/git-workflow.md:97-99`; `.claude/tasks/SCP-000.md:129`; `.claude/reviews/SCP-000/test-report.md:14,34,87,159`. `pnpm claude:selftest` prints **82 passed, 0 failed** (run, confirmed). Four files still say 60, and `git-workflow.md` still says "21 blocked commands and 20 real commands" against 35 block and 26 allow fixtures. `SCP-000.md`'s `Test Results` table says 60 while its prose twelve lines later says 82 — the kind of internal contradiction this ledger exists to prevent.

#### L2 — The task index and the task record disagree about SCP-000's status

`.claude/tasks/README.md:53` (`IN_PROGRESS`) vs `.claude/tasks/SCP-000.md:6` (`REVIEW`). `/status all` reads the index, so it will report a status the record contradicts — the "ledger is lying" case `.claude/skills/status/SKILL.md:40-43` exists to catch. Both files are machine-readable, so the gate could compare them.

#### L3 — The generic assignment rule ignores values shorter than eight characters

`.claude/hooks/guard-secrets.mjs:74` (`[^'"\s,;)]{8,}`). `DB_PASSWORD: Zx9!qLm` (seven characters) yields no findings. Short credentials are bad practice and real; the floor exists to avoid matching `true`/`null`-shaped values, which the placeholder list already handles. Lower it to five or six when the placeholder logic is reworked.

#### L4 — Skills diff against a local `main` that may be stale or absent

`.claude/skills/review/SKILL.md:26-27`; `.claude/skills/pr/SKILL.md:30`; `.claude/reviews/_templates/code-review.md:8`. The gate uses `origin/${baseRef}` and explains why; these three use `main...HEAD` and `main..HEAD`. In a fresh clone or a worktree without a local `main` the commands fail; with a stale local `main` the reviewer reads a diff that includes main's own work — the mistake this task's own review brief had to warn about. Use `origin/main...HEAD` in all three.

#### L5 — `/auth/i` and `/payment/i` match any path containing the substring

`infra/scripts/check-task-traceability.mjs:173-174`. `/auth/i` matches `author`, so touching `docs/AUTHORS.md` or `packages/ui/src/author-badge.tsx` demands a `security-review.md` before the PR can pass. A security review demanded for a reason the author cannot see is how a required review becomes a file written to get past a gate. Anchor to path segments, e.g. `(^|\/)(auth|payment)[\w-]*\/`.

#### L6 — Rows are appended at end of file, not below the marker

`.claude/hooks/record-task-activity.mjs:119` vs its comment at `:78-79`; `.claude/tasks/_TEMPLATE.md:8-11`. The marker is used as an opt-in flag; the write is `appendFileSync` to EOF. It works only because the template says the Activity log must stay last, which nothing checks. A record where someone adds `## Completion Summary` after the log — the natural thing when a PR merges — collects table rows after that prose. Insert after the marker's table, or have the gate assert the log is the last section; also prepend a newline when the file does not end with one.

#### L7 — `git-workflow.md` describes the merge rule imprecisely

`docs/workflow/git-workflow.md:93-95`. "The guard checks where `HEAD` is rather than which branch name appears in the command" — the rule's `test` does both, and also matches `git checkout main && git merge ...` by command text. The behaviour is right; a reader trusting the sentence will think the compound checkout-and-merge is allowed.

## Invariant checklist

`CLAUDE.md` §6, for what this diff could affect. The change touches no PHP, no migration, no route and no domain code — `git diff origin/main...HEAD --stat` lists nothing under `apps/`. Every commerce invariant is therefore N/A, examined rather than ticked.

| Invariant | Status |
| --- | --- |
| Money is integer đồng, never float | N/A — no money code in the diff |
| Price, modifiers, menu schedule and availability revalidated server-side | N/A — no API code in the diff |
| Checkout idempotent, key persisted with its result | N/A — SCP-002 plans it; no code here |
| Overselling prevented by row lock or conditional update, not Redis alone | N/A |
| Order lines hold immutable snapshots | N/A |
| Order state and payment state are separate machines | N/A |
| Business state and outbox event written in one transaction | N/A |
| Admin path checks permission **and** store scope | N/A — no routes added |
| Cross-store resource answers 404, not 403 | N/A |
| Domain layer free of Eloquent, facades and HTTP | N/A — no `apps/api/app/` change |

Two §7/§9 rules the diff does engage, and engages correctly: `migrate:fresh`/`migrate:refresh`/`db:wipe` are refused by a hook (modulo M7), and production migrations and production-stack commands are refused except in the ambient-environment case, which no command string can detect and whose comment says so.

## Not defects

- **`.env.backup`, `.env.ci` and plain `.sql` dumps missing from `FORBIDDEN_PATHS`.** Looks like a gap; `.gitignore:34` (`.env.*`) and `:54` (`infra/backups/`) already keep them out of `git add -A`. Verified with `git check-ignore -v`.
- **`force-push` blocking `--force-with-lease`.** Deliberate and documented at `:61`; a lease still rewrites a branch someone else may hold.
- **`production-migration` not firing on a bare `php artisan migrate --force`.** Unavoidable — the environment is ambient, not in the command string. The rule's comment says so and `migrate --force` is an explicit allow fixture.
- **One Node start per Bash call across three hooks.** The F1 note weighs this correctly: a prefix matcher cannot see `git add -A && git commit ...`, and milliseconds are the right price for closing a silent gap.
- **`SCP-000.md:197` saying "Status stays `IN_PROGRESS`" while the header says `REVIEW`.** Reconciled by the dated section at `:207-223`; keeping superseded reasoning visible is a property of this ledger, not drift. The *index* row in L2 is a different file and is genuinely stale.
- **AC8, AC10 and the two non-conforming commit subjects.** Known, recorded, and visible in the gate's own output; not re-raised. Note that M2 and M3 are code defects in the recorder independent of AC8's configuration cause — they will still be there after the fresh-session check passes.

## Independent verification by the calling session

| Claim | Result |
| --- | --- |
| H1 — compound idioms blocked | all five reproduced: `BLOCK(skip-hooks)`, `BLOCK(skip-hooks)`, `BLOCK(force-push)`, `BLOCK(delete-remote-branch)`, `BLOCK(force-push)`. Controls `git commit -nm x` and `git push -fu origin main` still block, so a narrowing must not undo F2 |
| H2 — template ships a clean review | `_TEMPLATE.md` ships `\| CRITICAL \| 0 \| \|` and `\| HIGH \| 0 \| \|`. Confirmed |
| M7 — `migrate:reset` | allowed, while `migrate:refresh` blocks. Confirmed |
| M10 — on `main` | `git commit -m "x [SCP-1]"` and `git push` both allowed with `branch='main'`. Confirmed |
| M4 — unscoped severity regex | the regex is unscoped as described; in `SCP-000.md` today the first `\| CRITICAL \|` row (line 235) falls *after* `## Review Findings` (line 225), so the defect is real but not currently triggered in this record |

H1 is a direct consequence of the fix round recorded in `.claude/tasks/SCP-000.md`. That round widened these three patterns to close F2 — accident shapes passing — and in doing so introduced the opposite failure, ordinary commands blocked, which is the same finding one layer up. The fix round's own verification ran only single commands, so it could not see it.
