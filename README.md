# tenant-shared-demo

One Symfony 8.1 codebase that runs two ways:

- **dedicated** (`TENANT_MODE=single`): one deployment per tenant, as most database-per-tenant apps run today;
- **shared** (`TENANT_MODE=multi`): one deployment serves every tenant placed on it, each request and each queued
  message naming its own tenant.

Every tenant keeps its own PostgreSQL database and its own Redis stream in both modes. Only the processes are shared.
Nothing in the controllers, handlers or repositories knows about tenants: the tenant is resolved once per request or
message, and a DBAL connection wrapper, a cache decorator and a Messenger transport follow it.

This is the companion repository of the article *"Database per Tenant, Process per Nobody: Running Many Symfony
Tenants in One Deployment"*. It is a demo, not a library: copy what you need.

## Run it

Requires PHP 8.4, Composer and Docker.

```bash
composer install
scripts/demo.sh        # the whole scenario, about a minute
```

The script starts Postgres (port 55434) and Redis (port 56381), places tenants `1111…`, `2222…` and `3333…` on the
shared deployment `shared-1` and tenant `4444…` on a dedicated one, migrates every tenant database in parallel, starts
one shared web process, two shared workers and the dedicated tenant's web process, then walks through the checks
below. Stop with Ctrl-C; `docker compose down` removes the containers (`-v` also removes the data).

## What it shows

**The same process, a different database per path prefix.**

```
GET /tenant_11111111-…/whoami
{"tenant":"11111111-…","database":"tenant_11111111_1111_4111_8111_111111111111","basePath":"/tenant_11111111-…","notesUrl":"/tenant_11111111-…/notes"}
GET /tenant_22222222-…/whoami
{"tenant":"22222222-…","database":"tenant_22222222_2222_4222_8222_222222222222", …}
```

The prefix becomes the request's base path (`Kernel::handle` + `TenantPathPrefix`), so routes have no prefix and
generated URLs keep it. A path without a prefix, or with a tenant placed elsewhere, is a 404 on the shared deployment;
only `/health` is open.

**The same worker, each message in its tenant's database.** Two notes posted for A and B one after the other are
both handled by worker pid 95790, and each `processedBy` stamp lands in the right database:

```
A sees: [{"id":2,"body":"hello from A","processedBy":"MacBookPro16.local:95790", …}]
B sees: [{"id":1,"body":"hello from B","processedBy":"MacBookPro16.local:95790", …}]
```

**One cache pool, a namespace per tenant.** `/notes/stats` is cached for 60 s; A and B get their own entry.

**Fair use.** With `TENANT_INFLIGHT_CAP=1`, tenant A queues four 4-second jobs and tenant B posts a note half a
second later. B's note is processed in the same second by the second worker, while A's jobs run one at a time:

```
B note created 20:37:28, processed 20:37:28 by MacBookPro16.local:95981
in flight for A right now: 1
[20:37:32] slow job A-1 for tenant 11111111 done by pid 95790
[20:37:36] slow job A-2 …   [20:37:40] slow job A-3 …   [20:37:44] slow job A-4 …
```

**Failures stay in the tenant.** The failure transport is `doctrine://default`, and the tenant is still set when the
failure is sent, so a failing job of B ends in B's own `messenger_messages` table (`queue_name = failed`), never in a
shared one. `bin/console app:tenant:failed <uuid> show|retry|remove` runs Symfony's own command inside that tenant.

**What a tenant switch costs** (`app:bench:switch`, Postgres in Docker on a laptop):

```
300 queries on one tenant:            0.199 ms per query
300 queries switching tenant each time: 6.474 ms per query (299 reconnects)
cost of a tenant switch: 6.27 ms
```

A switch is a reconnect. Workers read tenants in rotation, so a busy shared worker pays this per message; see the
article for when that matters and what to do about it.

**Process memory** (RSS, PHP 8.4, dev mode): a worker 49 to 65 MB, a web process 42 to 55 MB. A dedicated deployment
needs its own copies of these per tenant; the shared one needs one set per placement.

## Where to look

| Concern | Class |
|---|---|
| Who the request or message is for | `src/Tenant/TenantContext.php`, `Tenant.php` |
| Prefix → base path | `src/Tenant/TenantPathPrefix.php`, `src/Kernel.php` |
| Request → tenant, 404 rules | `src/Tenant/TenantRequestListener.php`, `TenantResolver/*` |
| Which tenants this deployment serves | `src/Tenant/PlacementReader.php` (Redis hash `tenant:placement`) |
| Database follows the tenant | `src/Tenant/TenantConnection.php` (DBAL `wrapper_class`) |
| Cache follows the tenant | `src/Tenant/TenantAwareCache.php` (decorates `cache.app`) |
| Workers read every tenant's stream | `src/Tenant/Messenger/MultiTenantTransport.php`, `…Factory.php` |
| Worker sets and clears the tenant | `src/Tenant/Messenger/TenantWorkerListener.php`, `TenantStamp.php` |
| Per-tenant in-flight cap | `src/Tenant/Messenger/FairUse.php` |
| Migrate every tenant, one process each | `src/Command/TenantsMigrateCommand.php` |
| Tests against two real tenants | `tests/TwoTenantIsolationTest.php` (`bin/phpunit`, after `scripts/demo.sh` placed and migrated the tenants) |

## Notes

- The PHP built-in server does not copy process environment variables into `$_SERVER` unless `variables_order`
  contains `E`; the scripts pass `-d variables_order=EGPCS`. php-fpm and FrankenPHP need nothing special.
- A worker that dies mid-message leaves its tenant's in-flight slot taken until the key's TTL (3 minutes) expires;
  `--keepalive` refreshes it while a long message is handled. That is the trade-off for a cap that costs one Redis
  `GET` per poll.
- `migrations/` is generated against tenant `1111…` in single mode and applied to every tenant by
  `app:tenants:migrate`, each in its own process with `TENANT_MODE=single TENANT_UUID=<uuid>`.

## Part 2: FrankenPHP worker mode and kept connections

The companion of the second article, *"One Kernel for Every Tenant: FrankenPHP Worker Mode for Database-per-Tenant
Symfony 8.1"*. The same code runs under FrankenPHP (`docker/frankenphp/Dockerfile`, classic or worker mode by
`FRANKENPHP_CONFIG`), with php-fpm behind nginx as the baseline (`docker/fpm`, `docker/nginx`). `symfony/runtime` 8.1
detects worker mode on its own; no extra package.

`DATABASE_URL=...&tenantConnections=16` makes `TenantConnection` keep up to 16 open connections, one per tenant
database, instead of closing on every switch. It only pays in a process that lives across requests (FrankenPHP
worker, Messenger worker); the default of 1 is right for php-fpm.

```
scripts/bench.sh                      # four deployments, wrk rotating three tenants, results in bench/
PG_SSLMODE=disable scripts/bench.sh   # same without SSL negotiation on connect
```

`/whoami` reports `runtime` (`web=1` or `web=1&worker=1`), `connects` (driver connections this thread opened) and
`open` (databases with an open connection). `tests/TenantConnectionKeepTest.php` covers the kept connections and the
transaction guard. Results of the run used in the article: `bench/results-2026-11-12-sslmode-prefer.md`.
