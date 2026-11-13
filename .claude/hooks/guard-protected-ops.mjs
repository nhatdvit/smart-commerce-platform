#!/usr/bin/env node
/**
 * PreToolUse guard: refuses shell commands that are hard or impossible to undo.
 *
 * Registered in .claude/settings.json against the Bash tool. The hook event
 * arrives as JSON on stdin; `exit 2` blocks the call and sends stderr back as
 * the reason, which is the only mechanism that actually stops a tool call
 * (https://code.claude.com/docs/en/hooks).
 *
 * Written in Node rather than bash + jq on purpose: jq is not installed on
 * every machine that works on this repository, and a guard that silently fails
 * to run is worse than no guard at all.
 *
 * ## What it is and is not
 *
 * It blocks *automated* steps, not people. A human who means it can run the
 * command in their own terminal. The point is that no agent rewrites shared
 * history, destroys a database, or merges to a protected branch because it
 * looked like the next logical move.
 *
 * It matches patterns against the command string, so it has two known limits,
 * both deliberate:
 *
 *   - A command that merely *mentions* a blocked pattern is blocked too. That
 *     is why `evaluate()` is exported and `selftest.mjs` drives it directly
 *     instead of passing fixtures through a shell.
 *   - A determined bypass gets through. This raises the cost of an accident,
 *     not of intent.
 *
 * It cannot stop a merge performed on GitHub. That needs branch protection on
 * the repository - see docs/workflow/quality-gates.md.
 */

import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const PROTECTED_BRANCHES = new Set(['main', 'master', 'production', 'release']);

/**
 * A path that is a real `.env`, in any directory, quoted or not - but never
 * `.env.example`, which is tracked on purpose, nor `.env.backup`, which is
 * where a careful person puts a copy.
 *
 * SCP-000 review M1/S7: the old rules anchored on the character *after* the
 * redirect or the argument separator, so `>> apps/api/.env` and
 * `cp x apps/api/.env` both passed. `apps/api/.env` is exactly where a
 * Laravel-conventional agent writes, and overwriting it destroys a developer's
 * APP_KEY, which makes every value encrypted with it unreadable.
 */
const DOTENV_FILE = /^(?:[\w.-]+\/)*\.env(?:\.(?:local|production|prod|staging))?$/;

