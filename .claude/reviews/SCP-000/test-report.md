# Test report — SCP-000

|             |                                                                        |
| ----------- | ---------------------------------------------------------------------- |
| **Task**    | SCP-000 — AI development workflow                                      |
| **Run**     | 2026-11-12                                                             |
| **By**      | `qa-engineer` agent; guard probes re-run independently by the session  |
| **Verdict** | **FAILS** — AC8 is ticked without evidence, AC4 is overstated          |

## What ran

| Suite             | Command                   | Result                            |
| ----------------- | ------------------------- | --------------------------------- |
| Hook self-test    | `pnpm claude:selftest`    | `60 passed, 0 failed`             |
| Traceability gate | `pnpm claude:traceability`| exit 1, `5 problem(s)`            |
| Guard variants    | in-process `evaluate()`   | 5 blocked, 10 allowed (see F2)    |
| Hook exercise     | temp git repo, scratchpad | appends correctly when invoked    |
| Branch protection | `gh api .../branches/main/protection` | `Branch not protected (HTTP 404)` |

**Environment.** Docker was **not running** (`docker compose ps` fails to reach
`npipe:////./pipe/dockerDesktopLinuxEngine`). No PHP or Pest suite ran, and
none is relevant: every SCP-000 criterion is a file, Node or git check. No
criterion is NOT VERIFIABLE for that reason. `jq` is absent, which AC7 relies
on; no hook uses it.

## Acceptance criteria

| #    | Criterion                                | Result           | Decided by                                                                 |
| ---- | ---------------------------------------- | ---------------- | -------------------------------------------------------------------------- |
| AC1  | Nine agent definitions, valid frontmatter | PASS             | Read 9 files in `.claude/agents/`; `name` matches filename in each. Frontmatter read, not schema-validated |
| AC2  | Review and QA agents cannot edit files    | PASS (caveat)    | Four agents list `tools: Read, Glob, Grep, Bash` — no Write or Edit. See N1 |
| AC3  | Eight skills, each validating task state  | PASS             | 8 `SKILL.md` files; `/plan`, `/implement`, `/test`, `/review`, `/fix`, `/pr` carry a status table with a Stop row. Skills were read, not invoked |
| AC4  | Guards block destructive operations       | **PARTIAL**      | F2 — canonical spellings blocked, 10 plausible variants allowed             |
| AC5  | Guards do not block ordinary work         | PASS (narrow)    | 20 allow cases in the 60 assertions, all real commands from this repo       |
| AC6  | Secret guard blocks credentials           | PASS             | 10 block + 9 allow cases, including this repo's own placeholders            |
| AC7  | Hooks in Node, not bash + jq              | PASS             | `which jq` finds nothing; all 4 hooks are `.mjs`                            |
| AC8  | Template fields + activity log auto-appended | **FAIL**      | F1 — the hook has never been observed to fire for a real commit             |
| AC9  | Traceability gate rejects an incomplete PR | NOT VERIFIABLE  | Only a rejection path fired, and only because the task is genuinely incomplete. No pass-path fixture; 245-line script with no tests; `task-traceability.yml` has never run |
| AC10 | Docs state which gates are enforced        | PASS on content | `docs/workflow/README.md` and `quality-gates.md` both say branch protection is not enabled; `gh api` confirms HTTP 404. Author left it unticked — their call |

## Failures

#### F1 — AC8: the activity log hook has never fired for a real commit

|              |                                                                   |
| ------------ | ----------------------------------------------------------------- |
| **Severity** | HIGH                                                              |
| **Criterion**| AC8                                                               |
| **Status**   | Open                                                              |

The script itself is correct. Driven directly in a throwaway repo on branch
`chore/SCP-000-x`, `record-task-activity.mjs` exited 0 and appended
`| 2026-11-11T23:45:24Z | commit | \`42d1dd6\` chore: test [SCP-000] (1 file) |`,
and a second run did not duplicate the row.

Automatic firing is what is unproven:

- Commit `58869f9` was made on branch `chore/SCP-000-ai-dev-workflow`, with
  `[SCP-000]` in the subject — both places the hook could find the id.
- `grep -c 58869f9` against `.claude/tasks/SCP-000.md` returns 0.
- `git status --short` is clean. A `PostToolUse` run after the commit would
  have left `SCP-000.md` modified and uncommitted. It left nothing.
