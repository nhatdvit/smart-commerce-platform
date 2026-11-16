# Code review — SCP-000 (second pass)

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-16 |
| **Reviewer** | `code-reviewer` agent |
| **Diff** | `git diff origin/main...HEAD`, 43 files, +6267/-1; this pass concentrates on `since-review.patch`, 21 files, +852/-113 |
| **Verdict** | CHANGES REQUESTED |

> Transcribed by the calling session from the agent's returned report, per
> `.claude/skills/review/SKILL.md`. The agent holds `Read`, `Glob`, `Grep` and
> no `Bash`; the probes it asked for were run by this session and the output
> sent back before the report was finalised.

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 2 | yes |
| MEDIUM | 9 | no |
| LOW | 7 | no |

Fix rounds 2 and 3 close the six blocking findings in substance: the segment split ends the `[^\n]*`-crosses-`&&` class, two new rules close the direct road to the trunk, the `.env` rules judge a target instead of a position, the secret scan reads what the commit will include, the gate asks for substance rather than existence, and the three reviewers hold `Read`/`Glob`/`Grep` — verified against the frontmatter, not against the prose. Two fixes overshot in the same direction. The widened `ASSIGNMENT` regex flags **eleven lines that exist in this repository today**, measured, including both `.env.example` files, both `next.config.mjs` files, the CI cache keys, `pnpm-lock.yaml` and the `api-contracts` header constants (H1); and the fixture exemption does not cover `.claude/reviews/`, so a review report cannot quote the string it is reporting on (H2). Both are the failure mode this diff's own H1 rationale names as unsafe — "blocking ordinary work … teaches people that the guard is noise, and then it protects nothing."

**Provenance, since this review had no shell.** Every finding was derived by reading: regexes evaluated by hand against literal inputs, candidate lines located with `Grep`. The calling session then ran four probes at the reviewer's request; **the results below are its measurements, not the reviewer's.** That session is also the one that made the fixes, so the evidence is from an interested party — the trade `review/SKILL.md` records. Nothing here is reported from a command whose output the reviewer did not see. No `/code-review` skill is installed (`.claude/skills/` holds eight, none of them `code-review`), and with no `Bash` or skill-invoking tool it could not have run one; **no automated pass ran**, and no finding below comes from one.

**A note on this report's own text.** H2 means a report quoting a credential-shaped string cannot be committed. Rather than weaken the guard to publish this, every such string below is split — key in one cell or code span, value in another — so no `key`+separator+value is contiguous on any source line. No evidence is lost; the tables always carried both halves. Two strings are left contiguous deliberately, in **Not defects**, because the placeholder logic waives them and that is worth demonstrating.

## Did it implement what was approved?

Fix round 2 and fix round 3 are implemented as recorded, with these deltas:

- **S4/M6 is genuinely closed.** `tools: Read, Glob, Grep` in all three reviewer frontmatters; `docs/workflow/README.md`'s new Tools column matches all nine definitions (`task-manager`, `software-architect`: `+ Write, Edit, Bash`; `laravel-developer`, `nextjs-developer`: no `tools:` key, i.e. the default set; `qa-engineer`, `git-release`: `+ Bash`).
- **`/review` does write the file it promises**, `.gitignore` ignores it, and the ask-for-a-command protocol is stated in all three agents and the skill. It is followable — this review followed it, four times.
- **The documentation is not all true yet.** `security-reviewer.md:10` still says "You do hold Bash" eleven lines above "You have no `Bash`", and `:100` sends it to a `/security-review` skill this repository does not contain (M9). `quality-gates.md:15` still calls `.env` writing a hard block when three ordinary shapes get through, measured (M3). The self-test claim at `:136-139` is falsified by H1 (L5).
- **Not re-reported, per the brief:** S6, S8, S9, S10, M2, M3, the prior LOW list, AC8, AC10, and the two commit subjects the Tech Lead kept — which account for three of the four problems the gate still reports.

Nothing in the diff touches application code: no PHP, no migrations, no frontend source. The `CLAUDE.md` §6 invariants are not reachable by it.

