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
  // `.npmrc` is deliberately NOT here. This repository tracks one, and it holds
  // pnpm resolution settings and no credentials - so a path rule on it blocked
  // every commit that touched it, measured in the SCP-000 S15 sweep. What is
  // actually dangerous is an auth token inside it, and that is a content rule
  // in CREDENTIAL_SHAPES, which catches it in any file rather than only this one.
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
    // Replaces the `.npmrc` path rule: this is the thing that made an .npmrc
    // dangerous, and it is caught wherever it appears.
    name: 'npm auth token',
    pattern: /\b_auth(?:Token)?\s*=\s*\S{8,}/,
  },
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
  // Well-known joke credentials, used as fixtures precisely because everyone
  // recognises them as fake. `hunter2` is in this repository's own redaction
  // test, where the point of the file is to contain a fake password.
  'hunter2',
  'correcthorsebatterystaple',
  's3cr3t',
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

/**
 * Does this value look like a literal credential, as opposed to an expression,
 * a version or an ordinary word?
 *
 * SCP-000 review S15/H1 measured 33 false findings across 24 of this
 * repository's own files. Every one of them failed on the *value*, not the key:
 *
 *   foreach ($x as $key => $value)   an expression
 *   key={item.id}                    an expression
 *   path-key: 3.1.1                  a version
 *   artisan key:generate             a word
 *
 * The reviewer's suggested remedy was to make all content rules advisory. That
 * would also stop blocking `MAIL_PASSWORD=<a real value>`, which fix round 2
 * had just closed as S2 - trading one HIGH for another. Grading by value shape
 * keeps both: an expression, a version or a bare word is never blocked, and a
 * mixed-class literal still is.
 *
 * Anything this rejects is still reported as advisory, so nothing goes unseen.
 */
