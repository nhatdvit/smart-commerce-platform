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
    id: 'auto-merge-pr',
    pattern: /\bgh\s+pr\s+merge\b/,
    reason: 'Pull requests are never merged automatically. A reviewer merges, after CI is green.',
  },
  {
    id: 'destructive-migration',
    pattern: /\bartisan\s+(migrate:fresh|migrate:refresh|db:wipe)\b/,
    reason:
      'CLAUDE.md §7 forbids migrate:fresh, migrate:refresh and db:wipe. They drop every table. Use a reviewed forward migration.',
  },
  {
    id: 'production-migration',
    // `pnpm api:migrate` against the dev stack stays allowed; what is blocked
    // is a migration aimed at a production environment.
    pattern: /\bartisan\s+migrate\b/,
    test: (command) =>
      /\bAPP_ENV\s*=\s*production\b/.test(command) ||
      /--env[=\s]+production\b/.test(command) ||
      /compose\.prod/.test(command),
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
    pattern: /(>{1,2}|tee\s+(-a\s+)?)\s*(\.\/)?\.env(\s|$|;)/,
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
    pattern:
      /\b(cp|mv|install)\b[^\n]*\s(\.\/)?\.env(\s|$|;)|\bsed\b[^\n]*\s-i\b[^\n]*\s(\.\/)?\.env(\s|$|;)/,
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
  for (const rule of RULES) {
    if (!rule.pattern.test(command)) continue;
    if (rule.test && !rule.test(command, branch)) continue;
    return rule;
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
