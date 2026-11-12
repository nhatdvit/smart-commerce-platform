#!/usr/bin/env node
/**
 * Self-test for the workflow hooks.
 *
 *   pnpm claude:selftest
 *
 * A hook that blocks tool calls is infrastructure: if it is wrong it either
 * waves through something destructive, or - the commoner and more corrosive
 * failure - blocks ordinary work until people route around it. Both halves are
 * asserted here, and the "must be allowed" half uses values taken from this
 * repository's own files, because those are exactly what a careless pattern
 * would flag.
 *
 * It imports the rule functions directly rather than piping fixtures through a
 * shell, for a reason worth remembering: the guard matches the command string,
 * so a shell command *containing* a blocked pattern is itself blocked. Driving
 * `evaluate()` in-process keeps the fixtures out of any command line.
 */

import { evaluate } from './guard-protected-ops.mjs';
import { scanDiff } from './guard-secrets.mjs';

let failed = 0;
let passed = 0;

const check = (label, actual, expected) => {
  const ok = actual === expected;
  if (ok) {
    passed += 1;
  } else {
    failed += 1;
    console.error(`  FAIL  ${label}\n          expected ${expected}, got ${actual}`);
  }
};

// ---------------------------------------------------------------------------
// guard-protected-ops: must block
// ---------------------------------------------------------------------------
const MUST_BLOCK = [
  ['git push --force origin main', 'feature/x', 'force-push'],
  ['git push -f', 'feature/x', 'force-push'],

  // SCP-000 F2. Every rule below had exactly one fixture, in its canonical
  // spelling, and allowed the forms people actually mistype. These are
  // accident shapes, not evasion: the guard's purpose is to raise the cost of
  // a slip, and a slip does not use the documented spelling.
  ['git push -fu origin main', 'feature/x', 'force-push'],
  ['git push --mirror origin', 'feature/x', 'force-push'],
  ['git push origin +main', 'feature/x', 'force-refspec'],
  ['git push origin :main', 'feature/x', 'delete-remote-branch'],
  ['git push -d origin feature/old', 'feature/x', 'delete-remote-branch'],
  ['git commit -n -m x', 'feature/x', 'skip-hooks'],
  ['git commit -nm x', 'feature/x', 'skip-hooks'],
  ['echo A>.env', 'feature/x', 'write-dotenv'],
  ['cat x | tee -a .env', 'feature/x', 'write-dotenv'],
  ['cp secrets.txt .env', 'feature/x', 'write-dotenv-indirect'],
  ['sed -i s/a/b/ .env', 'feature/x', 'write-dotenv-indirect'],
  ['docker-compose down -v', 'feature/x', 'compose-down-volumes'],
  ['docker compose down -fv', 'feature/x', 'compose-down-volumes'],
  ['php artisan migrate --env=production', 'feature/x', 'production-migration'],
  ['gh api -X PUT repos/o/r/pulls/3/merge', 'feature/x', 'auto-merge-pr-api'],
  ['git -c core.hooksPath=/dev/null commit -m x', 'feature/x', 'bypass-hooks-path'],
  ['git push --force-with-lease origin feature/x', 'feature/x', 'force-push'],
  ['git push origin +refs/heads/main:refs/heads/main', 'feature/x', 'force-refspec'],
  ['git filter-repo --path secrets --invert-paths', 'feature/x', 'history-rewrite'],
  ['git rebase -i HEAD~5', 'feature/x', 'interactive-rebase'],
  ['git reset --hard origin/main', 'feature/x', 'hard-reset-to-remote'],
  ['git push origin --delete feature/old', 'feature/x', 'delete-remote-branch'],
  ['git checkout main && git merge feature/x', 'feature/x', 'merge-into-protected'],
  ['git merge feature/x', 'main', 'merge-into-protected'],
  ['gh pr merge 35 --squash', 'feature/x', 'auto-merge-pr'],
  [
    'docker compose exec api php artisan migrate:fresh --seed',
    'feature/x',
    'destructive-migration',
  ],
  ['php artisan db:wipe', 'feature/x', 'destructive-migration'],
  ['APP_ENV=production php artisan migrate --force', 'feature/x', 'production-migration'],
  ['docker compose down -v', 'feature/x', 'compose-down-volumes'],
  ['docker compose down --volumes', 'feature/x', 'compose-down-volumes'],
  ['docker volume rm smart-commerce_postgres-data', 'feature/x', 'docker-prune'],
  ['echo "APP_KEY=x" > .env', 'feature/x', 'write-dotenv'],
  ['cat template >> .env', 'feature/x', 'write-dotenv'],
  [
    'docker compose -f infra/compose.yaml -f infra/compose.prod.yaml up -d',
    'feature/x',
    'production-stack',
  ],
  ['git commit --no-verify -m "wip"', 'feature/x', 'skip-hooks'],
];

