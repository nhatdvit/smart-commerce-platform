# Performance review — SCP-000

| | |
| --- | --- |
| **Task** | SCP-000 |
| **Reviewed** | 2026-11-18 (third pass; revised the same day against probe results) |
| **Reviewer** | `performance-reviewer` agent |
| **Why reviewed** | none of the usual triggers — no migration, no query, no list endpoint, no job, no API Resource. The two earlier passes skipped this agent and were right to. Called this round because fix round 4 changed code that runs on **every `git commit` and every Bash call in this repository** |
| **Verdict** | ACCEPTABLE |

> Transcribed by the calling session. The agent held `Read`, `Glob`, `Grep` and no
> `Bash`, and ran nothing. Every timing was produced by the calling session, which
> also wrote fix round 4 — interested-party evidence, labelled throughout. The
> agent returned a first draft reasoning from the code, then **revised it after the
> probes and inverted its own ranking**; this is the revision.

**`CLAUDE.md` §10 does not apply and is not used to judge this diff.** There is no
HTTP path, no SQL and no queue job in it. The standard applied is the one the
hooks live under: added latency per tool call and per commit, and whether any
input makes a guard stop running.

## Summary

| Severity | Unresolved | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 0 | yes |
| MEDIUM | 2 | no |
| LOW | 4 | no |

**The measurements inverted the first draft's ranking.** It reasoned from the code
that the avoidable `git` subprocesses were the aggregate cost and rated them
MEDIUM. The probes show the spawn and all the regex work together cost **~5 ms**,
while **Node start-up costs 129 ms and is paid three times per Bash call**. So P1
and P2 are LOW — meeting the "under ~20 ms, leave it alone" criterion the draft set
for itself before seeing the numbers — and P5 is MEDIUM and the only per-call
finding worth acting on. P3 stays MEDIUM: its growth was measured and is linear,
which was the draft's stated test for *not* escalating.

**Which of two conflicting figures this report uses.** `guard-protected-ops` was
measured at 168-183 ms (3 runs, cold) and 134 ms (10 sequential, warm). This report
relies on **134 ms**: ten sequential runs beat three cold ones, and the warm figure
is what a developer experiences in a session where Node has started many times. The
cold figure is the right one for the first Bash call of a session.

## Findings

### MEDIUM

#### P5 — Node start-up is the whole cost: 129 ms, paid three times per Bash call

`.claude/settings.json` — two `PreToolUse` entries plus one `PostToolUse`, all
`matcher: "Bash"`. *Raised from LOW in the first draft, on these numbers.*

| Measured, 10 sequential invocations each | ms/call |
| --- | --- |
| `node -e "process.exit(0)"` — the floor | **129** |
| `guard-protected-ops` on `ls -la` | **134** |
| `record-task-activity` on `ls -la` | **134** |

So **96% of each hook's cost is starting Node**, and the logic this review was
called to examine is within noise of free. `guard-secrets` exits 0 immediately for
anything that is not a commit — nearly every call — and still costs ~129 ms.

Per Bash call: **~263 ms** if the two `PreToolUse` hooks run in parallel, **~400 ms**
if in series. Which it is remains unmeasured (declined probe, below). Across 400
Bash calls that is **1.8 to 2.7 minutes**, roughly 25-50× everything P1, P2 and P4
together.

**What to change.** Merge the two `PreToolUse` guards into one process: both exit
with the same `exit 2` contract and read the same stdin, so the semantics compose.
One entry point importing `evaluate` and `scanDiff`, keeping both files
individually importable so `selftest.mjs` is untouched. Recovers ~129 ms per Bash
call, ~52 s per 400-call session, and costs no guarantee.

**What must not be done:** restoring a `Bash(git commit *)` pre-filter to avoid
starting Node on non-commit calls. That is F1, removed for a correctness reason
that still holds — `git add -A && git commit` does not *begin* with `git commit` —
and trading it back for 129 ms is the bargain this task exists to refuse.

#### P3 — no cap on untracked file count or total bytes; growth is linear at 0.86 ms/file, and the 30 s timeout is a silent fail-open

`guard-secrets.mjs:377-402`; timeout in `.claude/settings.json`. *Held at MEDIUM on
this report's own test: HIGH only if growth were super-linear, and it is not.*

