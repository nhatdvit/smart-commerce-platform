# AI development workflow

A task on this project goes from a request to a merged pull request through one
path, and every step leaves a record in git:

```
/task  →  /plan  →  [Tech Lead approves]  →  /implement  →  /test
       →  /review  →  /fix ⟲  →  /pr  →  [Tech Lead merges]
```

The record is `.claude/tasks/SCP-NNN.md`. The reviews are
`.claude/reviews/SCP-NNN/`. Both are committed, because a task history that
lives in a chat log is not a task history.

## Why orchestration lives in skills

The obvious design is a chain of agents handing off to each other. It does not
work: in Claude Code a subagent is spawned by the main session and **cannot
delegate to another subagent**. An agent definition instructing one agent to
call the next would simply do nothing.

So the division is:

| Layer                          | Runs where               | Job                                                               |
| ------------------------------ | ------------------------ | ----------------------------------------------------------------- |
| **Skills** (`.claude/skills/`) | main session             | Validate state, choose agents, consolidate results, enforce gates |
| **Agents** (`.claude/agents/`) | subagent                 | One specialism, deeply. No knowledge of the pipeline              |
| **Hooks** (`.claude/hooks/`)   | before/after a tool call | Hard-block what must not happen                                   |
| **CI** (`.github/workflows/`)  | GitHub                   | Gate the pull request                                             |

Each does what it is actually able to do. That is why the enforcement table in
[`quality-gates.md`](quality-gates.md) has a column for "enforced?" with some
honest no's in it.

## The agents

| Agent                  | Model       | Tools                     | Can edit files?                   | Called by            |
| ---------------------- | ----------- | ------------------------- | --------------------------------- | -------------------- |
| `task-manager`         | sonnet      | + `Write`, `Edit`, `Bash` | yes — `.claude/tasks/` by prompt  | `/task`              |
| `software-architect`   | opus, high  | + `Write`, `Edit`, `Bash` | yes — plans and `docs/` by prompt | `/plan`              |
| `laravel-developer`    | inherit     | default set               | yes                               | `/implement`, `/fix` |
| `nextjs-developer`     | inherit     | default set               | yes                               | `/implement`, `/fix` |
| `qa-engineer`          | sonnet      | + `Bash`                  | no `Write`/`Edit` — but `Bash`    | `/test`              |
| `code-reviewer`        | opus, high  | `Read`, `Glob`, `Grep`    | **no — structurally**             | `/review`            |
| `security-reviewer`    | opus, xhigh | `Read`, `Glob`, `Grep`    | **no — structurally**             | `/review`            |
| `performance-reviewer` | opus, high  | `Read`, `Glob`, `Grep`    | **no — structurally**             | `/review`            |
| `git-release`          | sonnet      | + `Bash`                  | no `Write`/`Edit` — but `Bash`    | `/implement`, `/pr`  |

Every row starts from `Read`, `Glob`, `Grep`; the Tools column says what is
added. "by prompt" means the scope is an instruction, not a permission.

The read-only column is the load-bearing one, and after SCP-000 review S4 it
means two different things depending on the row.

**The three reviewers** — `code-reviewer`, `security-reviewer`,
`performance-reviewer` — hold `Read`, `Glob` and `Grep`. No `Write`, no `Edit`,
no `Bash`. There is no channel through which they can change a file, so the
separation is structural: a reviewer cannot fix what it finds, and cannot write
the report that judges it. That report is transcribed by the calling session
from what the agent returns.

This was not free, and the cost is the honest part. Those agents used to run
`git diff` to read the change, `pnpm audit` and `composer audit` to check
dependencies, `EXPLAIN ANALYZE` to check a query plan, and the code itself to
confirm a finding rather than assert it. None of that is available to them now.
`/review` writes the diff to `.claude/reviews/<ID>/diff.patch` for them to
read, and when one asks for a command, the calling session runs it and sends
the output back.

So the trade is: **weaker independence of evidence, stronger independence of
action.** The session running those probes is usually the session that wrote or
fixed the code, which is an interested party; but an agent with no write
channel cannot author a clean verdict for work it did itself, and that is the
failure that actually empties a review of meaning. Whoever reads a review
report should know which commands were run by the reviewer and which by the
session — the skill requires that to be stated.

**`qa-engineer` is the deliberate exception.** It has to execute the suites, so
it keeps `Bash`, and a shell writes. Its independence rests on the agent rather
than on the tool list. Making it structural would mean running it against a
read-only checkout with a writable temp directory; that is not done.

**`task-manager` and `software-architect`** hold unrestricted `Write` and
`Edit`. The scopes written beside them — "`.claude/tasks/` only", "plans and
`docs/` only" — are instructions in a prompt, not permissions.

## Cost

A task that goes the whole way is three to five subagent runs, four of them on
opus. That is not free, and the workflow is built to spend it where it pays:

- `/review` always runs `code-reviewer`. It calls `security-reviewer` and
  `performance-reviewer` **only when the diff triggers them** — payments, auth,
  PII and store-scoped data for the first; migrations, queries, list endpoints
  and jobs for the second.
- Small, obvious changes do not need the pipeline. A typo fix is a commit, not
  a task. Use judgement; the ledger is for work worth tracking.

To make every review cheaper, change `model` and `effort` in the agent files.
They are the only dials.

## Running it

```bash
/task Generate the VietQR payload for bank transfer orders
/plan SCP-001            # then stop; approval is a human step
/implement SCP-001       # refuses unless status is APPROVED
/test SCP-001
/review SCP-001
/fix SCP-001             # loops back to /review
/pr SCP-001              # never merges
/status SCP-001          # or /status all
```

Each skill checks the task's status first and refuses out-of-order work. That
is the cheapest enforcement in the whole system: `/implement` on a task that is
only `PLANNED` stops and says the plan has not been approved.

## The two gates a human owns

**`PLANNED → APPROVED`.** Permission to spend effort on a design. An agent
proposing and approving its own plan is the failure this gate exists to
prevent.

**`READY_FOR_PR → DONE`.** A claim that something shipped. The evidence is a
merged pull request, not a green CI run.

Both are set by a person, recorded in the task file with who and when.

## Worked example

`.claude/tasks/SCP-001.md` is the VietQR task, scoped as it would really be
scoped. Its later sections are empty because the work has not been done.

That is deliberate. Filling in invented test results to make an example look
complete would contradict the one promise this workflow makes — that a recorded
result is a real one. The lifecycle is shown by the shape of the file and the
skills that move it, not by fiction.

## What this does not do

- It does not merge anything, or deploy anything.
- It does not make a review agent's opinion into proof. `.claude/reviews/`
  records what an agent thought. Proof is a test that ran.
- It does not enforce merge approval. That needs branch protection on GitHub,
  which is **not currently enabled** — see [`quality-gates.md`](quality-gates.md).
- It does not invent business policy. An open decision in `CLAUDE.md` §15 is a
  blocker, and the right outcome is a blocked task, not a guess.
