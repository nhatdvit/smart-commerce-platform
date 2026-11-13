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
import { readFileSync, statSync } from 'node:fs';
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
/**
 * key = "value" where the key NAMES a secret, including the snake_case prefixes
 * this project actually uses.
 *
 * SCP-000 review S2: the old pattern required a word boundary immediately
 * before `password`, and `_` is a word character - so there is no boundary in
 * `MAIL_PASSWORD`. It matched exactly one of this repository's own credential
 * variables, `DB_PASSWORD`, which was present as a hand-added special case.
 * `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `MINIO_ROOT_PASSWORD`,
 * `GRAFANA_ADMIN_PASSWORD`, `AWS_SECRET_ACCESS_KEY` and `APP_KEY` - the names in
 * .env.example - all passed.
 *
 * The prefix is `(?:[A-Za-z0-9]+[_-])*` rather than anything looser so that
 * `key` can be in the list without `MONKEY` matching: a prefixed name has to
 * carry a separator.
 */
export const ASSIGNMENT =
  /(^|[^A-Za-z0-9_-])(?:[A-Za-z0-9]+[_-])*(password|passwd|secret|token|key|api[_-]?key|apikey|access[_-]?token|auth[_-]?token|private[_-]?key|client[_-]?secret)['"]?\s*(?:=>|:=|[:=])\s*['"]?([^'"\s,;)]{5,})['"]?/i;

/**
 * Markers that say "this is an example", matched at the START of the value or
 * as the whole value - never as a substring.
 *
 * SCP-000 review S2: `looksLikePlaceholder` used to pass when the value merely
 * *contained* a marker, and the list included `secret`, `password` and `test`.
 * So `Secret!Prod#2026aQ`, `Password#Prod2026!` and `Hx9kLm2Qp7vtestRtZw4` were
 * all waved through - a credential excused for containing the word for what it
 * is. `secret`, `password` and `test` are now exact-match only.
 */
export const PREFIX_MARKERS = [
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
  'fake',
  'null',
  'none',
  '<',
  '${',
  'env(',
  'config(',
];

/** Values that are placeholders in full, including this repository's own. */
export const EXACT_MARKERS = [
  'password',
  'secret',
  'test',
  'changeme',
  'scp_test',
  'change_me_in_local',
];

/** Kept as the union, so anything importing the old name still sees the list. */
export const PLACEHOLDERS = [...PREFIX_MARKERS, ...EXACT_MARKERS];

const startsWithMarker = (value) =>
  PREFIX_MARKERS.some((marker) => {
    if (!value.startsWith(marker)) return false;
    // A marker that ends in a letter or digit must end the value or be
    // followed by a separator, so `change_me_in_local` is a placeholder and
    // `changed-prod-key-9x` is not. A marker that already ends in punctuation
    // - `your-`, `${`, `<`, `env(` - carries its own boundary, and demanding
    // another one would reject the very values it exists to allow.
    if (!/[a-z0-9]$/.test(marker)) return true;
    const next = value.charAt(marker.length);
    return next === '' || !/[a-z0-9]/.test(next);
  });

export const looksLikePlaceholder = (value) => {
  const lower = String(value).toLowerCase();
  if (EXACT_MARKERS.includes(lower)) return true;
  if (startsWithMarker(lower)) return true;

  // A Laravel APP_KEY is base64 of the real bytes, so the marker is inside the
  // encoding rather than the text. This keeps phpunit.xml's documented test key
  // allowed while a real random APP_KEY is still caught.
  const encoded = /^base64:([A-Za-z0-9+/=]+)$/i.exec(String(value));
  if (encoded) {
    try {
      const decoded = Buffer.from(encoded[1], 'base64').toString('utf8').toLowerCase();
      if (EXACT_MARKERS.some((m) => decoded.includes(m))) return true;
      if (PREFIX_MARKERS.some((m) => decoded.startsWith(m))) return true;
    } catch {
      // Not decodable: fall through and treat it as a real value.
    }
  }

  return false;
};

/**
 * @param {{ names: string[], diff: string }} input
 * @returns {string[]} one line per finding, empty when the change is clean
 */
/**
 * The two files whose job is to contain credential-shaped text.
 *
 * SCP-000 review S11: the guard refused to let anyone commit its own test
 * file, and the refusal message told the developer to widen the pattern - a
 * remedy that degrades the control. Both of these are read closely in review,
 * and a real credential hidden in either would be a deliberate act, not the
 * accident this guard is priced for. Content rules are skipped; the path rules
 * still apply, and nothing else is exempt.
 */
const FIXTURE_FILES = new Set(['.claude/hooks/selftest.mjs', '.claude/hooks/guard-secrets.mjs']);

const isFixtureFile = (path) => FIXTURE_FILES.has(String(path).replace(/\\/g, '/').trim());

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
    if (isFixtureFile(currentFile)) continue;

    const added = line.slice(1);

    for (const shape of CREDENTIAL_SHAPES) {
      if (shape.pattern.test(added)) {
        findings.push(`${currentFile}  -  looks like a ${shape.name}`);
      }
    }

    // Groups: 1 is the character before the key (so the name can be prefixed),
    // 2 is the credential word, 3 is the value.
    const assignment = ASSIGNMENT.exec(added);
    if (assignment && !looksLikePlaceholder(assignment[3])) {
      findings.push(
        `${currentFile}  -  ${assignment[2]} is assigned a value that does not look like a placeholder`,
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

const scanIndex = () =>
  scanDiff({
    names: git(['diff', '--cached', '--name-only']).split('\n'),
    diff: git(['diff', '--cached', '--unified=0']),
  });

/**
 * What a commit that stages first is about to include: everything already
 * different from HEAD, plus every untracked file git would pick up.
 *
 * Untracked files have no diff, so their contents are turned into one. Without
 * that, a brand-new file holding a credential - the commonest way one appears -
 * would be named but never read by the content rules.
 */
const scanWorkingTree = () => {
  const tracked = git(['diff', 'HEAD', '--name-only']);
  const untracked = git(['ls-files', '--others', '--exclude-standard']);
  const files = untracked.split('\n').filter((line) => line.trim() !== '');

  let diff = git(['diff', 'HEAD', '--unified=0']);
  for (const file of files) {
    let body;
    try {
      // A credential is a line, not a payload. Anything enormous is a build
      // artefact or a fixture, and reading it whole would cost more than it
      // can possibly catch.
      if (statSync(file).size > 2 * 1024 * 1024) continue;
      body = readFileSync(file, 'utf8');
    } catch {
      continue;
    }
    diff += `\n--- a/${file}\n+++ b/${file}\n@@ -0,0 +1 @@\n`;
    diff += body
      .split('\n')
      .map((line) => `+${line}`)
      .join('\n');
  }

  return scanDiff({ names: `${tracked}\n${untracked}`.split('\n'), diff });
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
  // SCP-000 review S5: `git -C . commit` and `git -c user.name=x commit` are
  // ordinary idioms, and the old filter required `commit` to follow `git`
  // immediately, so both skipped the scan entirely.
  if (!/\bgit\b[^\n]*\bcommit\b/.test(command)) process.exit(0);

  // SCP-000 review S1, the defect that mattered most. PreToolUse fires BEFORE
  // the shell runs, so for `git add -A && git commit ...` nothing is staged
  // yet and `git diff --cached` is empty - the scan ran, saw nothing, and the
  // commit went through with the credential in it. Whenever the command will
  // stage something, look at the working tree instead of the index.
  const stages =
    /\bgit\b[^\n]*\s(?:add|stage)\b/.test(command) ||
    /\s--all\b|\s-[A-Za-z]*a[A-Za-z]*(\s|$)/.test(command);

  const findings = stages ? scanWorkingTree() : scanIndex();

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
