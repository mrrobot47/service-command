# service-command Behat coverage

Suite for `ee service` (package `easyengine/service-command`, base `upstream/develop` `a292e6b`). Run it like the other package suites: copy `features/` into a core checkout's `features/` and run `vendor/bin/behat` from the core root as root.

## Inventory

### Commands, arguments and flags

| Item | Source | Covered by |
|---|---|---|
| `ee service` (composite, prints usage of all subcommands) | `Service_Command` | service.feature "ee service shows its subcommands" |
| `ee help service`, `ee help service <sub>` | docblocks | service.feature "ee help service describes the command", outline "shows its synopsis" |
| `ee service enable <service-name>` | `enable()` | service.feature, service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service disable <service-name>` | `disable()` | service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service restart <service-name>` | `restart()` | service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service reload <service-name>` | `reload()` | service.feature (unsupported services), service-nginx-proxy.feature |
| `ee service refresh` (no arguments) | `refresh()` | service-refresh.feature |
| `<service-name>` values: `nginx-proxy`, `db`, `redis`, `cron`, `newrelic-daemon` (`$whitelisted_services`) | `filter_service()` | every service feature (one per service) |
| Missing `<service-name>` → `usage: …`, exit 1 | framework | service.feature outline "without a service name prints usage" |
| Unknown service (`foo`, `global-db`, `nginx_proxy`, `DB`, `mailhog`, `php`) → `Error: Unable to find global EasyEngine service X` | `filter_service()` | service.feature outline "rejects an unknown service" |
| Extra positional argument → `Too many positional arguments` | framework | service.feature outline "accepts only one service" |
| Unknown `--param` → `unknown --foo parameter` | framework | service.feature outline "rejects unknown parameters"; refresh: service-refresh.feature `@known-bug` (SVC-6) |
| Global `--quiet` | framework | service.feature "--quiet suppresses the already-enabled notice" |
| `EE_CONFIG_PATH` / `proxy_80_port` / `proxy_443_port` config keys | `nginx_proxy_check()` | service.feature (mismatch, quoted match, unquoted `@known-bug` SVC-5), service-nginx-proxy.feature (ports busy) |
| Global `--custom-compose` / `custom-compose` dir | `docker_compose_with_custom()` | not covered: core behaviour, no custom compose files exist in the test environment |

### Behaviours and side effects

| Behaviour | Covered by |
|---|---|
| enable nginx-proxy on a running proxy: port check, notice `Service nginx_proxy already enabled.`, no restart | service.feature outline "is a no-op when the service is already running" |
| enable nginx-proxy: configured ports differ from the running proxy → error, no restart | service.feature "refuses to continue when the configured ports differ" |
| enable nginx-proxy on a stopped proxy: port probe, `docker-compose up -d global-nginx-proxy`, sites served again, default cert present | service-nginx-proxy.feature "starts a stopped proxy and sites come back" |
| enable nginx-proxy success message after a start | service-nginx-proxy.feature `@known-bug` (SVC-1) |
| enable nginx-proxy with ports busy → `Cannot create/start proxy container…`, proxy stays stopped | service-nginx-proxy.feature "refuses to start when the configured ports are busy" |
| enable nginx-proxy with the container removed → recreated | service-nginx-proxy.feature "recreates a removed proxy container" |
| enable nginx-proxy with `services/docker-compose.yml` missing → file regenerated (containers, DB password option) | service-nginx-proxy.feature "regenerates a missing global docker-compose.yml" |
| enable nginx-proxy generating `certs/default.{key,crt}` when missing (`Site_Self_signed`) and writing `/version.conf` | not covered: needs deleting the live default cert; the version.conf write is skipped today anyway (SVC-1) |
| enable nginx-proxy when `docker-compose up` fails → error | not covered: no safe way to make compose fail on the live stack |
| enable db/redis/cron/newrelic-daemon on a running container → `Notice: Service global-X already enabled.` | service.feature outline (db, redis), service-newrelic-daemon.feature "twice is a no-op", service-cron.feature (either message) |
| enable on an exited container → `docker start` | service-db/redis/cron.feature "starts a stopped …" |
| enable on a missing container → `docker-compose up -d global-X` | service-db/redis/cron.feature "recreates a removed … container", service-newrelic-daemon.feature "creates and starts the daemon" (image pull) |
| enable db keeps data (named volume) after the container is removed | service-db.feature "recreates a removed DB container and keeps the data" (WordPress site answers 200) |
| enable ignores `boot_container()` failures (MIG-2) | not covered: needs a failing compose up |
| enable on macOS copies `my.cnf` for db | not covered: macOS only |
| disable → `docker-compose stop`, container kept (exited), `Success: Service global-X disabled.` | every service feature |
| disable effect on sites: proxy down → connection refused; DB down → WordPress 500 | service-nginx-proxy.feature, service-db.feature |
| disable idempotent (twice) | every service feature |
| disable of a never-created service → exit 0, nothing created | service-newrelic-daemon.feature |
| restart → new `State.StartedAt`, services answer again (HTTP 200, `SELECT 1`, `PONG`) | every service feature |
| restart of a stopped service starts it (docker-compose semantics) | every service feature "restart … starts a stopped …" |
| restart of a never-created service claims success | service-newrelic-daemon.feature `@known-bug` (SVC-3) |
| reload nginx-proxy: docker-gen regenerates `conf.d/default.conf`, `nginx -t`, `nginx -s reload`, no container restart | service-nginx-proxy.feature "regenerates the proxy config without restarting" (marker removed, start time unchanged) |
| reload idempotent | service-nginx-proxy.feature "reload nginx-proxy twice" |
| reload on a stopped proxy still prints success | service-nginx-proxy.feature `@known-bug` (SVC-2) |
| reload of db/redis/cron/newrelic-daemon → `Warning: global-X can not be reloaded.`, exit 0, no docker call | service.feature outlines |
| refresh regenerates the compose file (all 5 services, both networks, subnets and DB password from `options`) | service-refresh.feature "regenerates a modified global docker-compose.yml" |
| refresh with the compose file missing | service-refresh.feature "recreates a missing global docker-compose.yml" |
| refresh is idempotent (same file, no container recreated) | service-refresh.feature "is idempotent" |
| refresh keeps stopped services stopped and doesn't create never-enabled ones | service-refresh.feature "leaves a disabled service stopped", "does not create services that were never enabled" |
| refresh applies image tag or port changes to running containers | not covered: needs a changed `img-versions.json` or proxy ports, which recreates the live proxy on other ports |
| Container migrations under `migrations/container/` | not covered: run by core's migration pipeline, not by `ee service` |

## Coverage estimate

About 85% of the inventory rows above (40 of 47 command/behaviour rows); the gaps are listed as "not covered" with the reason. All 5 subcommands and all 5 services are exercised for their success paths, and every validation path of the command is covered.

## Tags and counts

87 scenarios (Outline examples counted) in 7 features:

| Feature | Scenarios | Tags |
|---|---|---|
| service.feature | 44 | none (read-only), 5 `@known-bug` |
| service-nginx-proxy.feature | 13 | `@destructive`, 2 `@known-bug` |
| service-db.feature | 6 | `@destructive` |
| service-redis.feature | 6 | `@destructive` |
| service-cron.feature | 7 | `@destructive` |
| service-newrelic-daemon.feature | 5 | `@destructive @newrelic`, 1 `@known-bug` |
| service-refresh.feature | 6 | `@destructive`, 1 `@known-bug` |

Totals: `@destructive` 43, `@known-bug` 9, `@newrelic` 5 (pulls `easyengine/newrelic-daemon` from Docker Hub), `@le` 0, `@remote` 0. Exclude known bugs with `--tags='~@known-bug'`; run only the read-only part with `--tags='~@destructive'`.

## Environment and safety

- Sites: `bh-service-command-1.test` (html, nginx-proxy feature), `bh-service-command-2.test` (wp, db feature), `bh-service-command-3.test` (html, refresh feature). Created once per feature by `a test site :site of type :type exists` (refuses names without the prefix) and deleted by the `@AfterFeature` hook, which only deletes sites starting with `bh-service-command-`.
- HTTP checks go to `http://127.0.0.1` with a `Host` header, so they don't depend on `/etc/hosts` and aren't affected by HTTP auth (127.0.0.1 is whitelisted).
- `@destructive` scenarios: a `@BeforeScenario` hook records the state of the 5 global service containers and the `@AfterScenario` hook puts each one back (`docker start`, `docker-compose up -d`, `docker stop` or `docker rm -f`), then waits until the proxy runs, the DB answers `SELECT 1` and redis answers `PONG`.
- Files under `/opt/easyengine` are only modified after `I back up the file` (enforced by the steps) and restored after the scenario. Temporary configs for `EE_CONFIG_PATH` are written to `/var/tmp/bh-service-command-*.yml` and removed after the scenario.
- The cron container restart-loops when no site has cron jobs (CRON-1); cron checks accept "running or restarting".
- Paths assume Linux (`/opt/easyengine`).