export const looksLikeCredentialValue = (value) => {
  const candidate = String(value).trim();
  if (candidate.length < 6) return false;

  // An expression, interpolation or template - not a literal.
  if (/^[$%@{<(\[]/.test(candidate)) return false;
  // A version or any pure number.
  if (/^[\d.]+$/.test(candidate)) return false;
  // One ordinary word, in any case: `generate`, `value`, `true`, `SAMEORIGIN`.
  if (/^[A-Za-z]+$/.test(candidate)) return false;
  // A dotted or dashed identifier with no digits: `path.to.thing`, `no-referrer`.
  if (/^[A-Za-z]+(?:[.\-_][A-Za-z]+)*$/.test(candidate)) return false;

  // Markers of code, shell, templating or a regular expression, anywhere in the
  // value rather than only at the front. Measured against this repository, this
  // is what the remaining false positives all had in common:
  //
  //   key: composer-${{ runner.os }}          a GitHub Actions cache key
  //   sed 's|^APP_KEY=.*|APP_KEY=...|'        a sed expression
  //   'validate_csrf_token' => Class::class   a PHP class constant
  //   grep -qE '^APP_KEY=base64:.+'           a regex
  //   const key = segment.replace(/~1/g, ...) JavaScript
  //
  // None of these characters appears in a generated credential - with the
  // deliberate exception of `/` and `+`, which base64 uses, so neither is here.
  if (/\$\{|\{\{|\}\}|::|->|=>|[()|*\\]|\.\*|\.\+/.test(candidate)) return false;

  // What is left has to mix classes the way generated secrets do: letters with
  // digits, or letters with punctuation that is not just a separator.
  const hasLetter = /[A-Za-z]/.test(candidate);
  const hasDigit = /\d/.test(candidate);
  const hasSymbol = /[^A-Za-z0-9]/.test(candidate);
  return hasLetter && (hasDigit || hasSymbol);
};

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

/**
 * Directories whose whole job is to write about credentials.
 *
 * SCP-000 review H2: the exemption covered the two hook files and not
 * `.claude/reviews/`, so a review report could not quote the string it was
 * reporting on - and the traceability gate requires those reports to exist.
 * Both reports in the second review pass were blocked by this. The task ledger
 * is here for the same reason: it quotes the findings.
 *
 * Only *content* scanning is skipped. The path rules still apply, so a real
 * `.env` or `.pem` under either directory is still refused.
 */
const FIXTURE_PREFIXES = ['.claude/reviews/', '.claude/tasks/'];

const isFixtureFile = (path) => {
  const file = String(path).replace(/\\/g, '/').trim();
  return FIXTURE_FILES.has(file) || FIXTURE_PREFIXES.some((prefix) => file.startsWith(prefix));
};

/**
 * @returns {{ blocking: string[], advisory: string[] }}
 *
 * SCP-000 review S15/H1, and the reason the return shape is split in two.
 *
 * The widened key matching refused 24 of this repository's own files over 33
 * findings, none of them a credential: `foreach ($x as $key => $value)`, a
 * React `key` prop, `path-key: 3.1.1`, the documented `artisan key:generate`
 * command, the shared idempotency-key constant. The block was `exit 2`, and its
 * own message recommended weakening the pattern.
 *
 * Both reviewers rated that HIGH, and the reasoning is what settled the design:
 * the expected outcome of a guard that blocks ordinary work is not noise, it is
 * the removal of the guard - either the pattern gets weakened, or people commit
 * outside the tool it hooks. Either way S1 and S2 come back.
 *
 * So the rules are now graded by how certain they are:
 *
 *   blocking  a forbidden path, or a shape that is a credential and nothing
 *             else (private key block, `gh?_` token, AKIA, AIza, xox, sk_live,
 *             JWT). These have no plausible innocent reading.
 *   advisory  the generic `key = value` heuristic. Reported, never blocking,
 *             because it cannot tell a secret from a loop variable.
 *
 * The security reviewer named this exact split as what would move the finding
 * to MEDIUM.
 */
export const scanDiff = ({ names = [], diff = '' }) => {
  const blocking = [];
  const advisory = [];

  for (const path of names) {
    const file = String(path).trim();
    if (file === '') continue;
    for (const rule of FORBIDDEN_PATHS) {
      if (rule.pattern.test(file)) {
        blocking.push(`${file}  -  ${rule.reason} must never be committed`);
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
        blocking.push(`${currentFile}  -  looks like a ${shape.name}`);
      }
    }

    // Groups: 1 is the character before the key (so the name can be prefixed),
    // 2 is the credential word, 3 is the value.
    const assignment = ASSIGNMENT.exec(added);
    if (assignment && !looksLikePlaceholder(assignment[3])) {
      const finding = `${currentFile}  -  ${assignment[2]} is assigned a value that does not look like a placeholder`;
      // Graded, not all-or-nothing: see looksLikeCredentialValue.
      (looksLikeCredentialValue(assignment[3]) ? blocking : advisory).push(finding);
    }
  }

  return { blocking: [...new Set(blocking)], advisory: [...new Set(advisory)] };
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

  // Two defects, one cause: the index is not a reliable picture of what a
  // commit will contain.
  //
  // SCP-000 review S1, the one that mattered most: PreToolUse fires BEFORE the
  // shell runs, so for `git add -A && git commit ...` nothing is staged yet,
  // `git diff --cached` is empty, and the scan saw nothing while the commit
  // went through with the credential in it.
  // SCP-000 review S16/M5: `git commit <path>` and `git commit -- <path>` commit
  // that path directly without touching the index, so the index scan saw
  // nothing. Measured: the same credential in the same file committed silently
  // with a pathspec and was blocked with `git add -A`. Rather than parse a
  // pathspec out of a shell string - which is where S1 and S5 came from - both
  // views are scanned and the findings unioned. That is affordable now that
  // blocking is limited to forbidden paths and unmistakable shapes, so a wider
  // look cannot produce a wider false block.
  const index = scanIndex();
  const worktree = scanWorkingTree();

  const blocking = [...new Set([...index.blocking, ...worktree.blocking])];
  const advisory = [...new Set([...index.advisory, ...worktree.advisory])];

  if (advisory.length > 0) {
    process.stderr.write(
      'Note from .claude/hooks/guard-secrets.mjs - not blocking:\n\n' +
        advisory.map((finding) => `  ${finding}`).join('\n') +
        '\n\nThe generic key=value heuristic cannot tell a secret from a loop\n' +
        'variable, so it only reports. Look, and if one of these is real, remove\n' +
        'it before committing.\n',
    );
  }

  if (blocking.length === 0) process.exit(0);

  process.stderr.write(
    'Blocked by .claude/hooks/guard-secrets.mjs\n\n' +
      'This commit contains a forbidden path or an unmistakable credential. A\n' +
      'secret in git history stays in every clone and fork, so it is refused.\n\n' +
      blocking.map((finding) => `  ${finding}`).join('\n') +
      '\n\nRemove it from the change. These rules match forbidden file types and\n' +
      'token shapes that have no innocent reading, so if one has fired wrongly,\n' +
      'that is a defect in the rule - raise it rather than working around it.\n',
  );
  process.exit(2);
};

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
