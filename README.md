# Moodle LMS Setup

This service runs Moodle with PHP-FPM and MySQL.

## Services

- `moodle-db` -> MySQL database
- `moodle-app` -> Moodle PHP application

## Start Moodle Only

Run from the `Moodle_LMS/` folder:

```bash
docker compose --env-file ../.env up -d
```

This compose file joins the shared Docker network `rtc_net`, so that network must already exist. The easiest path is to start the root stack once from the project root.

## Database Settings

For the shared root deployment used by `./deploy.sh` and GitHub Actions:

- database host: run `./sync-compose.sh --service moodle-db`
- database name: `moodle_lms`
- database user: `moodle`
- database password: `Moodle@123`
- MySQL root password: `Root@123`

For the standalone `Moodle_LMS/docker-compose.yml` only:

- database host: `db`
- database name: `moodle`
- database user: `moodleuser`
- database password: `moodlepass`

## First-Time Setup

MySQL creates the `moodle_lms` database automatically only when the DB volume is empty on first startup.

Then finish the application installation from the browser:

- Production: `https://moodle.rtc-kp.camai.kh`
- Local/internal route: `http://moodle.rtc-kp.localhost`

`./setup_moodle.sh` now generates `Moodle_LMS/moodle/config.php` automatically when it is missing, so the browser installer does not need write access to create that file inside the bind-mounted repo checkout.
For the shared Caddy deployment, Moodle should keep `sslproxy=true` but `reverseproxy=false` by default, because Caddy forwards the public host header directly.

Use these values in the Moodle installer for the shared root deployment:

- Data directory: `/var/moodledata`
- Database type: `mysqli`
- Database host: the value from `./sync-compose.sh --service moodle-db`
- Database name: `moodle_lms`
- Database user: `moodle`
- Database password: `Moodle@123`

## Clean Reset

To remove the current Moodle DB volume and start again:

```bash
docker compose --env-file ../.env down -v
docker compose --env-file ../.env up -d
```

Moodle data is stored in the Docker volume mounted at `/var/moodledata`, not in the repo checkout.

For the shared root deployment and GitHub Actions flow, use:

```bash
./setup_moodle.sh --reset-moodle-db
./setup_moodle.sh --reset-moodle-data
./setup_moodle.sh --reset-moodle-db --reset-moodle-data
```

There is also a dedicated GitHub Actions workflow named `Moodle Setup` for CI/CD-driven re-setup and resets.

## Useful Commands

Show logs:

```bash
docker compose --env-file ../.env logs -f
```

Check running containers:

```bash
docker compose --env-file ../.env ps
```

## Isolated Moodle PHPUnit

The optional PHPUnit stack is separate from the normal Moodle Compose project. It uses its own MySQL 8.4 service and project-scoped named volumes on an internal network with no published ports or shared application volumes. The database, Moodle data, and runner state volumes use a stable per-checkout project name (rtc-moodle-phpunit-<12-character-path-hash>) derived from the absolute repository path by the guarded runner, so separate clones do not share named volumes. The Compose file has no fixed project name. The runner mounts the checkout read-only, copies the code into a temporary workspace, excludes the ordinary `config.php` and generated PHPUnit config, and uses a test-only config that pins the connection to `phpunit-db` / `rtc_moodle_phpunit`.

From the `Moodle_LMS/` folder, create the ignored local credentials file and set two different random 64-character hexadecimal passwords:

```powershell
Copy-Item .\phpunit\.env.example .\phpunit\.env
```

Before every mutating command, confirm the exact checkout-specific profile and
schema. The script requires that explicit consent on both `Initialize` and
`Test`; `Initialize` also requires the separate reset opt-in. It verifies the
selected Docker endpoint is local, confirms the engine is already available,
checks all existing volume Compose labels against the derived project and
refuses partial or foreign profiles. A first initialization is allowed only
when none of the profile volumes exist. The runner never starts Docker Desktop
or a Docker service when `docker info` fails.

Use this guarded sequence from the `Moodle_LMS/` folder:

```powershell
. .\phpunit\compose-project-name.ps1
$profile = Get-MoodlePhpUnitComposeProjectName -RepositoryRoot (Get-Location).Path
$target = @{
    ConfirmDisposableTarget = $true
    ExpectedProjectName = $profile
    ExpectedDatabaseName = 'rtc_moodle_phpunit'
}
.\phpunit\run.ps1 -Action Initialize @target -AllowDatabaseReset
.\phpunit\run.ps1 -Action Test @target `
    -TestFile local/rtcsync/tests/upgrade_test.php `
    -TestFilter '^test_upgrade_2026102600_creates_and_recreates_delivery_membership_schema$'
.\phpunit\run.ps1 -Action Test @target `
    -TestFile local/rtcsync/tests/delivery_lineage_reconciliation_test.php
.\phpunit\run.ps1 -Action Test @target `
    -TestFile local/rtcsync/tests/student_history_test.php `
    -TestFilter 'test_delivery_course_stays_locked_until_bounded_module_backfill_finishes|test_page_and_resource_availability_map_per_delivery_and_block_cross_delivery_reuse|test_page_and_resource_without_one_exact_delivery_restriction_fail_closed'
```

The upgrade test simulates the 2026102600-to-2026102800 path with a partial
2026102600 index state, runs the exact 2026102700 unique-index repair, then
asserts the v2026102800 lineage columns/audit tables and legacy quarantine.
The reconciliation test file covers reviewed preview/apply, target drift,
repeat import, conflict/ambiguous references, per-record provenance, and
transaction rollback. The selected history tests cover backfill quarantine,
activity guards, and exclusion of unmapped/ambiguous Released rows.

`Initialize` uses Moodle's `--disable-composer` mode. The runner refuses to
start if the checked-out `vendor` tree or `composer.phar` is missing, so it
will not install or download dependencies. `Test` requires all three
checkout-scoped profile volumes to exist with matching Docker Compose labels;
`Initialize` refuses a partial volume set and cannot rebuild an existing
profile without `-AllowDatabaseReset`. Do not point this stack at the normal
or staging Compose projects.

The local `.env` is excluded from Git. The temporary source workspace is
disposable, while the project-scoped database, Moodle data, and generated
PHPUnit config volumes persist between runs. The static Compose check validates
the fail-closed target guards, internal-only network, absence of published
ports, and project-derived volume names without starting services.

## Local Staging Moodle Stack

`docker-compose.staging.yml` is a separate local staging stack. It has its own project-scoped MySQL and Moodle data volumes, staging-named containers and network, and does not join `rtc_net`. The database and web ports bind only to `127.0.0.1`. Its PHP image compiles Moodle's required `mbstring` extension and installs the OS CA certificates. PHP mounts the Moodle checkout and generated staging `config.php` read-only; a staging-only entrypoint initializes ownership of the dedicated Moodle data volume as `www-data` before starting FPM. Nginx receives only `moodle/public` and its own configuration. Staging SSO uses only the exact configured RTC API origin, including any non-default HTTPS port; it does not try legacy gateway or backend hostname fallbacks. SSO requests verify the certificate chain and hostname and do not follow redirects, so the staging API must present a certificate trusted by the PHP image's system CA store. The checked-in Compose build supports system-trusted certificates only; a private staging CA requires a dedicated image trust configuration before use.

Create a local stage-only environment file from the example. Set `RTC_STAGING_API_ORIGIN` and `RTC_STAGING_MOODLE_ORIGIN` to the same canonical HTTPS origins configured as GitHub organization variables across the LMS repositories, and copy those values into the local file. The generator requires both origins and verifies that `RTC_STAGING_API_BASE_URL` matches `RTC_STAGING_API_ORIGIN`, including the effective HTTPS port, and that the hostname in `RTC_STAGING_MOODLE_ORIGIN` matches `MOODLE_STAGING_HOSTNAME`. It preserves the full canonical Moodle origin, including a non-default HTTPS port, in `$CFG->wwwroot`. A single trailing DNS dot is normalized before host comparisons; multiple trailing dots remain invalid. Both origins must be origin-only HTTPS URLs with no credentials, path, query, or fragment. Replace every placeholder with real staging hostnames and unique generated secrets. Both hostnames must use HTTPS, include a `stage` or `staging` DNS label, and use a staging-owned DNS domain outside the RTC production suffixes `bb-rtc-edu.com`, `kc-rtc-edu.com`, `kampot-rtc-edu.com`, `rtc-edu.com`, and `rtc-kp.camai.kh`. A staging label beneath one of those domains is rejected. `RTC_STAGING_API_BASE_URL` is the API origin only because the SSO client appends `/api/auth/get_detail_user`. The generator rejects `.invalid`/example hosts, path-bearing URLs, placeholder secrets, ordinary-stack ports, and missing values. Do not reuse production credentials or point either hostname at production.

Copy the exact current `LMS_DB_HOST_PORT` value from the backend GitHub `staging` environment into the Moodle `docker-compose.staging.env` file under the same key. The examples use `LMS_DB_HOST_PORT=13312`, matching the backend renderer/test example, and `MOODLE_STAGING_DB_PORT=13313` to avoid a collision. The backend GitHub variable is canonical, so use its current value if it differs from the example. Keep both Moodle host ports distinct from `LMS_DB_HOST_PORT`, from each other, and from other host services. The config generator rejects production port `3310` for the backend or Moodle host ports, rejects either Moodle host port when it matches `LMS_DB_HOST_PORT`, and requires the Moodle DB and web ports to differ.

```powershell
Copy-Item .\docker-compose.staging.env.example .\docker-compose.staging.env
# Set both origins from the canonical GitHub organization variables and match their hostnames.
# Keep RTC_STAGING_API_BASE_URL equal to RTC_STAGING_API_ORIGIN.
# Replace both DB passwords and the Moodle salt with distinct random 64-character hex values.
php .\docker\prepare-moodle-staging-config.php --env-file=.\docker-compose.staging.env --validate-only
if ($LASTEXITCODE -ne 0) { throw 'Staging Moodle inputs failed validation.' }
php .\docker\prepare-moodle-staging-config.php --env-file=.\docker-compose.staging.env
```

Before using the stack, run both safe validations. The hostname check passes deliberately colliding fake names to the local PHP generator, verifies matching default and non-default API origins, checks that a non-default Moodle port is retained in the generated `$CFG->wwwroot`, rejects missing or mismatched canonical origins and path-bearing URLs with `--validate-only`, and performs no DNS lookup or repository config write. The Compose check supplies fake `.invalid` values to `docker compose config` and checks that the example and fixture origins agree. Neither starts or pulls containers or accesses a database/network.

```powershell
.\docker\test-moodle-staging-hostnames.ps1
.\docker\test-moodle-staging-compose.ps1
```

To validate the actual local Compose inputs without starting services:

```powershell
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml config --quiet
```

Before accepting transfer/history results in staging, account for the legacy mapping boundary: if a delivery-to-activity association was overwritten before delivery-history tracking began, current Moodle tables cannot reconstruct the earlier association. Seed or reconcile such mappings only from an authoritative pre-overwrite backup or a reviewed mapping manifest; keep activities with unknown legacy mappings hidden. Do not infer an old delivery from the activity's current mapping.

Once the staging hostnames, backend API, DNS/TLS routing, credentials, and deployment approval are supplied by the staging operator, the local stack can be built and upgraded with:

```powershell
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml stop nginx
if ($LASTEXITCODE -ne 0) { throw 'Could not stop existing Nginx before upgrading Moodle.' }
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml up -d --build db php
if ($LASTEXITCODE -ne 0) { throw 'Could not start the staging database and PHP services.' }
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml exec -u www-data -T php php admin/cli/upgrade.php --non-interactive
if ($LASTEXITCODE -ne 0) { throw 'Moodle upgrade failed; keep Nginx stopped until it succeeds.' }
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml up -d nginx
if ($LASTEXITCODE -ne 0) { throw 'Could not start the staging Nginx service.' }
```

Stop Nginx first so an existing staging stack cannot serve requests while its database schema is being upgraded. The database becomes healthy before PHP starts. Compose holds Nginx until PHP-FPM's bounded localhost TCP listener probe on port 9000 succeeds. Run the upgrade CLI as `www-data`, matching FPM's file owner for the persistent Moodle data and cache directories. Nginx starts only after the upgrade command succeeds. The local web service is then available at `http://127.0.0.1:<MOODLE_STAGING_WEB_PORT>`; configure any local staging reverse proxy to target that loopback address and preserve the staging Host and forwarded HTTPS headers. `down` preserves the staging volumes. Do not add `-v` unless intentionally deleting the local staging database and Moodle data. This stack does not copy or migrate production data.