// ---------------------------------------------------------------------------
// guard-protected-ops: must be allowed. Every one of these is a command this
// repository actually runs.
// ---------------------------------------------------------------------------
const MUST_ALLOW = [
  ['git status --short', 'feature/x'],
  ['git add -A', 'feature/x'],
  ['git commit -m "feat(payment): add VietQR payload [SCP-001]"', 'feature/x'],
  ['git push origin feature/x', 'feature/x'],
  ['git push -u origin chore/SCP-000-ai-dev-workflow', 'chore/SCP-000-ai-dev-workflow'],

  // SCP-000 F2. The broadened patterns above must not start catching ordinary
  // work. -am contains no n; .env.example and .env.backup are not .env; a
  // dry run is not a push.
  ['git commit -am "feat(order): place order [SCP-016]"', 'feature/x'],
  ['git push --dry-run origin feature/x', 'feature/x'],
  ['cp .env.example .env.backup', 'feature/x'],
  ['echo APP_KEY= > .env.example', 'feature/x'],
  ['docker compose down', 'feature/x'],
  ['php artisan migrate', 'feature/x'],
  // Merging main INTO a feature branch is the safe direction.
  ['git merge main', 'feature/x'],
  ['git merge --ff-only origin/main', 'feature/x'],
  ['git rebase main', 'feature/x'],
  ['git log --oneline -5', 'feature/x'],
  ['gh pr create --base main --title x --body-file body.md', 'feature/x'],
  ['gh pr view 35 --json url', 'feature/x'],
  ['pnpm api:migrate', 'feature/x'],
  ['pnpm api:check', 'feature/x'],
  ['pnpm openapi:conformance', 'feature/x'],
  ['docker compose --env-file .env -f infra/compose.yaml up -d', 'feature/x'],
  ['docker compose down', 'feature/x'],
  ['docker compose -f infra/compose.yaml -f infra/compose.prod.yaml config -q', 'feature/x'],
  ['cp .env.example .env.ci', 'feature/x'],
  ['php artisan migrate --force', 'feature/x'],
  ['php artisan migrate:rollback --force', 'feature/x'],
];

console.log('guard-protected-ops.mjs');
for (const [command, branch, expectedId] of MUST_BLOCK) {
  const rule = evaluate(command, branch);
  check(`blocks: ${command}`, rule?.id ?? null, expectedId);
}
for (const [command, branch] of MUST_ALLOW) {
  const rule = evaluate(command, branch);
  check(`allows: ${command}`, rule?.id ?? null, null);
}

// ---------------------------------------------------------------------------
// guard-secrets: must block
// ---------------------------------------------------------------------------
const asDiff = (file, ...lines) =>
  [`--- a/${file}`, `+++ b/${file}`, '@@ -0,0 +1 @@', ...lines.map((line) => `+${line}`)].join(
    '\n',
  );

console.log('\nguard-secrets.mjs');

const secretCases = [
  ['staged .env', { names: ['.env'], diff: '' }],
  ['staged production env', { names: ['apps/api/.env.production'], diff: '' }],
  ['staged private key file', { names: ['infra/certs/server.pem'], diff: '' }],
  ['staged composer auth', { names: ['apps/api/auth.json'], diff: '' }],
  ['staged database dump', { names: ['infra/backups/2026-11-11.dump'], diff: '' }],
  [
    'private key block',
    { names: ['x.txt'], diff: asDiff('x.txt', '-----BEGIN OPENSSH PRIVATE KEY-----') },
  ],
  [
    'github token',
    {
      names: ['x.txt'],
      diff: asDiff('x.txt', 'GH=gho_16C7e42F292c6912E7710c838347Ae178B4a'),
    },
  ],
  ['aws key id', { names: ['x.txt'], diff: asDiff('x.txt', 'id = AKIAIOSFODNN7EXAMPLE') }],
  [
    'real-looking db password',
    { names: ['infra/x.yaml'], diff: asDiff('infra/x.yaml', 'DB_PASSWORD=Hx9kLm2Qp7vRtZw4') },
  ],
  [
    'client secret',
    {
      names: ['config/x.php'],
      diff: asDiff('config/x.php', "'client_secret' => 'A8f3Kd92Lp01Zx'"),
    },
  ],
];

for (const [label, input] of secretCases) {
  check(`blocks: ${label}`, scanDiff(input).length > 0, true);
}

// ---------------------------------------------------------------------------
// guard-secrets: must be allowed. Taken from this repository.
// ---------------------------------------------------------------------------
const cleanCases = [
  ['.env.example is tracked on purpose', { names: ['.env.example'], diff: '' }],
  [
    'test APP_KEY in phpunit.xml',
    {
      names: ['apps/api/phpunit.xml'],
      diff: asDiff(
        'apps/api/phpunit.xml',
        '<env name="APP_KEY" value="base64:Y2hhbmdlbWVjaGFuZ2VtZWNoYW5nZW1lY2hhbmcx"/>',
      ),
    },
  ],
  [
    'CI database password',
    {
      names: ['.github/workflows/ci.yml'],
      diff: asDiff('.github/workflows/ci.yml', 'DB_PASSWORD: scp_test'),
    },
  ],
  [
    'env placeholder',
    { names: ['.env.example'], diff: asDiff('.env.example', 'DB_PASSWORD=change_me_in_local') },
  ],
  [
    'compose interpolation',
    {
      names: ['infra/compose.dev.yaml'],
      diff: asDiff(
        'infra/compose.dev.yaml',
        'GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD:-admin}',
      ),
    },
  ],
  [
    'laravel config reads env',
    {
      names: ['config/services.php'],
      diff: asDiff('config/services.php', "'secret' => env('APP_SECRET')"),
    },
  ],
  [
    'seeder demo password',
    {
      names: ['database/seeders/x.php'],
      diff: asDiff('database/seeders/x.php', "'password' => 'password'"),
    },
  ],
  [
    'documented sample payload',
    {
      names: ['docs/api/README.md'],
      diff: asDiff('docs/api/README.md', '"api_key": "your-key-here"'),
    },
  ],
  [
    'ordinary code change',
    {
      names: ['apps/api/app/Shared/Domain/ValueObject/Money.php'],
      diff: asDiff('apps/api/app/Shared/Domain/ValueObject/Money.php', 'return new self($amount);'),
    },
  ],
];

for (const [label, input] of cleanCases) {
  const findings = scanDiff(input);
  check(`allows: ${label}`, findings.length === 0 ? true : findings.join(' / '), true);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
