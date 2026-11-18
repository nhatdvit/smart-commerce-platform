# Code review — SCP-000

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-18 (third pass) |
| **Reviewer** | `code-reviewer` agent |
| **Diff** | `git diff origin/main...HEAD`, 43 files; this pass concentrates on `since-review.patch` (`f7ad280..HEAD`), 4 files, +387/-481 |
| **Verdict** | CHANGES REQUESTED |

> Transcribed by the calling session from the agent's returned report, per
> `.claude/skills/review/SKILL.md`. The agent held `Read`, `Glob`, `Grep` and no
> `Bash`; it reported READY with nine MEDIUM **and one escalation condition**,
> and asked for five probes. The calling session ran them afterwards. **The
> escalation condition was met**, so M3 is HIGH and the verdict here is
> CHANGES REQUESTED rather than the agent's READY — the agent set that test
> itself, before seeing the answer.

## Summary

| Severity | Unresolved | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 2 | yes |
| MEDIUM | 7 | no |
| LOW | 5 | no |

**Three of the four HIGH from pass 2 are closed.** S15/H1 is closed by grading
rather than by the remedy this reviewer suggested, and the grading is the better
answer: tightening the value side keeps `MAIL_PASSWORD=Pr0d-2026-Xq` blocking
(measured), which making all content rules advisory would have given up. H2 is
closed. S16/M5 is closed in the part that mattered.

**Fix round 4 introduced four new defects and left two residues**, which is the
established pattern and what this pass was for.

## Did it implement what was approved?

Fix round 4 is implemented as the ledger records it, with three deltas worth
naming.

- **The graded split is a design change, documented nowhere outside the code and
  the ledger.** AC6 still reads "`guard-secrets` blocks a commit containing a
  credential" and is ticked; `quality-gates.md` lists three limits and not the
  new biggest one. The split was sanctioned by pass 2, so this is not an
  unapproved change of direction — it is an approved change whose documentation
  and acceptance criterion were not updated with it (M5, and S33 in the security
  report).
- **`quality-gates.md:34-36` describes a mechanism that no longer exists:** "the
  scan now reads the working tree, not the index, whenever the command will stage
  files". Round 4 deleted the `stages` heuristic; both views are always scanned.
- **Every caller of `scanDiff` handles both halves.** Verified with `Grep`: the
  only consumers are `selftest.mjs` (five sites) and `guard-secrets.mjs:442-446`.
  Nothing reads the old array shape, so nothing silently receives an object where
  it expected a list, and the advisory half is not dropped *inside* the program.
  Whether it is dropped on the way out is M3.

Scope confirmed unchanged: `.claude/`, `docs/`, `infra/scripts/`, `CLAUDE.md`,
`.gitignore`, `.prettierignore`, `package.json`. No PHP, no migration, no frontend
source, so no `CLAUDE.md` §6 invariant is reachable.

**No automated pass ran.** `Glob` over `.claude/skills/*/SKILL.md` returns eight
skills and no `code-review`; with no `Bash` or `Skill` tool the agent could not
have invoked one. No finding here comes from one.

## Findings

### HIGH

#### M3 — the advisory half is written to stderr on an `exit 0` path, and reaches nobody

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:448-458` |
| **Category** | error-handling |
| **Source** | manual review; the agent flagged it as "the one finding I most want a probe for" and stated the escalation condition |

The whole safety argument for narrowing the generic rule is "anything this
rejects is still reported as advisory, so nothing goes unseen"
(`guard-secrets.mjs:183`). The report is `process.stderr.write(...)` then
`process.exit(0)`.

The agent's stated condition: *"if the probe shows that an `exit 0` hook's stderr
reaches neither the user nor the model, this finding is HIGH and this task
returns to CHANGES REQUESTED — because S15's fix would then have removed the
generic rule rather than graded it, silently."*

**Probe result (calling session).** The hook with one advisory-only line in the
working tree: **311 bytes on stderr, stdout empty, exit code 0.** The hook
reference for this build: *"Claude never sees stderr from a hook that exits 0. To
read it yourself on events such as `PreToolUse`, enable debug logging."*

So the condition is met. **Failure scenario, measured:**
`MAIL_PASSWORD=$3cureP4ss` in a tracked file, then `git add -A && git commit`.
`ASSIGNMENT` matches, `looksLikePlaceholder` is false, `looksLikeCredentialValue`
returns false at the leading-`$` test, the finding goes to `advisory`, and the
commit proceeds with the note going to the debug log. Before round 4 this exact
line was blocked.

Mitigating, and why this is HIGH and not CRITICAL: probe D measured all 27 current
advisory findings and **every one is a false positive from a generic key**. The
channel is empty today, so nothing has actually been lost.

**What to change.** `exit 0` with the documented JSON output — `systemMessage` for
the user, `hookSpecificOutput.additionalContext` for the model — rather than
stderr on a success path. Not exit 1: the reference labels it an error notice and
gives no guarantee the model sees it. Add a fixture asserting
`advisory.length > 0`, because nothing in the suite pins that the advisory half is
populated at all (L5).

#### M2 — a quoted branch name reaches the trunk, and S17/M4 is recorded as closed

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:155-177` |
| **Category** | correctness |
| **Source** | manual review, hand-evaluated; residue of this reviewer's pass-2 M4. **Raised from the agent's MEDIUM** |

