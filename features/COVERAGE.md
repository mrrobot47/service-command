# service-command Behat coverage

Suite for `ee service` (package `easyengine/service-command`, base `upstream/develop` `a292e6b`). Run it like the other package suites: copy `features/` into a core checkout's `features/` and run `vendor/bin/behat` from the core root as root.

## Inventory

### Commands, arguments and flags

| Item | Source | Covered by |
|---|---|---|
| `ee service` (composite, prints usage of all subcommands, exit 0) | `Service_Command` | service.feature "ee service shows its subcommands" |
| Unknown subcommand (`ee service start …`) → `'start' is not a registered subcommand of 'service'`, exit 1 | framework | service.feature "rejects an unknown subcommand" |
| `ee help service`, `ee help service <sub>` | docblocks | service.feature "ee help service describes the command", outline "shows its synopsis" (run with `PAGER=cat`) |
| `ee service enable <service-name>` | `enable()` | service.feature, service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service disable <service-name>` | `disable()` | service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service restart <service-name>` | `restart()` | service-nginx-proxy/db/redis/cron/newrelic-daemon.feature |
| `ee service reload <service-name>` | `reload()` | service.feature (unsupported services), service-nginx-proxy.feature |
| `ee service refresh` (no arguments) | `refresh()` | service-refresh.feature |
| `<service-name>` values: `nginx-proxy`, `db`, `redis`, `cron`, `newrelic-daemon` (`$whitelisted_services`) | `filter_service()` | every service feature (one per service) |
| Missing `<service-name>` → `usage: …`, exit 1 | framework | service.feature outline "without a service name prints usage" |
| Unknown service (`foo`, `global-db`, `nginx_proxy`, `DB`, `mailhog`, `php`) → `Error: Unable to find global EasyEngine service X` | `filter_service()` | service.feature outline "rejects an unknown service" |
| Extra positional argument → `Too many positional arguments` | framework | service.feature outline "accepts only one service"; refresh: service-refresh.feature `@known-bug` (SVC-6) |
| Unknown `--param` → `unknown --foo parameter` | framework | service.feature outline "rejects unknown parameters"; refresh: service-refresh.feature `@known-bug` (SVC-6) |
| Global `--quiet` | framework | service.feature "--quiet suppresses the already-enabled notice" `@known-bug` (CORE-3/CFG-5: `--quiet` never takes effect) |
| Global `--debug` | framework | used by service-refresh.feature to see which `up -d` calls refresh makes |
| `EE_CONFIG_PATH` / `proxy_80_port` / `proxy_443_port` config keys | `nginx_proxy_check()` | service.feature (mismatch on both or one port, quoted match, unquoted `@known-bug` SVC-5), service-nginx-proxy.feature (ports busy) |
| Global `--custom-compose` pointing to a missing file → warning, falls back to the default compose file | `docker_compose_with_custom()` | service-redis.feature "falls back to the default compose file when --custom-compose is missing" |
| Existing custom compose files (`user-docker-compose/*.yml`, `docker-compose-custom.yml`) are added with `-f` | `docker_compose_with_custom()` | not covered: core behaviour; adding an override to the live global services is too invasive (the restore hook does honour them) |

### Behaviours and side effects

