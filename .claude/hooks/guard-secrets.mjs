#!/usr/bin/env node
/**
 * PreToolUse guard: refuses a commit that would put a credential in git history.
 *
 * Registered on every Bash call in .claude/settings.json and filtered here, on
 * the command itself. A scan on every file write would be neither cheap nor
 * more effective, so the filter stays — but it lives in this file.
 *
 * The `if: "Bash(git commit *)"` pre-filter it used to carry was removed in
 * SCP-000 F1. `git add -A && git commit ...` does not start with `git commit`,
 * so a prefix pattern could skip the scan entirely on the most common way to
 * commit — and a guard that silently does not run is worse than no guard,
 * because it is trusted. One Node start per shell call is a fair price.
 *
 * A secret in git history is not fixed by a follow-up commit: it stays in every
 * clone and every fork. That is why this blocks rather than warns.
 *
 * ## Why the patterns are split in two
 *
 * False positives are the real failure mode of a tool like this. A developer
 * who learns to work around the guard has lost nothing but time, and the guard
 * has lost all of its value. So there are two tiers: shapes that are
 * unmistakably credentials, and a generic key=value rule that stands down when
 * the value looks like a placeholder. This repository is full of legitimate
 * placeholders - .env.example, the test APP_KEY in phpunit.xml, the CI
 * password, sample payloads in docs - and none of them may trip it.
 *
 * `scanDiff` is exported and pure so selftest.mjs can prove both halves:
 * that real secrets are caught, and that the repository's own examples are not.
 */

import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

/** Paths that must never be committed, whatever is inside them. */
export const FORBIDDEN_PATHS = [
  { pattern: /(^|\/)\.env$/, reason: 'the real environment file' },
  { pattern: /(^|\/)\.env\.(local|production|prod|staging)$/, reason: 'a real environment file' },
  { pattern: /\.(pem|key|p12|pfx|jks|keystore)$/, reason: 'a private key or keystore' },
  { pattern: /(^|\/)id_(rsa|dsa|ecdsa|ed25519)$/, reason: 'an SSH private key' },
  { pattern: /(^|\/)auth\.json$/, reason: 'Composer credentials' },
  { pattern: /(^|\/)\.npmrc$/, reason: 'npm credentials' },
  { pattern: /\.dump$|\.sql\.gz$/, reason: 'a database dump, which contains customer data' },
];

/** Shapes that are a credential and nothing else. */
export const CREDENTIAL_SHAPES = [
  { name: 'private key block', pattern: /-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----/ },
  { name: 'GitHub token', pattern: /\bgh[pousr]_[A-Za-z0-9]{30,}/ },
  { name: 'AWS access key id', pattern: /\bAKIA[0-9A-Z]{16}\b/ },
  { name: 'Google API key', pattern: /\bAIza[0-9A-Za-z_-]{35}\b/ },
  { name: 'Slack token', pattern: /\bxox[baprs]-[A-Za-z0-9-]{10,}/ },
  { name: 'Stripe secret key', pattern: /\bsk_(live|test)_[A-Za-z0-9]{20,}/ },
  {
    name: 'JSON web token',
    pattern: /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/,
  },
];

/**
 * key = "value" where the key names a secret. Checked against PLACEHOLDERS.
 *
 * The separator has to cover four syntaxes this repository actually uses, and
 * the optional quote before it matters: PHP writes `'client_secret' => '...'`
 * and JSON writes `"client_secret": "..."`, both of which close the key's quote
 * before the separator appears.
 *
 *   DB_PASSWORD=value          .env
 *   DB_PASSWORD: value         YAML
 *   'client_secret' => 'value' PHP
 *   "client_secret": "value"   JSON
 */
export const ASSIGNMENT =
  /\b(password|passwd|secret|api[_-]?key|apikey|access[_-]?token|auth[_-]?token|private[_-]?key|client[_-]?secret|db[_-]?password)\b['"]?\s*(?:=>|:=|[:=])\s*['"]?([^'"\s,;)]{8,})['"]?/i;

/** Markers that say "this is an example". */
export const PLACEHOLDERS = [
  'change',
  'example',
  'placeholder',
  'your-',
  'your_',
  'xxx',
  '***',
  'redacted',
  'dummy',
  'sample',
  'test',
  'fake',
  'null',
  'none',
  'secret',
  'password',
  '<',
  '${',
  'env(',
  'config(',
];

export const looksLikePlaceholder = (value) => {
  const lower = String(value).toLowerCase();
  return PLACEHOLDERS.some((marker) => lower.includes(marker));
};

/**
 * @param {{ names: string[], diff: string }} input
 * @returns {string[]} one line per finding, empty when the change is clean
 */
export const scanDiff = ({ names = [], diff = '' }) => {
  const findings = [];

  for (const path of names) {
    const file = String(path).trim();
    if (file === '') continue;
    for (const rule of FORBIDDEN_PATHS) {
      if (rule.pattern.test(file)) {
        findings.push(`${file}  -  ${rule.reason} must never be committed`);
      }
    }
  }

  // Only added lines matter. An existing value is already in history, and
  // flagging it here would block every unrelated commit until someone purged it.
  let currentFile = '';
  for (const line of diff.split('\n')) {
    if (line.startsWith('+++ b/')) {
      currentFile = line.slice(6).trim();
      continue;
    }
    if (!line.startsWith('+') || line.startsWith('+++')) continue;

    const added = line.slice(1);

    for (const shape of CREDENTIAL_SHAPES) {
      if (shape.pattern.test(added)) {
        findings.push(`${currentFile}  -  looks like a ${shape.name}`);
      }
    }

    const assignment = ASSIGNMENT.exec(added);
    if (assignment && !looksLikePlaceholder(assignment[2])) {
      findings.push(
        `${currentFile}  -  ${assignment[1]} is assigned a value that does not look like a placeholder`,
      );
    }
  }

  return [...new Set(findings)];
};

const git = (args) => {
  try {
    return execFileSync('git', args, {
      encoding: 'utf8',
      timeout: 15000,
      maxBuffer: 32 * 1024 * 1024,
      stdio: ['ignore', 'pipe', 'ignore'],
    });
  } catch {
    return '';
  }
};

const readStdin = () =>
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
    event = JSON.parse(await readStdin());
  } catch {
    process.exit(0);
  }

  const command = event?.tool_input?.command ?? '';
  if (!/\bgit\s+commit\b/.test(command)) process.exit(0);

  // `git commit -a` stages at commit time, so the index is not yet the whole
  // truth. Comparing against HEAD covers both shapes.
  const stagesEverything = /\s--all\b|\s-[A-Za-z]*a[A-Za-z]*(\s|$)/.test(command);
  const range = stagesEverything ? ['HEAD'] : ['--cached'];

  const findings = scanDiff({
    names: git(['diff', ...range, '--name-only']).split('\n'),
    diff: git(['diff', ...range, '--unified=0']),
  });

  if (findings.length === 0) process.exit(0);

  process.stderr.write(
    'Blocked by .claude/hooks/guard-secrets.mjs\n\n' +
      'This commit appears to contain a credential. A secret in git history stays\n' +
      'in every clone and fork, so it is refused rather than reported.\n\n' +
      findings.map((finding) => `  ${finding}`).join('\n') +
      '\n\nIf every one of these is a placeholder, the pattern needs widening in\n' +
      '.claude/hooks/guard-secrets.mjs - fix it there, in review, rather than\n' +
      'bypassing the check.\n',
  );
  process.exit(2);
};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
