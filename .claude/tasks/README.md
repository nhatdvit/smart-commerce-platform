# Task index

Every development task on this project has a record in this directory. The
record, not the commit message, is the task history: a commit says what
changed, while the record says what was asked for, what was approved, what was
tested, what review found, and what was knowingly left out.

Ids are `SCP-NNN`, zero-padded, allocated in order and **never reused** — a
cancelled id stays cancelled, because the history of a decision not to do
something is still history.

## How to use it

```
/task <description>   open a record
/plan SCP-NNN         design it, then stop for approval
/implement SCP-NNN    build the approved plan
/test SCP-NNN         verify against the acceptance criteria
/review SCP-NNN       independent code, security and performance review
/fix SCP-NNN          close the findings, then review again
/pr SCP-NNN           open the pull request
/status SCP-NNN       where it stands
```

The full workflow, including which gates a human owns, is in
[`docs/workflow/README.md`](../../docs/workflow/README.md).

## Status

| Status | Means |
| --- | --- |
| `BACKLOG` | Recorded, not started |
| `ANALYZING` | Being scoped or planned |
| `PLANNED` | A plan exists, waiting on Tech Lead approval |
| `APPROVED` | Approved to implement. Only a human sets this |
| `IN_PROGRESS` | Being implemented |
| `TESTING` | Implemented, being verified |
| `REVIEW` | Tests green, under review |
| `CHANGES_REQUESTED` | Review found something blocking |
| `READY_FOR_PR` | Reviewed, no CRITICAL or HIGH open |
| `DONE` | Pull request merged. Only a human sets this |
| `BLOCKED` | Cannot proceed. The record says why and who can clear it |
| `CANCELLED` | Will not be done. The record says why |

Two transitions are never automated: `PLANNED → APPROVED` and
`READY_FOR_PR → DONE`. The first is permission to spend effort, the second is a
claim that something shipped, and an agent should be able to make neither.

## Tasks

| ID | Title | Status | Priority | Branch | PR |
| --- | --- | --- | --- | --- | --- |
| [SCP-000](SCP-000.md) | AI development workflow | IN_PROGRESS | P2 | `chore/SCP-000-ai-dev-workflow` | — |
| [SCP-001](SCP-001.md) | VietQR payload for bank transfer | BACKLOG | P1 | — | — |
| [SCP-002](SCP-002.md) | Idempotency for operations that must not run twice | APPROVED | P1 | — | — |

Next free id: **SCP-003**

## A note on trusting this directory

The reports under `.claude/reviews/` are the opinions of review agents. They
are useful, and they are not proof. Proof is a test that ran and a CI run that
passed. Where a record and reality disagree, reality wins and the record is the
bug — `/status` checks the cheap facts against git and `gh` for exactly that
reason.