| Behaviour | Covered by |
|---|---|
| enable nginx-proxy on a running proxy: port check, notice `Service nginx_proxy already enabled.`, no restart | service.feature outline "is a no-op when the service is already running" |
| enable nginx-proxy: configured ports differ from the running proxy (either port) → error, no restart | service.feature outline "refuses to continue when the configured ports … differ" |
| enable nginx-proxy on a stopped proxy: port probe, `docker-compose up -d global-nginx-proxy`, sites served again | service-nginx-proxy.feature "starts a stopped proxy and sites come back" |
| enable nginx-proxy success message after a start, and the version written into a recreated proxy | service-nginx-proxy.feature 2 × `@known-bug` (SVC-1) |
| enable nginx-proxy with ports busy → `Cannot create/start proxy container…`, proxy stays stopped | service-nginx-proxy.feature "refuses to start when the configured ports are busy" |
| enable nginx-proxy with the container removed → recreated | service-nginx-proxy.feature "recreates a removed proxy container" |
| enable nginx-proxy with `services/docker-compose.yml` missing → file regenerated (containers, DB password option) | service-nginx-proxy.feature "regenerates a missing global docker-compose.yml" |
| enable nginx-proxy generating `certs/default.{key,crt}` when missing (`Site_Self_signed`, CN=default), writing `/version.conf` and printing `Success` | service-nginx-proxy.feature "generates a missing default certificate, writes the version and reports success" (restores the original cert and reloads) |
| enable nginx-proxy when `docker-compose up` fails → `There was some error in starting …`, exit 1 | service-nginx-proxy.feature "fails when docker-compose cannot start the proxy" (invalid YAML appended to the backed-up compose file) |
| enable nginx-proxy after changing the proxy ports while it is stopped (new ports checked, old ports bound) | not covered: SVC-10; a passing (fixed) run would leave the live proxy on test ports |
| enable db/redis/cron/newrelic-daemon on a running container → `Notice: Service global-X already enabled.` | service.feature outline (db, redis), service-newrelic-daemon.feature "twice is a no-op"; cron accepts either message (restart loop, CRON-1) |
| enable db/redis/… with `services/docker-compose.yml` missing → file regenerated, running container untouched | service-redis.feature "regenerates a missing global docker-compose.yml without restarting redis" |
| enable on an exited container → `docker start` | service-db/redis/cron.feature "starts a stopped …" |
| enable on a missing container → `docker-compose up -d global-X` | service-db/redis/cron.feature "recreates a removed … container", service-newrelic-daemon.feature "creates and starts the daemon" (image pull) |
| enable db keeps data (named volume) after the container is removed | service-db.feature "recreates a removed DB container and keeps the data" (WordPress site answers 200; the DB is stopped cleanly before `docker rm`) |
| enable ignores `boot_container()` failures (SVC-4, MIG-2) | service-redis.feature `@known-bug` "fails when docker-compose cannot create the container" |
| enable on macOS copies `my.cnf` for db | not covered: macOS only |
| disable → `docker-compose stop`, container kept (exited), `Success: Service global-X disabled.` | every service feature |
| disable effect on sites: proxy down → connection refused; DB down → WordPress 500 | service-nginx-proxy.feature, service-db.feature |
| disable idempotent (twice) | service-nginx-proxy/db/redis/cron.feature |
| disable of a never-created service → exit 0, nothing created | service-newrelic-daemon.feature |
| disable/restart when docker-compose fails → still `Success`, exit 0 | service-redis.feature `@known-bug` outline (SVC-2, compose file missing) |
| restart → new `State.StartedAt`, services answer again (HTTP 200, `SELECT 1`, `PONG`) | service-nginx-proxy/db/redis.feature; cron and newrelic-daemon only check the running state (cron restart-loops, CRON-1) |
| restart of a stopped service starts it (docker-compose semantics) | every service feature "restart … starts a stopped …" (the precondition asserts it is stopped) |
| restart of a never-created service claims success | service-newrelic-daemon.feature `@known-bug` (SVC-3) |
| reload nginx-proxy: docker-gen regenerates `conf.d/default.conf`, `nginx -s reload` (new worker PIDs), no container restart | service-nginx-proxy.feature "regenerates the proxy config without restarting" (marker removed, worker PIDs changed, start time unchanged) |
| reload idempotent | service-nginx-proxy.feature "reload nginx-proxy twice" |
| reload on a stopped proxy still prints success | service-nginx-proxy.feature `@known-bug` (SVC-2) |
| reload of db/redis/cron/newrelic-daemon → `Warning: global-X can not be reloaded.`, exit 0, no docker call | service.feature outlines |
| refresh regenerates the compose file (all 5 services, both networks, subnets and DB password from `options`) and runs `up -d` for the running services | service-refresh.feature "regenerates a modified global docker-compose.yml and updates the running services" (`--debug` shows the `up -d` calls) |
| refresh with the compose file missing: file regenerated, nothing restarted | service-refresh.feature "recreates a missing global docker-compose.yml" |
| refresh with the compose file missing skips every `up -d` | service-refresh.feature `@known-bug` (SVC-8) |
| refresh is idempotent (same file, no container recreated) | service-refresh.feature "is idempotent" |
| refresh keeps stopped services stopped (no `up -d` for them) and doesn't create never-enabled ones | service-refresh.feature "leaves a disabled service stopped", "does not create services that were never enabled" (relies on compose v2, where `ps -q` lists only running containers) |
| refresh applies image tag or port changes to running containers | not covered: needs a changed `img-versions.json` or proxy ports, which recreates the live proxy on other ports |
| compose generation side effects: labelled volumes created when missing, subnet options initialised, random DB password when the `global-db` option is empty | not covered: all already exist on a live stack; recreating them would destroy data or change the DB password (SVC-11) |
| constructor creates and enters `EE_SERVICE_DIR` | not covered: the directory always exists on a live stack |
| Container migrations under `migrations/container/` | not covered: run by core's migration pipeline, not by `ee service` |