Pass 2 listed three allowed shapes. Round 4 fixed two and the ledger records the
finding closed with "All six cases are in the suite". The quoted form was not
among them, and the module's `unquote()` helper is not used by this rule.

**Probe result (calling session).** `git push origin "main"` and
`git push origin 'main'` on `feature/x` both return `null` — allowed. The security
reviewer independently enumerated seven more spellings and all were measured
allowed; see S26 in `security-review.md` for the full table. Severity raised to
match the rating S17/M4 carried, because this is the same defect reaching the same
outcome: the trunk moves with no pull request, and `main` has no branch
protection.

**What to change.** The destination resolver described in S26. One helper closes
M1, M2 and S35 together.

### MEDIUM

- **M1 — `push-to-protected` refuses pushing to a *feature* branch while HEAD is
  on the trunk.** `:175`. The `:` alternative in `/^\+?(?:HEAD|@)(?::|$)/` makes
  the test true for any refspec starting `HEAD:`, so the destination is never
  read. Measured: `git push origin HEAD:feature/SCP-002-cart` on `main` is
  blocked. That is the documented recovery from landing on the trunk. The suite
  pins the right command on the wrong branch (`selftest.mjs:194`).
- **M4 — the new `npm auth token` shape hard-blocks the documented safe form.**
  `:59-64`. `CREDENTIAL_SHAPES` is blocking and **not** placeholder-checked, so
  measured: `//registry.npmjs.org/:_authToken=${NPM_TOKEN}` blocks. That is the
  officially recommended line. Latent today — the tracked `.npmrc` holds
  resolution settings — but it is S15's failure one tier up, in the tier with no
  advisory escape. Require a literal, or route this shape through
  `looksLikePlaceholder`.
