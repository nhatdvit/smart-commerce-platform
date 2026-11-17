# Security review — SCP-000

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-18 (third pass) |
| **Reviewer** | `security-reviewer` agent |
| **Why reviewed** | infra config: the two enforcement hooks, the CI merge gate, agent tool grants |
| **Verdict** | CHANGES REQUIRED |

> Transcribed by the calling session from the agent's returned report, per
> `.claude/skills/review/SKILL.md`. The agent held `Read`, `Glob`, `Grep` and no
> `Bash`, and ran nothing. Probes A, D, D2 and E below were run by the calling
> session **after** the report was returned, and they confirm the agent's
> predictions; that session also wrote fix round 4, so the evidence is from an
> interested party and is labelled where it appears.

## Summary

| Severity | Unresolved | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 2 | yes |
| MEDIUM | 8 | no |
| LOW | 4 | no |

Numbering continues from S25. S6, S8, S9, S10, S18–S25, AC8 and AC10 are
recorded open and not re-reported.

**What round 4 got right, first.** S15/H1 is genuinely closed, and closed better
than this reviewer's own recommendation: it asked for all content rules to be
made advisory, which would have re-opened S2. Grading the generic rule by the
shape of the *value* keeps a mixed-class literal blocking — confirmed by probe
D2 — while `foreach ($x as $key => $value)` is not. H2 is closed and the suite
asserts the dangerous half. S16/M5 is closed for the idiom it was about.

**Three of the four HIGH are closed. S17/M4 is not**, and probe A measured ten
separate spellings reaching the trunk.

Nothing in this diff touches application code, payments, money, customer data or
a store boundary.

## Findings

### HIGH

#### S26 — `push-to-protected` reads the destination as text, so ten spellings reach the trunk

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-protected-ops.mjs:155-177` |
| **Class** | broken change control |
| **Severity** | HIGH — the rating the record gave S17/M4, for surviving instances of the same defect |

**Who the attacker is.** An agent holding `Bash` on a task branch — `git-release`
and `qa-engineer` both hold it, as does the calling session — told "get this onto
main", or recovering from a failed branch cut, or acting on shell text it read in
material it was asked to review.

**What they send, and what the guard does.** Measured by the calling session with
`evaluate()` driven in-process (probe A), `ALLOWED` meaning the guard permits it:

| Command | Branch | Result |
| --- | --- | --- |
| `git push origin "main"` | `feature/x` | **ALLOWED** |
| `git push origin 'main'` | `feature/x` | **ALLOWED** |
| `git push origin "$BRANCH"` | `feature/x` | **ALLOWED** |
| `git push origin $(git rev-parse --abbrev-ref HEAD)` | `main` | **ALLOWED** |
| `git push origin heads/main` | `feature/x` | **ALLOWED** |
| `git push --all origin` | `feature/x` | **ALLOWED** |
| `git push origin HEAD:"main"` | `feature/x` | **ALLOWED** |
| `gh api -X PUT /repos/o/r/contents/README.md -f branch=main …` | `feature/x` | **ALLOWED** |
| `gh api graphql -f query=mutation{mergePullRequest}` | `feature/x` | **ALLOWED** |
| `curl -X POST https://api.github.com/repos/o/r/merges -d …` | `feature/x` | **ALLOWED** |
| `git push origin main` (control) | `feature/x` | `push-to-protected` |

The three sub-tests each fail for a different reason. The colon test needs a
literal `:` followed by the trunk name. The positional test requires the trunk
name to begin exactly where the token begins, so a leading `"`, `'`, `$` or
`heads/` defeats it, and `\S+` cannot span the preceding space to recover. Then
the fallback:

```js
const named = /\bgit\s+push\b(?:\s+-\S+(?:\s+\S+)?)*\s+\S+\s+(\S+)/.exec(segment);
const destinationIsHead = named === null || /^\+?(?:HEAD|@)(?::|$)/.test(named[1]);
return destinationIsHead && PROTECTED_BRANCHES.has(branch);
```

A destination token is present and is not the literal word `HEAD`, so the rule
returns false without ever consulting `branch`. **A destination the rule cannot
read disables the one check that does not depend on reading it.**
`git push --all origin` fails it from the other side: one positional token, so
`named === null`, `destinationIsHead` is true, and the rule stands down because
HEAD is on a feature branch — while `--all` pushes every local branch, `main`
among them.

**What they get.** Exit 0. The trunk moves with no pull request, so
`task-traceability.yml` never runs and no reviewer sees the diff. There is no
server-side backstop: `main` has no branch protection (AC10, open). The other
spellings being blocked makes this worse, not better — the ruleset reads as
complete, and the suite asserts six trunk cases, which is evidence about six
spellings rather than about the rule.