## Coverage estimate

About 88% of the inventory rows above (49 of 56 command/behaviour rows); the gaps are listed as "not covered" with the reason. All 5 subcommands and all 5 services are exercised for their success paths, every validation path of the command is covered, and every `EE::error` branch of the package has a scenario.

## Tags and counts

100 scenarios (Outline examples counted) in 7 features:

| Feature | Scenarios | Tags |
|---|---|---|
| service.feature | 47 | none (read-only), 6 `@known-bug` |
| service-nginx-proxy.feature | 16 | `@destructive`, 3 `@known-bug` |
| service-db.feature | 6 | `@destructive` |
| service-redis.feature | 11 | `@destructive`, 3 `@known-bug` |
| service-cron.feature | 7 | `@destructive` |
| service-newrelic-daemon.feature | 5 | `@destructive @newrelic`, 1 `@known-bug` |
| service-refresh.feature | 8 | `@destructive`, 3 `@known-bug` |

Totals: `@destructive` 53, `@known-bug` 16, `@newrelic` 5 (pulls `easyengine/newrelic-daemon` from Docker Hub), `@le` 0, `@remote` 0. Exclude known bugs with `--tags='~@known-bug'`; run only the read-only part with `--tags='~@destructive'`.

## Environment and safety

- Sites: `bh-service-command-1.test` (html, nginx-proxy feature), `bh-service-command-2.test` (wp, db feature), `bh-service-command-3.test` (html, refresh feature). Created once per feature by `a test site :site of type :type exists` (refuses names without the prefix) and deleted by the `@AfterFeature` hook, which only deletes sites starting with `bh-service-command-`.
- HTTP checks go to `http://127.0.0.1` with a `Host` header, so they don't depend on `/etc/hosts` and aren't affected by HTTP auth (127.0.0.1 is whitelisted). They assume the proxy publishes port 80 (the default `proxy_80_port`).
- The read-only feature (`service.feature`) only checks that services are running; it never starts one.
- `@destructive` scenarios: a `@BeforeScenario` hook records the state of the 5 global service containers and the `@AfterScenario` hook puts each one back (`docker start`, `docker-compose up -d`/`up --no-start` with the same custom compose files as ee, `docker stop` or `docker rm -f`), then waits until the proxy runs, the DB answers `SELECT 1` and redis answers `PONG`.
- Files under `/opt/easyengine` are only modified after `I back up the file` (enforced by the steps) and restored after the scenario. Temporary configs for `EE_CONFIG_PATH` are written to `/var/tmp/bh-service-command-*.yml` and removed after the scenario.
- Crash safety: backups and the pending restore state (files, temp configs, service snapshot) live in `/var/tmp/bh-service-command-state/` until restored. A `@BeforeSuite` hook replays a leftover state (killed run), and a failed restore keeps its baseline for the next scenario instead of taking the broken state as the new baseline.
- Scenarios that regenerate the compose file and run `up -d` (refresh, compose regeneration) first check that the running global containers use this checkout's `img-versions.json` tags, so they can't upgrade the live stack.
- The global DB and redis are stopped cleanly (`docker stop`) before `docker rm`, never SIGKILLed.
- Failure messages that print the global `docker-compose.yml` mask `MYSQL_ROOT_PASSWORD`.
- The cron container restart-loops when no site has cron jobs (CRON-1); cron checks accept "running or restarting", and cron scenarios enable it first because it only exists once something enabled it.
- The WordPress site of the db feature may start global cron; if cron was absent before a scenario, the restore removes it again.
- Paths assume Linux (`/opt/easyengine`). Run the suite with a checkout whose `VERSION` matches the installed ee, otherwise the first `bin/ee` call runs the migration pipeline on the host.