const unquote = (token) =>
  String(token)
    .replace(/^["']/, '')
    .replace(/["']?;?$/, '');

/** The last argument of a command - the destination for cp, mv, install, sed -i. */
const lastArgument = (segment) => {
  const tokens = segment.trim().split(/\s+/);
  return unquote(tokens[tokens.length - 1] ?? '');
};

/**
 * Splits a shell line into the commands it actually runs.
 *
 * SCP-000 review H1: every rule used `[^\n]*` between the verb and the flag it
 * looks for, and `[^\n]*` crosses `&&`. So `git commit -m x && git log -n 1`
 * was refused as `skip-hooks` because of `git log`'s `-n`, and
 * `git push origin x && gh pr create -d -f` was refused as a force push
 * because of `gh pr create`'s own flags. Both are shapes this repository's own
 * documentation prescribes. Blocking ordinary work is not a safe failure: it
 * teaches people that the guard is noise, and then it protects nothing.
 *
 * Splitting is deliberately naive about quoting. A separator inside a quoted
 * string splits too, which can only ever make each piece smaller and the match
 * narrower - it cannot let a dangerous command through that a whole-line match
 * would have caught, because that command is still wholly inside one piece.
 */
const SEGMENTS = /\s*(?:&&|\|\||;|\|)\s*/;

/** The branch HEAD is on, or '' when that cannot be determined cheaply. */
export const currentBranch = () => {
  try {
    return execFileSync('git', ['rev-parse', '--abbrev-ref', 'HEAD'], {
      encoding: 'utf8',
      timeout: 2000,
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return '';
  }
};

/**
 * Each rule is `pattern` plus an optional `test` that narrows it further.
 * `branch` is passed in rather than read inside a rule so the rules stay pure
 * and testable.
 */
export const RULES = [
  {
    id: 'force-push',
    // --force-with-lease is still a rewrite of a branch someone else may hold.
    pattern:
      /\bgit\s+push\b[^\n]*(--force\b|--force-with-lease\b|--mirror\b|\s-[A-Za-z]*f[A-Za-z]*\b)/,
    reason:
      'Force-pushing rewrites history other people may already have. CLAUDE.md §13 requires explicit authorization; run it yourself if you mean it.',
  },
  {
    id: 'force-refspec',
    pattern: /\bgit\s+push\b[^\n]*\s\+[A-Za-z0-9_./-]+(:|\s|$)/,
    reason: 'A refspec starting with "+" is a force push in disguise.',
  },
  {
    id: 'history-rewrite',
    pattern: /\bgit\s+(filter-branch|filter-repo)\b/,
    reason: 'Rewriting the whole repository history is never an automated step.',
  },
  {
    id: 'interactive-rebase',
    pattern: /\bgit\s+rebase\b[^\n]*(-i\b|--interactive\b|--onto\b)/,
    reason:
      'Interactive or onto-rebase rewrites commits. Do it yourself, on a branch nobody else has pulled.',
  },
  {
    id: 'hard-reset-to-remote',
    pattern: /\bgit\s+reset\s+--hard\s+(origin|upstream)\//,
    reason:
      'A hard reset onto a remote branch discards local commits with no way back. Inspect and choose deliberately.',
  },
  {
    id: 'delete-remote-branch',
    pattern: /\bgit\s+push\b[^\n]*(--delete\b|\s-[A-Za-z]*d[A-Za-z]*\b|\s:[A-Za-z0-9_./-]+(\s|$))/,
    reason: 'Deleting a remote branch is not reversible from here.',
  },
  {
    id: 'merge-into-protected',
    // Merging INTO a protected branch is the direction that matters. `git merge
    // main` while standing on a feature branch is the opposite, and normal.
    pattern: /\bgit\s+merge\b/,
    test: (command, branch) =>
      /\bgit\s+(checkout|switch)\s+(-\S+\s+)*(main|master|production|release)\b/.test(command) ||
      PROTECTED_BRANCHES.has(branch),
    reason:
      'Merging into a protected branch is a human decision. Open a pull request and let a reviewer merge it.',
  },
  {
    id: 'push-to-protected',
    // SCP-000 review S3. The ruleset blocked every indirect route to main -
    // gh pr merge, the merge API, checkout-and-merge - and left the one-liner
    // that goes straight there: `git push origin HEAD:main`. A push to main
    // raises no pull request, so task-traceability.yml never runs and no
    // reviewer ever sees the diff. That is the outcome the other three rules
    // exist to prevent, reached by the shortest road.
    pattern: /\bgit\s+push\b/,
    test: (segment, branch) => {
      // `git push origin src:main`, or `git push origin +main`
      if (/:(?:main|master|production|release)\b/.test(segment)) return true;
      // `git push origin main`
      if (
        /\bgit\s+push\b(?:\s+-\S+(?:\s+\S+)?)*\s+\S+\s+\+?(?:main|master|production|release)\b/.test(
          segment,
        )
      ) {
        return true;
      }
      // No branch argument at all: git pushes whatever HEAD is on.
      const named = /\bgit\s+push\b(?:\s+-\S+(?:\s+\S+)?)*\s+\S+\s+(\S+)/.test(segment);
      return !named && PROTECTED_BRANCHES.has(branch);
    },
    reason:
      'Pushing straight to a protected branch skips the pull request, so no CI gate and no reviewer ever sees the change. Push your task branch and open a pull request.',
  },
  {
    id: 'commit-on-protected',
    // SCP-000 review M10. Adjacent to push-to-protected and deliberately a
    // separate rule: this one catches the commit that should never have been
    // made, before there is anything to push. An agent lands on main after a
    // fresh clone, or a branch cut that failed, and commits there.
    pattern: /\bgit\s+commit\b/,
    test: (segment, branch) => PROTECTED_BRANCHES.has(branch),
    reason:
      'HEAD is on a protected branch, so this commit would land on the trunk. Cut a task branch first - /implement step 2 - and commit there.',
  },
  {
    id: 'auto-merge-pr',
    pattern: /\bgh\s+pr\s+merge\b/,
    reason: 'Pull requests are never merged automatically. A reviewer merges, after CI is green.',
  },
  {
    id: 'destructive-migration',
    // migrate:reset was missing (SCP-000 review M7). It rolls every migration
    // back - the table-dropping half of migrate:refresh - and it is the verb an
    // agent reaches for when asked to "reset the local database".
    pattern: /\bartisan\s+(migrate:fresh|migrate:refresh|migrate:reset|db:wipe)\b/,
    reason:
      'CLAUDE.md §7 forbids migrate:fresh, migrate:refresh, migrate:reset and db:wipe. They drop every table. Use a reviewed forward migration.',
  },
  {
    id: 'production-migration',
    // `pnpm api:migrate` against the dev stack stays allowed; what is blocked
    // is a migration aimed at a production environment.
    pattern: /\bartisan\s+migrate\b/,
    // The environment markers are read from the WHOLE line, not the segment:
    // `export APP_ENV=production && php artisan migrate` sets the environment
    // in one command and migrates in the next, and splitting must not lose
    // that. `--database=production` names a production connection directly.
    test: (segment, branch, whole) =>
      /\bAPP_ENV\s*=\s*production\b/.test(whole) ||
      /--env[=\s]+production\b/.test(segment) ||
      /--database[=\s]+production\b/.test(segment) ||
      /compose\.prod/.test(whole),
    reason:
      'Running migrations against production is an explicit, authorized deploy step, not something an agent does in passing (CLAUDE.md §9).',
  },
  {
    id: 'compose-down-volumes',
    pattern: /\bdocker[\s-]compose\b[^\n]*\bdown\b[^\n]*(--volumes\b|\s-[A-Za-z]*v[A-Za-z]*\b)/,
    reason:
      'down -v deletes the database volume. If you want a clean local database, say so and the volume gets removed deliberately.',
  },
  {
    id: 'docker-prune',
    pattern: /\bdocker\s+(volume\s+rm|volume\s+prune|system\s+prune)\b/,
    reason: 'Removing volumes destroys data that is not backed up anywhere.',
  },
  {
    id: 'write-dotenv',
    // Match the redirect target as a PATH, so apps/api/.env and "./.env" are
    // caught as well as a bare .env (SCP-000 review M1).
    pattern: /(?:>{1,2}|\btee\s+(?:-a\s+)?)\s*(["']?)([^\s;|&"']+)\1/,
    test: (segment) => {
      const target = /(?:>{1,2}|\btee\s+(?:-a\s+)?)\s*(["']?)([^\s;|&"']+)\1/.exec(segment);
      return target !== null && DOTENV_FILE.test(unquote(target[2]).replace(/^\.\//, ''));
    },
    reason:
      'The .env file holds real credentials and is never written by an automated step. Edit .env.example instead, and change .env yourself.',
  },
  {
    id: 'production-stack',
    pattern: /\bcompose\.prod\.ya?ml\b[^\n]*\b(up|restart|run)\b/,
    reason:
      'Starting or restarting the production stack requires explicit authorization (CLAUDE.md §13).',
  },
  {
    id: 'skip-hooks',
    pattern: /\bgit\s+commit\b[^\n]*(--no-verify\b|\s-[A-Za-z]*n[A-Za-z]*\b)/,
    reason: 'Skipping hooks defeats the checks this workflow exists to run.',
  },
  {
    id: 'write-dotenv-indirect',
    // A redirect is not the only way to overwrite .env. cp, mv and sed -i all
    // land in the same place, and the file holds real credentials.
    //
    // SCP-000 review M1: judge the DESTINATION, not any mention of .env. The
    // old rule matched `.env` anywhere in the command, so `cp .env .env.backup`
    // - taking a backup, a read - was refused, while `cp x apps/api/.env` was
    // allowed because of the directory prefix. Both were the wrong way round.
    pattern: /^\s*(?:cp|mv|install|sed)\b/,
    test: (segment) => {
      const verb = segment.trim().split(/\s+/)[0];
      const writes = verb !== 'sed' || /\s-i\b/.test(segment);
      return writes && DOTENV_FILE.test(lastArgument(segment).replace(/^\.\//, ''));
    },
    reason:
      'The .env file holds real credentials and is never written by an automated step, whatever writes it.',
  },
  {
    id: 'bypass-hooks-path',
    pattern: /\bgit\b[^\n]*\s-c\s+core\.hooksPath\s*=/,
    reason: 'Pointing core.hooksPath elsewhere disables the checks this workflow runs.',
  },
  {
    id: 'auto-merge-pr-api',
    // gh pr merge is blocked; the REST call underneath does the same thing.
    pattern: /\bgh\s+api\b[^\n]*\/pulls\/\d+\/merge\b/,
    reason:
      'Merging a pull request through the API bypasses the same gate gh pr merge is blocked by.',
  },
];

/**
 * Returns the rule that forbids `command`, or null when it is allowed.
 *
 * @param {string} command
 * @param {string} branch the branch HEAD is on
 */
export const evaluate = (command, branch = '') => {
  if (typeof command !== 'string' || command === '') return null;

  // Branch state is carried across segments rather than matched by text, so
  // `git checkout main && git merge feature/x` is still refused: the first
  // segment moves HEAD to a protected branch and the second is then judged
  // standing on it. That is also what makes the split safe for the merge rule,
  // which used to recognise the pair by reading the whole line.
  let standingOn = branch;

  for (const segment of command.split(SEGMENTS)) {
    if (segment.trim() === '') continue;

    for (const rule of RULES) {
      if (!rule.pattern.test(segment)) continue;
      if (rule.test && !rule.test(segment, standingOn, command)) continue;
      return rule;
    }

    const moved = /\bgit\s+(?:checkout|switch)\s+(?:-\S+\s+)*([A-Za-z0-9_./-]+)/.exec(segment);
    if (moved) standingOn = moved[1];
  }

  return null;
};

const readStdin = () =>
  new Promise((resolve) => {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => (raw += chunk));
    process.stdin.on('end', () => resolve(raw));
    // A hook with no stdin must not hang the session.
    setTimeout(() => resolve(raw), 2000).unref?.();
  });

const main = async () => {
  let event;
  try {
    event = JSON.parse(await readStdin());
  } catch {
    // Nothing judgeable arrived. Staying out of the way beats guessing.
    process.exit(0);
  }

  const command = event?.tool_input?.command;
  const rule = evaluate(command, currentBranch());
  if (rule === null) process.exit(0);

  process.stderr.write(
    `Blocked by .claude/hooks/guard-protected-ops.mjs (rule: ${rule.id})\n\n` +
      `${rule.reason}\n\n` +
      `Command: ${command}\n\n` +
      'If this is genuinely the right action, ask the Tech Lead and run it by hand.\n',
  );
  process.exit(2);
};

// Only act as a hook when executed directly, so selftest.mjs can import the
// rules without the module trying to read stdin. pathToFileURL rather than a
// hand-built file:// string: on Windows the path has a drive letter and
// backslashes, and the two forms would never compare equal.
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