**What to change.** Stop matching the destination; resolve it. Take the token
right of the last `:` if present, else the last positional argument; `unquote()`
it — the helper exists at `:52-55` and is not used here; strip a leading `+` and
an optional `refs/heads/` or `heads/`; compare against `PROTECTED_BRANCHES`. Then
**invert the fallback's default**: a destination containing `$`, a backtick or
`(`, or that does not resolve to a literal branch name, is treated as HEAD and
tested, rather than concluded safe. Add `--all` and `--mirror`-class whole-repo
pushes to `force-push`. Put all ten commands in `selftest.mjs` with the rule id
asserted, plus one allow fixture that would catch over-tightening:
`git push origin "feature/x"` on `feature/x`.

#### S27 — the advisory tier is written to stderr behind `exit 0`, which reaches nobody

| | |
| --- | --- |
| **Where** | `.claude/hooks/guard-secrets.mjs:448-458`; `.claude/settings.json` |
| **Class** | control degradation |
| **Severity** | HIGH — raised from the agent's MEDIUM. It rated it "HIGH together with S28 if the channel is discarded", and the channel is discarded. The `code-reviewer` set the same escalation condition independently |

**What is wrong.** The whole safety argument for narrowing the generic rule is
stated twice — `guard-secrets.mjs:183` ("Anything this rejects is still reported
as advisory, so nothing goes unseen") and in the round-4 ledger note ("They are
reported and never block"). The implementation is
`process.stderr.write(...)` followed by `process.exit(0)`.

**Measured and documented, both.** The calling session ran the hook with one
advisory-only line in the working tree: **311 bytes on stderr, stdout empty,
exit code 0.** The hook reference for this build states:

> "Claude never sees stderr from a hook that exits 0. To read it yourself on
> events such as `PreToolUse`, enable debug logging."

| Exit | stderr goes to | Action |
| --- | --- | --- |
| 0 | the debug log only — not Claude, not the transcript | proceeds |
| 2 | Claude, as the block reason | blocked |
| other | the transcript, as a `hook error` notice | proceeds |

So the generic `key = value` rule was not demoted to advisory. **It was turned
into a silent no-op**, and the two claims above are false as written.

**What they get.** Nothing visible. Probe D measured the population: 27 advisory
findings across 21 files, and **every one is a false positive from a generic key**
— `$key =>`, a GitHub Actions cache `key:`, `path-key: 3.1.1`, header name
constants, `artisan key:generate` inside a comment. **No real credential is
exposed today**, which is why this is HIGH and not CRITICAL: the channel is
empty, so nothing has been lost yet.

**What to change.** Use the documented output form rather than a stream
convention: `exit 0` with a JSON object carrying `systemMessage` (documented as
shown to the user) and `hookSpecificOutput.additionalContext` (reaches the
model). Do not use exit 1 — the reference labels it an error notice and gives no
guarantee the model sees it. Then correct both claims in the code and the ledger,
and add a self-test fixture asserting `advisory.length > 0`, because nothing in
the suite pins that the advisory half is populated at all.

### MEDIUM

#### S28 — the grading discards what the key already said

`guard-secrets.mjs:185-218`, `:339-344`. The grader looks only at the value.
Measured (probe D2) as advisory, not blocking: `MAIL_PASSWORD=supersecretpassword`
(all letters), `POSTGRES_PASSWORD: correct-horse-battery` (dashed identifier),
`MAIL_PASSWORD=$Ecret2026Prod` (leading `$`), `DB_PASSWORD=Xk9*mQ2pLw` (contains
`*`). Control `DB_PASSWORD=Hx9kLm2Qp7vRtZw4` still blocks, so S2 holds for mixed
literals.

Probe D settles the design question the agent raised: **all 27 false findings came
from a generic key** (`key`, `token`, `KEY`), and **not one from an unambiguous
one**. So the shape test is being applied where it was never needed, and its cost
is paid where the key was never ambiguous. Band on key confidence: for
`password`, `passwd`, `*secret`, `api[_-]?key`, `access[_-]?token`,
`auth[_-]?token`, `private[_-]?key`, `client_secret`, `app_key`, block unless
`looksLikePlaceholder` excuses the value — round 2's behaviour, with zero
measured false positives. Keep round 4's grading for the generic words only. Add
the four rows above as block fixtures with an unambiguous key and as non-block
fixtures with a bare `key =`.

#### S29 — an untracked private key blocks every commit in the repository

`guard-secrets.mjs:442-443`, `:377-402`, `:308-316`. Both scans now run
unconditionally, and the path rules run over `names` with no relation to what the
commit includes. `.gitignore` covers `.env`, `.env.*`, `*.dump`, `*.sql.gz` — but
**not** `*.pem`, `*.key`, `*.p12`, `id_rsa` or `auth.json`. Measured (probe E):
`names = [localhost-key.pem, README.md]` yields one blocking finding naming the
`.pem`. `mkcert localhost`, which local HTTPS for the Next.js apps needs, writes
exactly that file. From then on every commit exits 2 naming a file the developer
never staged, and the message says "Remove it from the change" when it is not in
the change. Round 4's trade-off note defends the *content* half with "`git add -A`
would have picked it up"; that does not reach the path tier, which needs no added
line, nor `git commit -m x` with a clean specific index. Scope the path rules to
the index unconditionally and to untracked files only when the command stages, or
report an untracked-only path hit as advisory.

#### S30 — the working-tree read is unbounded and every failure path is fail-open

`guard-secrets.mjs:425`, `:442-443`, `:377-402`; timeout 30s. No aggregate cap:
`diff` accumulates with `+=` outside any `try`, so around 256 untracked files
near the 2 MB per-file cap throws `RangeError: Invalid string length`, uncaught,
exiting non-zero and non-2 — a commit allowed because the scan could not run.
Over-2 MB files are skipped in silence. `readFileSync` with no `lstat` follows a
symlink out of the repository. `git()` swallows every failure and returns `''`, so
an error, a 15s subprocess timeout or a 32 MB buffer overrun all read as clean
(the recorded S6, now with two call sites). Cap the aggregate read, and on hitting
it `exit 2` with "could not scan everything" rather than exiting 0 with nothing.

#### S31 — the exempt directories waive the token shapes too

`guard-secrets.mjs:258-275`, `:327`. `isFixtureFile` is tested once, before both
`CREDENTIAL_SHAPES` and `ASSIGNMENT`, so the whole content tier is skipped under
`.claude/reviews/` and `.claude/tasks/`. Measured (probe 4): an `AKIA…` line
attributed to `.claude/reviews/SCP-003/security-review.md` and to
`.claude/tasks/SCP-003.md` yields 0 blocking, 0 advisory, while the same line in
`apps/api/x.php` blocks. The gate *requires* those files to exist, so there is no
"do not commit it" escape. Narrow rather than remove: keep the exemption for the
generic rule, keep `CREDENTIAL_SHAPES` blocking everywhere, restrict the prefixes
to `.md`, and tell the review agents to truncate a found token to eight
characters. Probe 4b confirms the path tier survives the exemption: a `.env`
under `.claude/reviews/` still blocks.

#### S32 — `trunk-write-api` enumerates two routes and misses the rest

`guard-protected-ops.mjs:288-301`. Measured allowed (probe A): the Contents API
(`-X PUT /repos/o/r/contents/README.md -f branch=main`), the GraphQL
`mergePullRequest` mutation, and `curl -X POST …/merges` — **no rule in the file
mentions `curl`**, so every endpoint `gh api` is guarded for is reachable with the
same credential by another client. Judge the endpoint, not the client.

#### S33 — `quality-gates.md` describes the control that existed before this round

`docs/workflow/quality-gates.md:16`, `:28-56`, `:127`. Round 4 changed four files
and none was documentation. The section still says the scan "reads the working
tree, not the index, whenever the command will stage files" (it now reads both,
always), and nowhere mentions the advisory tier — so the one fact a developer most
needs, that the generic rule no longer stops anything, is absent from the document
whose purpose is to say what only looks enforced. Third consecutive pass to find
this.