2 MB cap per file, and no cap on file count, total bytes or content type. Measured
(tiny files, to isolate count from bytes): **1000 → 1174 ms, 2000 → 2101 ms,
3000 → 2897 ms.** Least squares: **slope 0.86 ms/file, intercept 314 ms, clean
linear fit.** That replaces the draft's 1.0-1.6 ms/file hand-fit.

| Untracked files | Added to every commit |
| --- | --- |
| 0 (measured: today's state) | 0 |
| 1,000 | ~1.2 s |
| 5,000 | ~4.6 s |
| 10,000 | ~8.9 s |
| **~34,500** | **30 s — the hook timeout** |

Two consequences, the first mattering more:

1. **The cliff is not the problem; the approach to it is.** Long before 34,500
   files this is a multi-second stall on every commit. A guard that adds nine
   seconds to every commit is a guard someone switches off.
2. **At the cliff the guard stops running.** A `PreToolUse` hook killed by its
   timeout fails without `exit 2`, and a non-`exit 2` failure is non-blocking, so
   the commit proceeds unscanned. The slowest input is the one that disables it —
   the same fail-open class as S6, reached through performance.

**Latent, not present.** Measured: the current untracked count is **0**.
`.gitignore` is doing the work the missing cap would otherwise do — `vendor/` at
any depth, `node_modules/`, `dist/`, `.next/`, `coverage/`, `infra/.data/`,
`*.dump`, `*.sql.gz` and the materialised patches. A Laravel `vendor/` is
15,000-25,000 files, which on the measured slope is **13-22 s per commit** — the
right comparison for scale even though it cannot happen here. What remains reachable
is an artefact nobody thought to ignore: an unpacked archive, an export tree, a
fixture dump. Single huge files are handled by the 2 MB cap; **many medium files
have no defence.**

Memory is read from the code and **not measured**: peak is on the order of 2× total
untracked bytes plus per-line overhead, and a heap abort is the same fail-open as
the timeout.

**What to change.** A total-bytes budget (16 MB) and a file-count budget (500 — well
above today's 0, ~430 ms on the measured slope); skip probable binaries before
reading; and on hitting either budget write the truncation out rather than returning
a clean result silently.

### LOW

- **P1 — `guard-protected-ops` spawns `git rev-parse` on every Bash call**, including
  the majority mentioning no git, because `currentBranch()` is an argument expression
  evaluated before `evaluate()` is entered. Only three rules read the branch and all
  require `git merge|push|commit`. *Downgraded from MEDIUM:* measured, the spawn plus
  stdin plus JSON plus all 20 rule regexes come to **~5 ms**. The whole available
  saving is under 2 s across a 400-call session, against ~52 s from P5. **Recorded,
  not recommended** — changing working safety code for an unmeasurable gain is a bad
  trade in files three fix rounds have already destabilised.
- **P2 — `record-task-activity` spawns `git rev-parse` and reads the whole ledger
  before checking whether the command is interesting.** *Downgraded from MEDIUM,
  same test:* measured at 134 ms against the 129 ms floor, so the spawn, the
  `existsSync`, the full 547-line read and the marker search together cost ~5 ms.
  The reorder is still the right shape and worth taking if the file is edited anyway.
  Re-measure if the ledger grows to a few thousand lines.
- **P4 — the union scan adds 2-3 git subprocesses per commit.** Before: 2 on a plain
  commit, 3 on the staging idiom. After: **5**, plus a `statSync` and a `readFileSync`
  per untracked file. Measured: `guard-secrets` 356-376 ms clean-tree (5 runs, cold),
  regression intercept ~314 ms (warmer). Against the 129 ms floor the hook's own work
  is ~185-247 ms — **arithmetic, not measurement** — which is the one place a
  subprocess is not free: five `git` invocations at roughly 37-49 ms each. The
  contrast with P1 is instructive: `rev-parse` reads a ref, while `git diff HEAD`
  stats every tracked file through OneDrive. **It is the worktree walk that costs,
  not the fork.** Whole commit, serial: ~580-645 ms, about 13 s a day at twenty
  commits. **No change recommended** — what makes people disable a hook is a false
  block, and round 4 took those from 33 to 0.
- **P6 — two size limits fail silently.** `maxBuffer: 32 MB` with a bare
  `catch { return '' }`, and the 2 MB per-file `continue`. Both report a clean result
  for a change never read. Performance mechanisms with a security consequence —
  handed to the code reviewer alongside S6. The fix is to distinguish "scanned and
  clean" from "could not scan", not to raise the limits.

### Judged, no finding

- **`looksLikeCredentialValue`** runs at most once per added line and only after
  `ASSIGNMENT` has matched. Its seven regexes are anchored or flat alternations; the
  one that looks risky is not, because `[A-Za-z]` and `[.\-_]` are disjoint.
  Measured: slowest single pattern 0.8 ms on a 96 KB value, no catastrophic
  backtracking on minified JS, a lockfile integrity line, an 80 KB `api_key=` value
  or 96 KB of repeated quotes. Corroborated from the other direction by the
  (a)-versus-(b) result: all 20 rule regexes plus a subprocess came to ~5 ms.
- **`check-task-traceability.mjs` and CI.** ~7 git subprocesses, three file reads,
  lazy section extractors with a single-character-class terminator and no `/m` flag —
  O(n) per start position, not quadratic even at the 982 lines the ledger briefly
  reached. `fetch-depth: 0` is **necessary**, since the gate diffs against
  `origin/<base>` and reads every commit subject. `concurrency.cancel-in-progress`
  is set. No change.

## Query checks · Boundaries · Queues and caching

Every row is N/A: no SQL, no Eloquent, no PHP, no endpoint, no transaction, no lock,
no job, no queue, no cache and no store-scoped data anywhere in this diff. Two
analogues are recorded under their own findings rather than as table rows: the
unbounded `git ls-files --others` read loop is the "unbounded result set" of this
diff (P3), and the synchronous hook path is its "work inside a transaction" (P4,
P5). **No data-leak finding for the security reviewer arises from this review.**

## Measured, not measured

**Measured by the calling session** (interested party; the agent ran nothing):
the three timings above at 10 runs each; the 1000/2000/3000-file scaling; the
earlier 50/200/500-file and 501-file warm/cold figures; the regex timings; the
2 MB cap behaviour; and the current untracked count of 0.

**Read from the code, not measured** — counts and orderings, each priced at ~5 ms by
the probes: the unconditional `currentBranch()`; the recorder's ordering; subprocess
counts of 2-3 before and 5 after; three Node processes per Bash call; and that
`maxBuffer` and the per-file cap fail silently.

**Arithmetic on those measurements, labelled as such where it appears:** the
per-count projections and the 34,500-file cliff; the ~185-247 ms of own-work and
~37-49 ms per `git diff`; the ~75-100 ms the union added; the ~263-400 ms per-call
and 1.8-2.7-minute session totals.

**Declined by the calling session, recorded as declined rather than pending:**

- **Whether a timed-out `PreToolUse` hook permits the commit** — declined, because
  testing it means editing the hook timeout in `.claude/settings.json` and restarting
  the session. The agent endorsed the refusal without reservation: a review is not
  authorization to change hook or permission configuration. **The consequence stays
  visible: P3's fail-open character rests on the documented hook contract, not on an
  observation here.**
- **Parallel versus series `PreToolUse` execution** — declined for the same reason
  (it requires renaming `.claude/settings.json` away). This is why P5 quotes a range.
  The 1.5× uncertainty changes neither its severity nor its remedy.
- **Peak memory on a large untracked tree** — declined; 450 MB of writes into a
  OneDrive-synced checkout is not a reasonable thing to do to this machine. The
  file-count dimension that P3's slope rests on is covered.

**Still open and needing no configuration change:** the pre-round-4 `guard-secrets`
timing, which would replace two layers of arithmetic in P4 with one measurement. The
agent asked for it; it will not change P4's severity either way.

**Recommendation to relay to the user.** The two declined measurements are the only
ones left that would sharpen this report, and both need a configuration change plus
a session restart that only the user should make. The more valuable is the timeout
behaviour: if a timed-out `PreToolUse` hook *blocks* rather than waves through, P3's
second consequence disappears and the finding becomes a denial-of-commit at ~34,500
files instead of a silent fail-open — a materially different thing to design the cap
against.
