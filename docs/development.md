# Local development

Day-to-day operation of the stack, and what to do when it misbehaves.
First-time setup is in the [root README](../README.md).

Throughout, this is the Compose invocation — run from the repository root so
`--env-file` finds `.env`:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml
```

`pnpm infra:up`, `infra:down`, `infra:logs` and the `pnpm api:*` scripts are
thin wrappers around it.

## Daily loop

```bash
pnpm infra:up
```

The dev override bind-mounts `app/`, `config/`, `database/`, `routes/` and
`tests/` into the api container and turns OPcache timestamp validation back on,
so edited PHP takes effect without a rebuild. `vendor/` stays inside the image
— it is built for Alpine, and a host copy would shadow it with wrong-platform
binaries.

Stop without destroying data:

```bash
pnpm infra:down
```

`docker compose ... down -v` **deletes the database volume**. Use it only when
you want a clean slate.

## After pulling changes

A changed `composer.lock` or `Dockerfile` needs a rebuild; new migrations need
applying:

```bash
pnpm infra:up --build && pnpm api:migrate
```

For the web apps, `pnpm install` as usual.

## Checking the stack is healthy

```bash
curl -s localhost:8080/health/ready
```

`{"status":"ok","checks":{"database":{"status":"up"},"redis":{"status":"up"}}}`
means this instance can serve traffic. A 503 names the dependency that is down.

`/health/live` answers without touching any dependency, so a brief database
outage does not get a healthy container killed and restarted in a loop.

## Quality gates

```bash
pnpm api:check
```

Runs Pint (style), PHPStan/Larastan level 8 (static analysis) and Pest (tests)
in sequence — the same three CI runs. Individually:

```bash
pnpm api:pint
```

```bash
pnpm api:stan
```

```bash
pnpm api:test
```

Tests run against real PostgreSQL. `smart_commerce_test` is created on first
boot by `infra/scripts/postgres-init/`.

## Inspecting data

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml exec postgres psql -U scp_app -d smart_commerce
```

Tinker:

```bash
pnpm api:artisan tinker
```

Queues — Horizon runs in the `worker` container and its dashboard is at
http://admin.localhost:8080/horizon:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml exec worker php artisan horizon:status
```

Logs:

```bash
pnpm api:artisan pail
```

## Troubleshooting

### The api container exits with "Invalid configuration"

Working as intended — `EnvironmentValidator` runs at boot and the entrypoint
runs `app:validate-config`. The message names every offending setting. Most
common cause: `APP_KEY` empty. Generate one:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml run --rm --no-deps api php artisan key:generate --show
```

### The gateway will not start

It waits for `api` to report healthy. Check the api container first:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml ps
```

The api healthcheck pings the PHP-FPM pool over FastCGI. If the container is up
but unhealthy, read its logs — a fatal error during boot (bad config, missing
extension) shows there.

### 502 from the gateway

Nginx reached no PHP-FPM worker. Either the api container is down, or
`SCRIPT_FILENAME` no longer matches the path inside it. That path is fixed in
`infra/nginx/conf.d/fastcgi-api.inc` at `/var/www/html/public/index.php`; it
must match the image `WORKDIR`.

### 500 with "could not be opened in append mode"

`storage/logs` is not writable by `www-data`. The image sets this up; it breaks
if you bind-mount a host `storage/` over it. Don't — the dev override
deliberately mounts only `app/`, `config/`, `database/`, `routes/`, `tests/` and
the two `bootstrap/*.php` files, leaving `storage/` and `bootstrap/cache/`
inside the image.

### Changes to config/ have no effect

In `local` the entrypoint clears the config cache, but if you ran
`config:cache` manually, Laravel keeps serving the cached copy:

```bash
pnpm api:artisan config:clear
```

Also remember: `env()` returns null once config is cached. Read configuration
through `config()` everywhere outside `config/*.php`.

### `SQLSTATE[08006] could not connect to server`

From inside a container the database host is `postgres`; from the host machine
it is `127.0.0.1`. Check the container is healthy and that `DB_HOST` matches
where the code is running.

### Migration drift or a failed migration

Locally, reset and replay:

```bash
pnpm api:artisan migrate:fresh --seed
```

This **drops every table**. Never run it against anything shared. For a
deployed environment, inspect `php artisan migrate:status` and resolve the
specific migration instead.

### `pnpm install` fails with `EISDIR: ... symlink`

The checkout is on exFAT or FAT32, which has no symlinks, and pnpm needs them
to link workspace packages. Move the repository to NTFS, APFS, ext4 or btrfs.
Confirm with `df -T .`. Composer and the Docker builds are unaffected.

### Next.js build fails with `EPERM: ... symlink`

Windows without symlink privileges, while Next assembles the `standalone`
output. Only the Docker images need it, so it is gated behind
`NEXT_OUTPUT_STANDALONE=true`, which only the Dockerfiles set. If you need it
on Windows, enable Developer Mode or use an elevated shell.

### MinIO will not pull

MinIO images are no longer served anonymously from Docker Hub or quay.io. It is
optional and sits behind the `storage` profile, so it never blocks the stack.
Development uses the local filesystem disk; set `MINIO_IMAGE` to a registry you
can read if you need S3 semantics locally.

## Resetting everything

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml down -v
```

Destroys all local data, including the seeded store and any test orders. Then
re-run first-time setup.