## Findings

### CRITICAL

None. Nothing here can lose data, move money or cross a store boundary.

### HIGH

#### H1 — The widened `ASSIGNMENT` regex refuses commits to eleven lines that already exist in this repository

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:91`, and the value floor lowered from 8 to 5 |
| **Category** | correctness |
| **Source** | manual review; **confirmed by probe 1, run by the calling session: 11 of 11 BLOCK** |

**What is wrong.** The key side gained `token` and bare `key` plus an arbitrary prefix chain `(?:[A-Za-z0-9]+[_-])*`; the value floor fell to 5; and the value class `[^'"\s,;)]{5,}` accepts `{`, `}`, `$`, `(`, `.`, `-` and `|`. It now matches ordinary identifiers and ordinary data. The only allow fixture defending bare `key` is `const monkey = computeTurkey(value)`, which tests the prefix-separator rule and nothing else, and the "clean" cases are all hand-written strings — `.env.example` is asserted with `diff: ''`, so its real contents are never scanned. That is why this shipped under a green suite.

**Failure scenario.** Probe 1 fed one real line per file to `scanDiff`. All eleven returned a finding, so `main` exits 2 and the commit is refused:

| File | Key, as written | What matched | Value captured | Probe 1 |
| --- | --- | --- | --- | --- |
| `.env.example`, `apps/api/.env.example` | `AWS_SECRET_ACCESS_KEY` then `=` | `AWS_SECRET_ACCESS_` + `KEY` | `minioadmin` | BLOCK |
| `.github/workflows/ci.yml` | `key` then `:`, in the `actions/cache` step | `key` | `composer-${{` | BLOCK |
| both `next.config.mjs` | `key` then `:`, in the headers array | `key` | `X-Content-Type-Options` | BLOCK |
| `packages/api-contracts/src/headers.ts` | `HEADER_IDEMPOTENCY_KEY` then `=` | `HEADER_IDEMPOTENCY_` + `KEY` | `idempotency-key` | BLOCK |
| `pnpm-lock.yaml` | `path-key` then `:` | `path-` + `key` | `3.1.1` | BLOCK |
| `apps/api/tests/Feature/IdempotencyTest.php` | `'key'` and `'Idempotency-Key'` then `=>` | `key` | `key-aaaaaaaa` | BLOCK |
| `apps/api/tests/Unit/SensitiveDataRedactorTest.php` | `'token'` then `=>` | `token` | `secret-value` (`secret` is exact-match only now) | BLOCK |
| `infra/scripts/bootstrap-dev.sh`, `.github/workflows/ci.yml` | `APP_KEY` inside a `sed` substitution | `APP_` + `KEY` | the replacement text | hand-evaluated |

So: a developer bumps a dependency and `pnpm-lock.yaml` re-adds the `path-key` line, or adds a security header, a cache step, or an idempotency test, then runs `git add -A && git commit -m "chore(deps): bump [SCP-0NN]"`. The hook refuses it and prints *"the pattern needs widening in `.claude/hooks/guard-secrets.mjs`"* — the remedy that degrades the control, which is what S11 objected to.

Forward-looking, in the stack this repository is about to write: a TypeScript field named `token` annotated `string` (value `string`, six characters); JSX `key` bound to `{product.id}`; a Zod field named `password` built with `z.string().min(8)`; PHP `$key` assigned `'idempotency:'` concatenated with a hash. The first `/implement` on SCP-002 hits the last of those.

**What to change.** Narrow the *value*, not only the key, and give the suite real fixtures:

1. Require a credential-shaped value: a quoted literal, or ≥12 characters containing both a letter and a digit. Reject a bare type name (`string`, `number`, `bool`), a version (`^[\d.]+$`), and anything beginning `{`, `$`, `process.env`, `config(`, `env(` or `import.meta`.
2. Drop bare `key`, or require a known credential prefix before it (`api`, `private`, `secret`, `app`, `aws`, `access`). A YAML mapping key and a JSX prop cannot be told from a credential by name alone.
3. Keep the 5-character floor only for quoted literals; restore 8 for unquoted.
4. Add allow fixtures for every row above, plus one fixture that reads the real `.env.example`, `apps/api/.env.example`, `.github/workflows/ci.yml` and both `next.config.mjs` from disk. The repository's own files are free fixtures, and a suite that never samples them cannot catch a false positive — which is the whole reason this round shipped with eleven.

#### H2 — The fixture exemption covers the guard but not the review reports, so the evidence the gate demands cannot be committed

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:185` (`FIXTURE_FILES`), `:211` |
| **Category** | correctness |
| **Source** | manual review; **confirmed by probe 1 row 11, and by this report** |

**Re-graded from MEDIUM to HIGH** after the probe. It is no longer a prediction: the artefact it blocks is one `check-task-traceability.mjs` requires to exist, and it was hit by the first review after the change rather than by an unusual one. The reports already in the tree committed only because of the S2 defect this round fixed, so review reports are now systematically uncommittable, not occasionally.

**What is wrong.** `FIXTURE_FILES` exempts `selftest.mjs` and `guard-secrets.mjs` from the content rules. It does not exempt `.claude/reviews/**`, which is where `/review` step 5 requires reports to be transcribed, and where a reviewer must quote the credential-shaped line that demonstrates a defect.

**Failure scenario, observed.** Probe 1 fed the line from the previous `security-review.md` — key `MAIL_PASSWORD`, assigned a sixteen-character fixture — attributed to `.claude/reviews/SCP-000/code-review.md`. Result: BLOCK, matched word `PASSWORD`. So the session that saves this report and commits it is refused, and told to widen the pattern. The same loop S11 described, one directory over.

**What this report had to do.** Rather than weaken the guard to publish, every credential-shaped string above and below is split across cells or code spans so that no `key`+separator+value is contiguous on a source line. The information is intact; the record simply shows that a report about this guard had to be written around it.

**What to change.** Not a per-line marker — it would have to appear inside every table row, and it was the wrong suggestion. Not a blanket exemption for `.claude/reviews/` either, for a reason worth stating: a review report is precisely where a reviewer pastes a credential **actually found in the diff**, and that is the one case where a content rule earns its keep in a `.md` file. Instead:

1. A **region marker** — an HTML comment opening a fixtures region and another closing it — honoured by `scanDiff` while a region is open. One pair per table or block, visible in review, and the rest of the file stays scanned.
2. Add a fixture shaped like a report line, inside and outside a region, so the next round cannot reintroduce this.
3. Until then, splitting the key from the value in the report text is enough and needs no change to the guard.

### MEDIUM

#### M1 — The segment split is quoting-naive, and the comment asserting it is harmless is false

**Where** `guard-protected-ops.mjs:63-79` (the claim), `:297` (the split). **Source** manual review; **confirmed by probe 2: ALLOWED**.

The comment states that a separator inside a quoted string "cannot let a dangerous command through that a whole-line match would have caught, because that command is still wholly inside one piece". That holds only when the verb and the flag fall on the same side of the separator.

`git commit -m "chore: tidy; drop dead code" --no-verify` on `feature/x` → **ALLOWED**. `SEGMENTS` splits at the `;` inside the message; segment one has `git commit` and no flag, segment two has `--no-verify` and no `git commit`, and `skip-hooks` needs both. The whole-line match blocked this before the round. `git push origin "a;b" --force` → **ALLOWED** by the same mechanism.

The honest caveat: anyone writing `--no-verify` or `--force` is acting deliberately, and this guard documents itself as raising the cost of accidents, not intent — the bypass alone is close to LOW. It is MEDIUM because the comment records a safety property the code does not have, and the next person to add a rule will build on it.

Mask quoted spans before splitting, or stop denying the limitation in the comment and pass the whole line to `skip-hooks`, `force-push` and `delete-remote-branch` the way `production-migration` already receives it. Add both probe-2 commands as MUST_BLOCK fixtures either way.

#### M2 — `production-stack` needed the whole line and did not get it

**Where** `guard-protected-ops.mjs:239-243`. **Source** manual review; **confirmed by probe 2: ALLOWED**.

`production-migration` reads `APP_ENV=production` and `compose.prod` from the whole command because the environment can be set in an earlier segment. `production-stack` matches one segment only, and `COMPOSE_FILE` is the documented way to name a compose file out of band.

`export COMPOSE_FILE=infra/compose.prod.yaml && docker compose up -d` → **ALLOWED**. Segment one holds the file and no verb; segment two the verb and no file. Blocked before the split. The outcome is an agent starting the production stack, the one thing `CLAUDE.md` §13 names as requiring explicit authorization.

Give it `test(segment, branch, whole)`: `compose.prod` in `whole`, the verb in `segment`.

#### M3 — The `.env` target rules miss absolute paths and any verb that is not segment-initial, while the docs still call it a hard block

**Where** `guard-protected-ops.mjs:50` (`DOTENV_FILE`), `:258` (the anchor), `docs/workflow/quality-gates.md:15`. **Source** manual review; **confirmed by probe 2: all three ALLOWED**.

`DOTENV_FILE` is anchored so the first path element must start with a word character: absolute, `~` and `$HOME` paths all fail, as does a Windows `apps\api\.env`. The indirect rule is now anchored at the start of the segment, which the old pattern was not, so any wrapper before the verb defeats it. The comment claims coverage of "a real `.env`, in any directory", and `quality-gates.md:15` records "hard block".

Probe 2, three shapes, all **ALLOWED**: `echo "APP_KEY=x" > /var/www/html/.env`; `sudo cp .env.example apps/api/.env`; `docker compose exec -T api cp /tmp/env .env`. Each overwrites a developer's `APP_KEY`, the loss the fix comment itself describes as making every value encrypted with it unreadable.

Allow a leading `/`, `~/` and `./` in `DOTENV_FILE`; normalise `\` to `/`; match the verb as the segment's command word after stripping known wrappers (`sudo`, `env X=1`, `time`, `docker … exec …`) instead of demanding position 0. Change `quality-gates.md:15` to partial with the list of what is not covered.

#### M4 — `push-to-protected` misses `git push origin HEAD`, the full refspec, and a quoted branch name

**Where** `guard-protected-ops.mjs:155-169`. **Source** manual review; **confirmed by probe 2: all three ALLOWED**.

The third arm — "no branch argument at all while HEAD is on a protected branch" — is gated on `named`, which is true as soon as two non-flag arguments appear. `HEAD` is such an argument while *meaning* "whatever branch I am on".

HEAD on `main`, the case `commit-on-protected` exists for: `git push -u origin HEAD` → **ALLOWED**. The flag group takes `-u`, then `origin`, then `HEAD`, so `named` is true and the protected arm is skipped. This is the idiom the repository prescribes and the suite asserts must be allowed. The push lands on `origin/main` with no pull request — S3's outcome by a different road. Also **ALLOWED**: `git push origin HEAD:refs/heads/main` and `git push origin "main"`.

Resolve the refspec before testing: strip quotes, take the text after the last `:`, strip `refs/heads/`, and treat a destination of `HEAD` or no destination as "the branch HEAD is on".

#### M5 — `stages` detection is wrong in both directions, and the working-tree scan reads files the commit will not include

**Where** `guard-secrets.mjs:316-320` (`stages`), `:261-286`. **Source** manual review — hand-evaluated, not probed.

`stages` is `git` anywhere, then the *word* `add` anywhere, and it ignores the pathspec in both branches.

**Over-detection.** `git commit -m "feat(menu): add schedule window [SCP-0NN]"` with one file staged: the word "add" in the subject sets `stages`, so the scan reads the entire working tree, including untracked files that are no part of this commit. Any credential-shaped line in any of them refuses a commit that does not contain it, naming a file the developer never touched. "add" is the commonest verb in a Conventional Commit subject.

**Under-detection.** `git commit apps/api/config/services.php -m "…"` with nothing staged commits the working-tree version of that path without an index entry. `stages` is false, `scanIndex()` reads an empty index, and the credential commits. That is S1's failure in a shape the fix does not cover.

**Ignored-file bypass.** `git add -f .env && git commit -m "…"`: `scanWorkingTree` runs, and `git ls-files --others --exclude-standard` omits `.env` because it is gitignored — so `FORBIDDEN_PATHS`, the one rule whose whole job is "`.env` must never be committed", never sees the name.

Derive the set from the command; tighten `stages` to the subcommand position so a commit message cannot set it. The durable answer remains the real `pre-commit` hook.

#### M6 — `scanDiff` judges a line by its first assignment only, so a placeholder waives the rest of the line

**Where** `guard-secrets.mjs:223-228`. **Source** manual review; **confirmed by probe 5: zero findings**.

`ASSIGNMENT.exec(added)` takes the leftmost match and the code does nothing further with the line. If that first match is waived by `looksLikePlaceholder`, every later assignment on the same line is never examined.

Probe 5 fed one line to `scanDiff`: a `docker run` invocation passing an interpolated API key followed by a real-looking database password. **Zero findings.** The leftmost match is the interpolated value, which begins `${` and is therefore a placeholder, so the line passes and the password goes to history unflagged. This is a silent miss, which is worse than H1's noisy one: nothing tells anybody it happened.

Make `ASSIGNMENT` global and iterate with `matchAll`, flagging when **any** match has a non-placeholder value. Add a two-assignment fixture, placeholder first.

#### M7 — An unedited template copy satisfies both new evidence checks

**Where** `check-task-traceability.mjs:183-195`. **Source** manual review; **confirmed by probe 3: 2377 characters, Verdict matched**.

The two checks are "trimmed length ≥ 400" and "contains a Verdict". The template is 2377 characters and carries a Verdict row. Copying it into a task's review directory passes both with no review performed. The old gate accepted `touch`; this one accepts the blank template, a more convincing artefact recording exactly as much. A length floor is the wrong shape of check: it measures volume, the one thing a stub has for free.

Check the answers. The Verdict cell must name exactly one of `READY` / `CHANGES REQUESTED` / `BLOCKED`; each severity count must be an integer; unsubstituted placeholders must be absent; and the file must differ from the template by more than its header.

#### M8 — The acceptance-criteria check fails open on a missing section, an unbolded criterion, and a substring collision

**Where** `check-task-traceability.mjs:202-216`. **Source** manual review — hand-evaluated against `SCP-000.md`; consistent with probe 4, which raised no acceptance-criteria problem.

Three gaps. (a) When the section is missing or spelled differently, the loop silently passes — unlike the findings-section check twelve lines above, which fails loudly. (b) The matcher demands emphasis, so `- [ ] AC11 — …` is invisible. (c) `results.includes(unticked[1])` is a substring test.

For (c): twelve criteria, `AC1` unticked, `Test Results` saying "AC10 could not be verified on this hardware". `results.includes('AC1')` is true because `AC1` prefixes `AC10`, so the gate says nothing and `AC1` reaches a pull request unverified and unmentioned. This record is one criterion away from that shape: AC8 and AC10 are both unticked and both named.

Fail when the section is missing or holds no checkbox; accept `AC\d+` with or without emphasis; match with a word boundary.

#### M9 — `security-reviewer.md` contradicts itself about `Bash` and sends a tool-less agent to a skill this repository does not have

**Where** `.claude/agents/security-reviewer.md:10` and `:100-101`. **Source** manual review — verified by reading the file and globbing `.claude/skills/`.

Line 10, untouched by the round, reads *"You do hold Bash: that is for reading the diff and running probes."* Line 4 grants `Read, Glob, Grep`; lines 18-19 say it holds no `Bash`. Line 100 sends it to a `/security-review` skill that does not exist here. This is M5's defect fixed in `code-reviewer.md` and `review/SKILL.md` and left standing in the one file that also claims a tool it does not have.

The reviewer reads top-down, believes line 10, and either burns a turn on an unavailable tool — concluding the environment is broken rather than the prompt wrong — or, expensively, satisfies line 100 by describing what a pass would have found. The round's entire purpose was that a claim in a prompt must match the grant.

#### M10 — merged into M6; number retired to keep cross-references stable

### LOW

#### L1 — A duplicate clean fixture, recreating the double count M8 was raised for

`selftest.mjs:287-293` and `:349-355` are byte-identical inputs. The round that removed the duplicated `docker compose down` assertion *because* it made the headline count double-count one rule added a new duplicate in the same file. Probe 4 measured **117 passed, 0 failed**, so the honest count is **116 distinct assertions**.

#### L2 — The canonical list of refused operations was not updated for the new rules

`docs/workflow/git-workflow.md:81-91` omits `push-to-protected` and `commit-on-protected`, both added this round, and `quality-gates.md:12` still lists the destructive migrations without `migrate:reset`. `commit-on-protected` is the one most likely to surprise, because it fires on a bare `git commit`.

#### L3 — Tool-less agents are told to "run" a skill they have no tool to invoke

`code-reviewer.md:87-93` and `security-reviewer.md:100` say to run a review skill "if available". With no `Bash`, `Skill` or `SlashCommand` tool it is never available. Reword to "ask the calling session to run it and send you the output", matching `review/SKILL.md`.

#### L4 — `commit-on-protected` refuses every commit on a local `master`, including a repository's first

`master` is git's default without `init.defaultBranch`. In a fresh clone or bootstrap the first commit — before any task exists — is refused with advice that cannot be followed. Add an exception for an unborn HEAD, or a sentence in `git-workflow.md`.

#### L5 — `quality-gates.md` claims the self-test proves the repository's own placeholders do not trip the scanner

`:136-139` claims the suite proves this. `.env.example` is asserted with `diff: ''`: path rules only, contents never read. Probe 1 shows one of its lines now trips. Make the claim true per H1 item 4, or narrow it to the four inline strings it actually covers.

#### L6 — The `base64:` placeholder path uses `includes` for markers documented as exact

`guard-secrets.mjs:161` uses a substring test on decoded bytes, while the `PREFIX_MARKERS`/`EXACT_MARKERS` split exists precisely to end one. The risk is negligible — about 1e-8 for a random 32-byte key to contain `test` — but the inconsistency will be copied the next time this function is extended.

#### L7 — A line beginning `++ b/` in an untracked file becomes a diff header and can reach the fixture exemption

`guard-secrets.mjs:278-282` prefixes every body line with `+`. A body line beginning `++ b/` becomes a header, `currentFile` is re-pointed, and if the path is a fixture file the content rules are skipped for every following added line. Deliberate exploitation is an act this guard does not price for, hence LOW; the accidental variant misattributes findings whenever an untracked file holds such a line — a patch of a patch.

## Invariant checklist

`CLAUDE.md` §6. The 43 files are `.claude/`, `docs/`, `infra/scripts/`, `CLAUDE.md`, `.gitignore`, `.prettierignore` and `package.json` — no PHP, no migrations, no frontend source — so no business invariant is reachable.

| Invariant | Status |
| --- | --- |
| Money is integer đồng, never float | N/A — no money code in the diff |
| Price, modifiers, menu schedule and availability revalidated server-side | N/A |
| Checkout idempotent, key persisted with its result | N/A |
| Overselling prevented by row lock or conditional update, not Redis alone | N/A |
| Order lines hold immutable snapshots | N/A |
| Order state and payment state are separate machines | N/A |
| Business state and outbox event written in one transaction | N/A |
| Admin path checks permission **and** store scope | N/A |
| Cross-store resource answers 404, not 403 | N/A |
| Domain layer free of Eloquent, facades and HTTP | N/A |
| *(workflow)* Review cannot be performed by whoever can change the code | PASS — structural; `tools: Read, Glob, Grep` verified in all three reviewer frontmatters |
| *(workflow)* A gate's claim matches what it enforces | PARTIAL — M3, M7, M8, M9, L5: five places where the text is still ahead of the code |

## Not defects

- **The split did not break `merge-into-protected`.** Probe 2's control returned `merge-into-protected` for `git checkout main && git merge feature/x`: segment one matches no rule, the branch tracker moves `standingOn` to `main`, segment two is judged standing on it.
- **`git commit -m "chore: cp .env.example .env"` is correctly allowed now.** The anchor that causes M3 also kills a real false positive in the old unanchored rule. Strip wrappers; do not drop the anchor and restore the false positive.
- **The placeholder waivers that work, quoted contiguously on purpose.** The CI `APP_KEY`, a `base64:` value whose bytes decode to text containing `changeme`, is waived by the base64 branch; `POSTGRES_PASSWORD=change_me_local_only` is waived by the `change` prefix marker, with `_` as its boundary. Both are safe to commit in this report, which is a live check that the two branches behave. Note that `change_me_in_local` in `EXACT_MARKERS` never fires on the real file — harmless, but dead weight.

  > **Correction by the transcribing session, 2026-11-16.** The reviewer wrote
  > the `APP_KEY` example as a contiguous `key: value` inside Markdown
  > backticks, on the stated grounds that the waiver makes it safe to commit.
  > It does not. Measured: the bare string is allowed, the same string wrapped
  > in backticks is **blocked**. The value character class `[^'"\s,;)]{5,}` does
  > not exclude a backtick, so the captured value ends with one, and the
  > `base64:` test is anchored with `$` — `looksLikePlaceholder` returns `true`
  > for the bare value and `false` for the backticked one. The example has been
  > reworded here to keep the report committable. This is H2 and H1 compounding
  > in the one place the report claimed to be safe, and it is worth recording
  > because the reviewer could not run the check itself.
- **`docs/workflow/README.md`'s Tools column is accurate.** All nine `tools:` lines checked, including the deliberate absence of the key in the two developer agents, which the table renders as "default set".
- **The new gate checks cannot crash it.** `readFileSync` has its own failure path; the section extractions all use `?.[1] ?? ''`; `report()` is a hoisted declaration. A malformed record produces a failure or a no-op, never a throw. Probe 4 ran the gate against this branch: 4 problems, all expected — status `REVIEW`, and three complaints about the two commit subjects the Tech Lead chose to keep. No findings and no evidence complaints, so the M4 severity scoping and both reports satisfy it.
- **Reports transcribed by the calling session** — usually the session that wrote the code — is a real weakening of evidence independence, and this review is an instance of it: the five probes were run by that session, not by the reviewer. It is the Tech Lead's recorded trade and it is stated rather than hidden.

## Commands run

Run by the calling session at the reviewer's request, output seen before this report was finalised:

1. **`probe-secrets.mjs`** — eleven real lines through `scanDiff`. **11 of 11 BLOCK**, matched words `KEY`, `key`, `key`, `KEY`, `key`, `key`, `token`, `key`, `token`, `key`, `PASSWORD`. Establishes H1 and H2.
2. **`probe-ops.mjs`** — nine commands plus a control through `evaluate()`. **9 of 9 ALLOWED**, control blocked with `merge-into-protected`. Establishes M1, M2, M3, M4.
3. **Template measurement** — `2377` characters, Verdict regex `true`. Establishes M7.
4. **Baseline** — `pnpm claude:selftest` → 117 passed, 0 failed. `check-task-traceability.mjs --task SCP-000` → 4 problems. Supports L1 and the Not-defects note.
5. **`probe-m6.mjs`** — one `docker run` line carrying an interpolated key and a real-looking password. **Zero findings.** Establishes M6.

---

**Verdict: CHANGES REQUESTED.** Two HIGH block the pull request. H1 is narrow to fix — tighten the value side, drop or qualify bare `key`, and add the repository's own files as allow fixtures — and it should be fixed before this guard meets application code, because every false positive it produces is answered by a message telling the developer to weaken it. H2 blocks the commit of this report and wants the region marker rather than a directory exemption, since a review report is exactly where a real found credential gets pasted. Of the nine MEDIUM, take M5, M6 and M9 in the same round: M5 leaves S1's hole open in two further command shapes, M6 is a silent miss rather than a noisy one, and M9 is two false claims in the file this round rewrote for honesty. Everything measured is marked as such, and the measurements were taken by the session that made the fixes.
