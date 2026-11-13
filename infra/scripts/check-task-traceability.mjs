#!/usr/bin/env node
/**
 * Checks that a change is traceable from requirement to review before it can be
 * merged.
 *
 *   pnpm claude:traceability                 # against the current branch
 *   node infra/scripts/check-task-traceability.mjs --task SCP-001
 *
 * Run by .github/workflows/task-traceability.yml on every pull request, and
 * runnable locally so the answer is knowable before pushing.
 *
 * ## What it is for
 *
 * The rest of the workflow is advice: a skill can decline to proceed, but a
 * person can always do the work by hand. This is the one place that refuses at
 * the point of no return. It asks the questions a reviewer would otherwise have
 * to ask every time:
 *
 *   - Which task is this?
 *   - Is there a record of what was asked for and approved?
 *   - Did anyone test it, and did anyone review it?
 *   - Is anything blocking still open?
 *
 * It deliberately does not judge quality. It judges whether the evidence
 * exists, which is the only thing a script can honestly decide.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const BRANCH_PATTERN = /^(feature|fix|refactor|chore|docs|test)\/([A-Z][A-Z0-9]+-\d+)-[a-z0-9-]+$/;
const CONVENTIONAL = /^(feat|fix|refactor|test|docs|chore)(\([a-z0-9-]+\))?!?: .+/;

const argument = (flag) => {
  const index = process.argv.indexOf(flag);
  return index !== -1 ? process.argv[index + 1] : undefined;
};

const git = (args, fallback = '') => {
  try {
    return execFileSync('git', args, {
      encoding: 'utf8',
      timeout: 15000,
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return fallback;
  }
};

const failures = [];
const notes = [];
const fail = (what, why) => failures.push({ what, why });

// ---------------------------------------------------------------------------
// Where we are
// ---------------------------------------------------------------------------
// On a pull request GitHub checks out a merge commit, so HEAD's name is not the
// branch. GITHUB_HEAD_REF carries the real one.
const branch = process.env.GITHUB_HEAD_REF || git(['rev-parse', '--abbrev-ref', 'HEAD']);
const baseRef = process.env.GITHUB_BASE_REF || 'main';
const prTitle = process.env.PR_TITLE ?? '';

console.log(`branch   ${branch || '(unknown)'}`);
console.log(`base     ${baseRef}`);
if (prTitle !== '') console.log(`pr title ${prTitle}`);

// ---------------------------------------------------------------------------
// 1. Branch names the task
// ---------------------------------------------------------------------------
const matched = BRANCH_PATTERN.exec(branch);
const taskId = argument('--task') ?? matched?.[2];

if (!matched) {
  fail(
    'branch name',
    `"${branch}" does not match <type>/<TASK-ID>-<description>, for example ` +
      'feature/SCP-001-vietqr-payload. The task id is read from here by the ' +
      'activity-log hook and by this gate.',
  );
}

if (!taskId) {
  // Nothing further can be checked without an id.
  report();
}

console.log(`task     ${taskId}`);

// ---------------------------------------------------------------------------
// 2. The task record exists and is in the right state
// ---------------------------------------------------------------------------
const taskPath = join('.claude', 'tasks', `${taskId}.md`);
let task = '';

if (!existsSync(taskPath)) {
  fail(
    'task record',
    `${taskPath} does not exist. Every change needs a record of what was asked ` +
      'for and approved; run /task to open one.',
  );
} else {
  task = readFileSync(taskPath, 'utf8');

  const status = /\|\s*\*\*Status\*\*\s*\|\s*([A-Z_]+)\s*\|/.exec(task)?.[1];
  if (!status) {
    fail('task status', `${taskPath} has no parseable Status row.`);
  } else if (status !== 'READY_FOR_PR') {
    fail(
      'task status',
      `${taskId} is ${status}, not READY_FOR_PR. A pull request means review is ` +
        'finished; if it is not, finish /review first.',
    );
  }

  // 3. Unresolved blocking findings
  for (const severity of ['CRITICAL', 'HIGH']) {
    const row = new RegExp(`\\|\\s*${severity}\\s*\\|\\s*([^|]*)\\|`).exec(task);
    if (!row) {
      fail(
        'review findings',
        `${taskPath} has no ${severity} row in the Review Findings table. Keep the ` +
          'table shape from _TEMPLATE.md; this gate parses it.',
      );
      continue;
    }
    const value = row[1].trim();
    if (value !== '0') {
      fail(
        'review findings',
        `${severity} unresolved is "${value}" in ${taskPath}. A pull request cannot ` +
          'carry an unresolved CRITICAL or HIGH finding.',
      );
    }
  }

  // 4. Blockers
  const blockers = /##\s*Blockers\s*\n([\s\S]*?)(?:\n##\s|$)/.exec(task)?.[1]?.trim() ?? '';
  if (blockers === '') {
    notes.push(`${taskPath} has an empty Blockers section. "none" is an answer; blank is not.`);
  }
}

// ---------------------------------------------------------------------------
// 5. Review and test reports exist
// ---------------------------------------------------------------------------
for (const [file, why] of [
  ['test-report.md', 'nobody recorded running the tests'],
  ['code-review.md', 'nobody recorded reviewing the diff'],
]) {
  const path = join('.claude', 'reviews', taskId, file);
  if (!existsSync(path)) {
    fail('evidence', `${path} does not exist, so ${why}.`);
  }
}

// A security review is required when the diff touches what it is for.
// Distinguishing "nothing changed" from "could not tell" matters: the first is
// a branch with no commits yet, the second is a check that did not run. A tool
// that reports them with the same words cannot be trusted about either.
const baseExists = git(['rev-parse', '--verify', `origin/${baseRef}`], '') !== '';
const changed = baseExists
  ? git(['diff', '--name-only', `origin/${baseRef}...HEAD`], '')
      .split('\n')
      .filter((line) => line.trim() !== '')
  : [];

const SECURITY_TRIGGERS = [
  /apps\/api\/app\/Modules\/Payment\//,
  /apps\/api\/app\/Modules\/Identity\//,
  /store_bank_account/,
  /payment/i,
  /auth/i,
  /\.env\.example$/,
  /infra\/(nginx|compose)/,
];

if (!baseExists) {
  notes.push(
    `origin/${baseRef} is not available in this checkout, so the security-review ` +
      'requirement could not be evaluated. In CI, fetch-depth: 0 makes it available.',
  );
} else if (changed.length === 0) {
  notes.push(`No files differ from origin/${baseRef}, so no security review is required yet.`);
} else {
  const triggered = changed.filter((file) => SECURITY_TRIGGERS.some((rule) => rule.test(file)));
  if (triggered.length > 0) {
    const path = join('.claude', 'reviews', taskId, 'security-review.md');
    if (!existsSync(path)) {
      fail(
        'evidence',
        `${path} does not exist, but this change touches ${triggered.length} file(s) that ` +
          `need a security review, starting with ${triggered[0]}.`,
      );
    }
  }
}

// ---------------------------------------------------------------------------
// 6. Pull request title and commit subjects carry the task id
// ---------------------------------------------------------------------------
if (prTitle !== '' && !prTitle.includes(taskId)) {
  fail('pr title', `"${prTitle}" does not mention ${taskId}.`);
}

// %p is the parent list, so a merge commit is the one with two parents, and it
// is skipped. A merge subject cannot be a Conventional Commit and carries no
// task of its own; meanwhile merging the base branch in to refresh a stale
// branch is ordinary work the workflow documentation tells people to do. A
// gate that failed on merges would make that advice impossible to follow.
// Found by merging origin/main into the SCP-000 branch itself.
const subjects = baseExists
  ? git(['log', '--format=%p|%s', `origin/${baseRef}..HEAD`], '')
      .split('\n')
      .filter((line) => line.trim() !== '')
      .map((line) => {
        const cut = line.indexOf('|');
        return { parents: line.slice(0, cut).trim(), subject: line.slice(cut + 1) };
      })
      .filter((commit) => commit.parents.split(' ').filter(Boolean).length < 2)
      .map((commit) => commit.subject)
  : [];

if (subjects.length === 0) {
  notes.push(
    baseExists
      ? `This branch has no commits beyond origin/${baseRef}, so commit subjects were not checked.`
      : `origin/${baseRef} is unavailable, so commit subjects were not checked.`,
  );
}

for (const subject of subjects) {
  if (!subject.includes(`[${taskId}]`)) {
    fail('commit message', `"${subject}" does not end with [${taskId}].`);
  }
  if (!CONVENTIONAL.test(subject)) {
    fail(
      'commit message',
      `"${subject}" is not a Conventional Commit subject. The scope is lowercase — ` +
        `the task id belongs in the [${taskId}] suffix, not in the scope.`,
    );
  }
}

report();

function report() {
  console.log('');
  for (const note of notes) console.log(`note  ${note}`);

  if (failures.length === 0) {
    console.log(`\nTraceable: ${taskId ?? 'task'} has a record, evidence and clean findings.`);
    process.exit(0);
  }

  console.error(`\n${failures.length} problem(s):\n`);
  for (const { what, why } of failures) console.error(`  [${what}] ${why}\n`);
  console.error('This gate checks that the evidence exists, not that the code is good.');
  process.exit(1);
}