- The one existing row, for `773a729`, cannot have come from a real firing:
  the reflog puts that commit on `main` on 2026-11-10, while the row is stamped
  `2026-11-11T14:13:40Z`, over a day later and after `.claude/` existed.

Two unconfirmed explanations: `settings.json` was created in the session that
would have loaded it, so the hooks were not registered yet; or the matcher did
not match the compound `git add -A && git commit ...` form actually used.

This matters more than one missing row. `.claude/tasks/README.md` states that
where a record and reality disagree, reality wins and the record is the bug.
A mechanism meant to keep the record current, which silently does not run, is
worse than no mechanism — people trust it.

**To confirm:** make a throwaway commit in a fresh session and check whether a
row appears.

#### F2 — AC4: the guards block the canonical spelling and little else

|              |                                                        |
| ------------ | ------------------------------------------------------ |
| **Severity** | MEDIUM                                                 |
| **Criterion**| AC4                                                    |
| **Status**   | Open                                                   |

The 60 assertions break down as 21 block, 20 allow, 10 secret-block, 9
secret-allow. Every AC4 item has at least one block case — but only one, in its
canonical form. Driving `evaluate(command, branch)` in-process over variants:

| Command                      | Result  |
| ---------------------------- | ------- |
| `git push -f origin main`    | blocked |
| `git push -fu origin main`   | allowed |
| `git push origin +main`      | allowed |
| `git push origin :main`      | allowed |
| `git push --mirror origin`   | allowed |
| `git commit --no-verify -m x`| blocked |
| `git commit -n -m x`         | allowed |
| `echo A > .env`              | blocked |
| `echo A>.env`                | allowed |
| `cp x .env`                  | allowed |
| `sed -i s/a/b/ .env`         | allowed |
| `docker compose down -v`     | blocked |
| `docker-compose down -v`     | allowed |
| `docker compose down -fv`    | allowed |
| `php artisan migrate:fresh`  | blocked |

5 blocked, 10 allowed.

The QA agent found further gaps not re-probed here: `migrate --force
--env=production` (the production check only looks for `APP_ENV=production` or
`compose.prod`), `echo x | tee -a .env`, `git -c core.hooksPath=/dev/null
commit`, and `gh api -X PUT repos/o/r/pulls/3/merge`, which merges a pull
request while bypassing the `gh pr merge` rule.

The guard's own header says it raises the cost of an accident, not of intent,
and some bypasses are accepted by design. But `-fu`, `:main`, `+main`, `-n`,
`down -fv` and `echo x>.env` are accident shapes, not evasion. AC4 reads as
comprehensive; the tests prove one spelling per item.

## Notes

#### N1 — AC2: "cannot edit" means no edit tools, not that writes are impossible

The four review and QA agents have `Bash`, which can write through `sed -i`,
redirection or `tee`. The guarantee is a tool list, not an enforced boundary.
This is worth knowing when reading a review that claims independence. It did
not cause a problem here: the QA agent declined to write its own report, which
is why this file was written by the session rather than the agent.

#### N2 — The report the skill asks for cannot be written by the agent it asks

`/test` instructs the `qa-engineer` agent to write
`.claude/reviews/SCP-000/test-report.md`. That agent has no write tool and its
own instructions tell it to hand findings back. The two disagree. Either give
the agent a write tool scoped to `.claude/reviews/`, or change the skill to say
the session transcribes the findings.

#### N3 — The traceability gate flags a template mismatch

`_TEMPLATE.md` names the column **Unresolved**; `SCP-000.md` uses **Resolved**
with `—`. The gate reads the former and reports an unresolved CRITICAL and HIGH
that do not exist. The gate is right to be strict; the template and the record
should agree.

## Not verified, and why

| Criterion                         | Reason                                                                        |
| --------------------------------- | ----------------------------------------------------------------------------- |
| AC8, automatic firing             | Needs a real commit in a session that loaded the hooks; only direct invocation was possible |
| AC9                               | No pull request has run `task-traceability.yml`; the script has no pass-path test |
| AC3, "invocable as `/name`"       | Frontmatter and gating text were read, not executed                            |
| AC1, "valid per documented schema"| Frontmatter read by eye; no schema validator exists                            |

## Verdict

**FAILS.** AC8 is ticked without evidence and the automatic recording has never
fired for a real commit. AC4 is overstated relative to what the 60 assertions
prove. AC9 and AC10 are correctly left unticked by the author.

Not ready for a pull request. Next step: `/fix SCP-000`.