## Estimated runtime

About 18 minutes on this machine: the read-only feature ~2 min; nginx-proxy ~4 min (html site create plus 16 proxy stops/starts); db ~4 min (WordPress site create, DB restarts with readiness waits); redis ~2 min; cron ~1.5 min; newrelic-daemon ~1.5 min plus the first image pull; refresh ~3 min.

## Known bugs exposed (`@known-bug`)

| ID | Scenario | Issue |
|---|---|---|
| SVC-1 | service-nginx-proxy.feature "reports success after starting a stopped proxy", "writes the EasyEngine version into a recreated proxy" | `nginx_proxy_check()` does a bare `return;` when the default cert exists, so after starting a stopped proxy `enable` prints `Notice: Service nginx_proxy already enabled.` and `set_nginx_proxy_version_conf()` never runs: a recreated proxy keeps the image's `X-Powered-By "EasyEngine v4"`. |
| SVC-2 | service-nginx-proxy.feature "reload nginx-proxy fails when the proxy is stopped", service-redis.feature outline "fails when the global docker-compose.yml is missing" (disable, restart) | `disable`, `restart` and `reload` call `EE::exec( …, true, true )` without `$exit_on_error` and ignore the result, so a failed docker-compose call still prints `Success:` and exits 0. |
| SVC-3 | service-newrelic-daemon.feature "restart newrelic-daemon does not claim success" | `docker-compose restart` exits 0 for a service with no container, so `ee service restart newrelic-daemon` claims a restart that never happened. |
| SVC-4 | service-redis.feature "enable redis fails when docker-compose cannot create the container" | `init_global_container()` ignores `boot_container()`'s result and always returns true, so a failed `up -d` prints `Success: Service global-redis enabled.` |
| SVC-5 | service.feature "accepts unquoted port values" | `get_config_value()` returns Spyc's int for `proxy_80_port: 80`; `nginx_proxy_check()` compares it with `!==` against the docker inspect string, so matching ports are reported as `Ports … don't match` and `enable nginx-proxy` exits 1. |
| SVC-6 | service-refresh.feature "rejects unknown parameters", "rejects positional arguments" | `refresh()` has no synopsis, so the framework skips argument validation and `ee service refresh --foo=bar extra` runs a refresh. |
| SVC-7 | service.feature outline "lists every accepted service" | The `<service-name>` help lists `nginx-proxy, db, redis, newrelic-daemon` but not `cron`. Also (not tested): the class examples all say "Restarts global nginx proxy service", and `composer.json` `extra.commands` omits `service refresh`. |
| SVC-8 | service-refresh.feature "updates the running services when the compose file was missing" | `refresh` probes running services with plain `docker-compose ps -q` before regenerating the file; without the file it finds nothing, skips every `up -d` and still prints `Global services refreshed.` |
| CORE-3 (core, also CFG-5) | service.feature "--quiet suppresses the already-enabled notice" | `InitializeLogger` runs before `ConfigureRunner`, so the logger never sees `quiet` and `--quiet` still prints `Notice: Service global-redis already enabled.` (first seen on CI: https://github.com/mrrobot47/service-command/actions/runs/36085614782). |

## Suspected issues not covered by a scenario

- SVC-9 (cosmetic): inconsistent service names in messages: `Service nginx_proxy enabled.` vs `Service global-nginx-proxy disabled.` vs `Service global-db enabled.`, and `Reloaded global-nginx-proxy.`.
- SVC-10: changed proxy ports aren't applied when starting a stopped proxy (the compose file is only regenerated when missing).
- SVC-11: an empty `global-db` option makes compose generation write a new random root password that no longer matches the existing datadir.