- **M5 — what the grading lets through, and neither AC6 nor the docs say so.**
  `:185-218`. Measured advisory rather than blocking: all-letters values,
  letters-and-separators passphrases, values starting `$ % @ { < ( [` (including
  every bcrypt and argon2 hash), and any value containing `( ) | * \`. Keeping `/`
  and `+` out is right and should stay. Narrow the marker set to `${ {{ }} :: -> =>
  .* .+` and drop the bare `( ) | * \`, which cost real coverage for one `sed`
  expression and one `replace()` call the earlier tests already reject. See S28
  for the stronger fix: band on key confidence, which probe D shows costs nothing.
- **M6 — one untracked `.pem` blocks every commit in the repository.** `:442-443`,
  `:425`. Measured: `names = [localhost-key.pem, README.md]` yields a blocking
  finding. `.gitignore` does not cover `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`
  or `auth.json`. `mkcert localhost` produces exactly this. Aggravating: the
  command filter matches any Bash command containing both "git" and "commit", so
  `git log --grep=commit` and `rg "git commit" docs/` are refused too, with a
  message about a commit that is not happening. Name the real cause in the message,
  and add those extensions to `.gitignore` on their own merits.
- **M7 — the exempt directories waive the unmistakable-shape rules too, and the
  suite asserts that as desired.** `:327`; `selftest.mjs:329-337`. Measured: an
  `AKIA…` line under `.claude/reviews/` and under `.claude/tasks/` yields 0
  blocking and 0 advisory, while the same line elsewhere blocks. A review report is
  the one place a reviewer pastes a credential *actually found in the diff*. Test
  the exemption per tier and invert that fixture.
- **M8 — the ledger repair and the activity row.** The diff's last hunk removes a
  row recording commit `773a729` while the round-4 note says "the only line lost is
  the corrupted one itself". Both are true of different commits: the repair in
  `ed65476` lost only the corrupted line, and `fbbb131` removed the row separately
  **because it was false** — `773a729` is `main`'s commit, written by a direct hook
  test on 2026-11-11, not by this task. That reasoning is in `fbbb131`'s message
  but not in the round-4 note, which is the defect. Corrected in the round-3
  section of the ledger rather than by restoring a row that records something that
  never happened.
- **M9 — `security-reviewer.md` claims it holds `Bash` and points at a missing
  skill.** Confirmed independently by both reviewers. See S34.

### LOW

- **L1 — trunk patterns treat any branch *starting* with a trunk name as the
  trunk.** `TRUNK` ends in `\b`. Measured: `git push -u origin release/2026-11` on
  `release/2026-11` is refused, and `gh api /repos/o/r/git/refs/heads/main` — a
  GET, the ordinary way to read the trunk's SHA — is refused as a write. Anchor with
  `(?![\w./-])`.
- **L2 — `git-workflow.md:81-91` lists nine refused operations; `RULES` holds
  nineteen.** `trunk-write-api`, `push-to-protected` and `commit-on-protected` are
  absent — the last two being the most likely to surprise, because they fire on a
  bare `git push` and a bare `git commit`.
- **L3 — the blocking floor is 6 while the capture floor is 5**, so a
  five-character secret is permanently advisory. Round 2 lowered the capture floor
  to 5 to close a finding; round 4 reinstated 6 for blocking.
- **L4 — two stale JSDoc blocks**, one an orphaned `@returns {string[]}` left
  attached to nothing when the return shape became `{ blocking, advisory }`.
- **L5 — nothing in the suite asserts the advisory half is ever populated.** All
  assertions read `.blocking`. A future round that drops the advisory branch
  entirely passes green.

## Invariant checklist

The 43 files contain no PHP, no migration and no frontend source, so no business
invariant in `CLAUDE.md` §6 is reachable. The two workflow rows:

| Invariant | Status |
| --- | --- |
| Review cannot be performed by whoever can change the code | PASS — structural; `tools: Read, Glob, Grep` re-verified in all three reviewer frontmatters, and this pass ran under the real grants for the first time |
| A gate's claim matches what it enforces | **FAIL** — M3/S27 (the advisory claim is false), M5/S33 (the docs describe the pre-round-4 control), M9/S34, L2 |

## Not defects

- **S15/H1 is genuinely closed, and the approach beats this reviewer's
  suggestion.** Grading by value shape is the right axis: every one of the 33 false
  findings failed on the value. Probe D confirms the sweep at 0 blocking over 251
  files. The disagreement is about the price (M5), not the design.
- **H2 is closed and the path rules survived the exemption.** `isFixtureFile` is
  consulted only inside the diff loop, after the `FORBIDDEN_PATHS` loop over
  `names`, so a `.env` under `.claude/reviews/` still blocks — probe 4b confirms it.
- **S16/M5's main half is closed.** Scanning both views and unioning is right, and
  it removes the shell-string parsing that produced S1 and S5.
- **The `{ blocking, advisory }` split cannot smuggle a credential through as
  advisory.** The tiers are selected by rule, not by severity: `FORBIDDEN_PATHS`
  and all eight `CREDENTIAL_SHAPES` push to `blocking` unconditionally. M5 is about
  what `ASSIGNMENT` no longer blocks, which is a different claim.
- **Dedup across the two scans is correct**, through `new Set` on identical
  finding strings.
- **`git add -f .env && git commit` is still open and is not new.** Recorded so the
  next pass does not re-derive it.

## Verdict

**CHANGES REQUESTED.** The agent returned READY with nine MEDIUM and one
escalation condition; the probe met the condition, so M3 is HIGH, and M2 is raised
to HIGH on the measured bypass. Under this workflow's own rubric one HIGH means
`CHANGES_REQUESTED`.

Three things belong in the pull request description rather than in a finding: AC8
is still unverified from a session holding the work; the traceability gate is
unsatisfiable on this branch for two commit subjects, and the round was right to
leave the rule alone rather than weaken a control to let its author's branch
through; and squash-merging #36 resolves the second without touching the first.

The agent's recommendation, which the probes support: take M1, M2 and M4 in one
short round — three small edits in two files already open, two of them over-blocks
of the exact class this task has now re-learned three times, and M1, M2 and S35
share one fix.