## Estimated runtime

About 15 minutes on this machine: the read-only feature ~2 min; nginx-proxy ~3 min (html site create plus 13 proxy stops/starts); db ~4 min (WordPress site create, DB restarts with readiness waits); redis ~1 min; cron ~1.5 min; newrelic-daemon ~1.5 min plus the first image pull; refresh ~2.5 min.

## Known bugs exposed (`@known-bug`)

| ID | Scenario | Issue |
|---|---|---|
| SVC-1 | service-nginx-proxy.feature "reports success after starting a stopped proxy" | `nginx_proxy_check()` does a bare `return;` when the default cert exists, so after starting a stopped proxy `enable` prints `Notice: Service nginx_proxy already enabled.` and `set_nginx_proxy_version_conf()` never runs (the `X-Powered-By` version header isn't refreshed after a start). |
| SVC-2 | service-nginx-proxy.feature "reload nginx-proxy fails when the proxy is stopped" | `disable`, `restart` and `reload` call `EE::exec( …, true, true )` without `$exit_on_error` and ignore the result, so a failed docker-compose call still prints `Success:` and exits 0. |
| SVC-3 | service-newrelic-daemon.feature "restart newrelic-daemon does not claim success" | `docker-compose restart` exits 0 for a service with no container, so `ee service restart newrelic-daemon` claims a restart that never happened (same shape as SVC-2). |
| SVC-5 | service.feature "accepts unquoted port values" | `get_config_value()` returns Spyc's int for `proxy_80_port: 80`; `nginx_proxy_check()` compares it with `!==` against the docker inspect string, so matching ports are reported as `Ports … don't match` and `enable nginx-proxy` exits 1. |
| SVC-6 | service-refresh.feature "rejects unknown parameters" | `refresh()` has no synopsis, so the framework skips argument validation and `ee service refresh --foo=bar extra` runs a refresh. |
| SVC-7 | service.feature outline "lists every accepted service" | The `<service-name>` help lists `nginx-proxy, db, redis, newrelic-daemon` but not `cron`. Also (not tested): the class examples all say "Restarts global nginx proxy service", and `composer.json` `extra.commands` omits `service refresh`. |

## Suspected issues not covered by a scenario

- SVC-4: `enable` ignores `init_global_container()`'s `boot_container()` result and prints `Success` even when `docker-compose up` fails (overlaps MIG-2).
- SVC-8: `refresh` probes running services with plain `docker-compose ps -q` (not `docker_compose_with_custom()`) before regenerating the file. With the compose file missing or unparsable it finds nothing, skips every `up -d` and still prints `Global services refreshed.`, so running containers are never updated.
- SVC-9 (cosmetic): inconsistent service names in messages: `Service nginx_proxy enabled.` vs `Service global-nginx-proxy disabled.` vs `Service global-db enabled.`, and `Reloaded global-nginx-proxy.`.