#### S34 — `security-reviewer.md` claims it holds `Bash` and points at a skill that does not exist

`.claude/agents/security-reviewer.md:4` vs `:10` vs `:18-21`, and `:100-101`.
Confirmed independently by both reviewers and by the calling session. Line 4
grants `tools: Read, Glob, Grep`; line 10 says "You do hold Bash"; lines 18-21 say
it does not. Line 100 sends it to a `/security-review` skill; `.claude/skills/`
holds eight skills and no such one. `code-reviewer.md` carries neither defect. The
agent attested to the consequence firsthand: an agent told it holds `Bash` may
report a probe result it never obtained, which the same file forbids three lines
later. Raised as M9 in pass 2; round 4 did not fix it and did not list it among
what was left open.

#### S35 — the documented recovery from landing on the trunk is now refused

`guard-protected-ops.mjs:174-176`. The fallback tests the **left** side of the
refspec — the source — while naming the result `destinationIsHead`. Measured
(probe A): `git push origin HEAD:feature/x` on `main` is blocked as
`push-to-protected`, and that is the standard way out of having landed on the
trunk. The S26 fix removes this as a side effect.

### LOW

- **S36** — `trunk-write-api` ignores the HTTP method. Measured: `gh api
  /repos/o/r/git/refs/heads/main`, a GET and the ordinary way to read the trunk's
  SHA, is refused with "This writes a protected branch through the API". Require a
  write method or a body for that arm.
