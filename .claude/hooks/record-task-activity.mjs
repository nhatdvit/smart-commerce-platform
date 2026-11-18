#!/usr/bin/env node
/**
 * PostToolUse recorder: appends commits and pull requests to the task log.
 *
 * Registered in .claude/settings.json on every Bash call, and filters on the
 * command itself (see the regexes below).
 *
 * It used to carry an `if: "Bash(git commit *)"` pre-filter to avoid starting
 * Node on every shell call. That was removed in SCP-000 F1: the natural commit
 * idiom is `git add -A && git commit ...`, which does not *start* with
 * `git commit`, so a prefix pattern can miss the very commits this exists to
 * record. The saving was one Node start; the cost was a silent gap in the
 * ledger. The script exits immediately when the command is not interesting.
 *
 * Why this exists: the brief for this workflow is explicit that git commit
 * messages must not be the only task history. A task record that someone has
 * to remember to update is a task record that drifts, so the two facts worth
 * auditing - which commit, which pull request - are written by the machine
 * that just produced them.
 *
 * It reads those facts from git and gh rather than from the hook payload. The
 * payload's shape is an implementation detail; `git log -1` is not.
 *
 * It never fails a tool call. A recorder that can block work would be a worse
 * problem than a missing log line, so every failure path exits 0 quietly.
 */

import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const MARKER = '<!-- activity-log:appended-by-hook -->';
const TASK_ID = /\b([A-Z][A-Z0-9]+-\d+)\b/;

const run = (file, args) => {
  try {
    return execFileSync(file, args, {
      encoding: 'utf8',
      timeout: 10000,
      stdio: ['ignore', 'pipe', 'ignore'],
    }).trim();
  } catch {
    return '';
  }
};

const read = () =>
  new Promise((resolve) => {
    let raw = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (chunk) => (raw += chunk));
    process.stdin.on('end', () => resolve(raw));
    setTimeout(() => resolve(raw), 2000).unref?.();
  });

const main = async () => {
  let event;
  try {
    event = JSON.parse(await read());
  } catch {
    process.exit(0);
  }

  const command = event?.tool_input?.command ?? '';
  const projectDir = process.env.CLAUDE_PROJECT_DIR ?? process.cwd();

  // The task id comes from the branch name, which the workflow requires to
  // carry it. A branch without one is not part of a tracked task, and guessing
  // from the commit message would attribute work to the wrong task.
  const branch = run('git', ['rev-parse', '--abbrev-ref', 'HEAD']);
  const fromBranch = TASK_ID.exec(branch);
  if (!fromBranch) process.exit(0);

  const taskId = fromBranch[1];
  const taskFile = join(projectDir, '.claude', 'tasks', `${taskId}.md`);
  if (!existsSync(taskFile)) process.exit(0);

  // Only append below the marker, so a task file that has not opted in - or
  // that someone restructured - is left alone instead of being corrupted.
  let existing;
  try {
    existing = readFileSync(taskFile, 'utf8');
  } catch {
    process.exit(0);
  }
  if (!existing.includes(MARKER)) process.exit(0);

  const when = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
  const rows = [];

  if (/\bgit\s+commit\b/.test(command)) {
    const sha = run('git', ['log', '-1', '--format=%H']);
    const subject = run('git', ['log', '-1', '--format=%s']);
    // Compare against the short sha wrapped in backticks, because that is the
    // form actually written below. Checking the full sha would never match, so
    // a hook that fired twice - a retried commit, a `commit --amend` - would
    // append the same commit again.
    const short = sha.slice(0, 7);
    if (sha !== '' && !existing.includes(`\`${short}\``)) {
      const files = run('git', ['show', '--stat', '--format=', '--name-only', sha])
        .split('\n')
        .filter((line) => line.trim() !== '').length;
      rows.push(
        `| ${when} | commit | \`${short}\` ${subject.replace(/\|/g, '\\|')} (${files} file${files === 1 ? '' : 's'}) |`,
      );
    }
  }

  if (/\bgh\s+pr\s+create\b/.test(command)) {
    const url = run('gh', ['pr', 'view', '--json', 'url', '--jq', '.url']);
    if (url.startsWith('http') && !existing.includes(url)) {
      rows.push(`| ${when} | pull request | ${url} |`);
    }
  }

  if (rows.length === 0) process.exit(0);

  try {
    appendFileSync(taskFile, `${rows.join('\n')}\n`, 'utf8');
  } catch {
    // Read-only checkout, locked file, anything: not worth interrupting work.
  }

  process.exit(0);
};

main();