- **S37** — `/\bgit\b[^\n]*\bcommit\b/` matches `git log --grep=commit` and
  `git commit --dry-run`, so unrelated commands pay for both scans and, given S29,
  can be blocked by a filename unrelated to any commit.
- **S38** — dropping the `.npmrc` path rule was right, but npm's legacy
  `:_password=` pair is now matched by nothing, and `ASSIGNMENT`'s group 1
  `(^|[^A-Za-z0-9_-])` cannot consume `_`, so **any credential key immediately
  preceded by an underscore is invisible to the generic rule** — `_password`,
  `_token`, `__SECRET`.
- **S39** — `guard-protected-ops.mjs:362` echoes the whole blocked command, and
  for the `write-dotenv` rules that command contains by definition the value being
  written. The new `guard-secrets` messages are clean by comparison: file, rule
  name and matched key word, never the value.

## Payment integrity

`CLAUDE.md` §6: N/A throughout — no route, controller, migration, model or payment
code in the diff.

Two process notes bear on payments, and the first has improved materially. Pass 2
recorded that the guard flagged the shared idempotency-key and guest-order-token
constants, the idempotency middleware, the Sanctum config and the CSRF rewrite
rule — the exact surfaces SCP-001 and SCP-002 must change — so pressure to disable
the guard would have arrived with the first real payment change. Probe D confirms
0 blocking findings across all 251 files, so that pressure is gone. What remains
is S9 and S10, both open: a future change to store-scoped or payment code can
reach a pull request with no security review required, and the review that is
required is authored by the actor it judges — visible in this very round, where one
commit fixed the guards and set the `HIGH` cell the gate parses to `0`.

## Store isolation · Guest access · Secrets and logging

Store isolation, guest access and token handling: N/A — no queries, endpoints or
token code in the diff.

| Check | Status |
| --- | --- |
| No hardcoded credential in the diff | PASS — every credential-shaped value is a documented vendor example, an invented key, or a joke placeholder, all in fixtures or quoted review evidence |
| `.env` not in the diff | PASS — 43 files, none is `.env` or `.env.*` |
| No credential, payment payload or PII written to a log | PARTIAL — S39, unchanged |
| No internal detail in an error response | PASS — no HTTP surface |

## Dependencies

**Not run, and this is not a statement that the tree is clean.** `pnpm audit` and
`composer audit` were not executed: the agent has no `Bash` and did not ask,
because this change alters no manifest and no lockfile — `package.json` gains two
script entries and neither lockfile is in the diff. The `/security-review` skill
the agent's own definition instructs it to run does not exist here (S34).

## Not defects

- **The grading design.** Splitting into `blocking`/`advisory` and keeping the path
  and token tiers hard is the right architecture, and better than the remedy this
  reviewer proposed last round. S28 is about one input the grader ignores.
- **Keeping `/` and `+` out of the rejection list.** Load-bearing: base64 uses
  both, and an AWS secret with `/` in it still blocks because of it.
- **The union scan instead of parsing a pathspec.** The right instinct — S1 and S5
  both came from reading a shell string.
- **The twelve `MUST_NOT_BLOCK` fixtures.** The first assertions in this repository
  taken from real files rather than invented ones, which is exactly why S15
  survived a green 117-assertion suite.
- **Asserting that an exempt directory still refuses a `.env`.** Written before
  anyone asked for it, and probe 4b confirms it works.
- **Repairing the ledger from the code rather than from memory.** Recovering the
  lost token by reading the regex is the right method.
- **Raising the unsatisfiable gate instead of relaxing the commit-subject rule.**
  Exactly the restraint S15 was about.

## Verdict

**CHANGES REQUIRED.** Two HIGH: the trunk is reachable by ten spellings (S26), and
the advisory channel that the S15 fix rests on reaches nobody (S27). Neither is
reachable by a customer and neither moves money, but S26 defeats the only hard
control over what lands on `main`, and S27 means a fix recorded as "graded"
actually deleted a rule.

`Review Findings` in `SCP-000.md` must stop recording S17/M4 as closed, and the
`HIGH` cell the traceability gate parses must not read `0`.

Per `/review` step 6: one HIGH means `CHANGES_REQUESTED`, not `READY_FOR_PR`.
