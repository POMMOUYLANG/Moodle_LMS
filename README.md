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

The optional PHPUnit stack is separate from the normal Moodle Compose project. It uses its own MySQL 8.4 service and project-scoped named volumes on an internal network with no published ports or shared application volumes. The database, Moodle data, and runner state volumes are Compose-derived names under the fixed `rtc-moodle-phpunit` project; there are no globally named volume overrides. The runner mounts the checkout read-only, copies the code into a temporary workspace, excludes the ordinary `config.php` and generated PHPUnit config, and uses a test-only config that pins the connection to `phpunit-db` / `rtc_moodle_phpunit`.

From the `Moodle_LMS/` folder, create the ignored local credentials file and set two different random 64-character hexadecimal passwords:

```powershell
Copy-Item .\phpunit\.env.example .\phpunit\.env
```

Then initialize the isolated PHPUnit site and run the focused released-history test:

```powershell
.\phpunit\run.ps1 -Action Initialize
.\phpunit\run.ps1 -Action Test
```

`Initialize` uses Moodle's `--disable-composer` mode. The runner refuses to start if the checked-out `vendor` tree or `composer.phar` is missing, so it will not install or download dependencies. Moodle may rebuild the isolated PHPUnit schema and data when its test-version marker is stale. A different focused test can be selected with `-TestFile local/rtcsync/tests/your_test.php`.

The local `.env` is excluded from Git. The temporary source workspace is disposable, but the project-scoped database, data, and generated PHPUnit config volumes persist between runs. Before initializing on a host where these volumes already exist, verify their Compose project labels identify `rtc-moodle-phpunit`; do not point this Compose file at the normal or staging project or reuse their volumes. The static Compose check verifies the internal-only network, absence of published ports, and project-derived volume names without starting services.

## Local Staging Moodle Stack

`docker-compose.staging.yml` is a separate local staging stack. It has its own project-scoped MySQL and Moodle data volumes, staging-named containers and network, and does not join `rtc_net`. The database and web ports bind only to `127.0.0.1`. Its PHP image compiles Moodle's required `mbstring` extension. PHP mounts the Moodle checkout and generated staging `config.php` read-only; a staging-only entrypoint initializes ownership of the dedicated Moodle data volume as `www-data` before starting FPM. Nginx receives only `moodle/public` and its own configuration. The configured RTC stage API is tried first; the SSO helper's generic fallback hostname is pinned to loopback inside the PHP container.

Create a local stage-only environment file from the example. Replace every placeholder with real staging hostnames and unique generated secrets. Both hostnames must use HTTPS, include a `stage` or `staging` DNS label, and use a staging-owned DNS domain outside the RTC production suffixes `bb-rtc-edu.com`, `kc-rtc-edu.com`, `kampot-rtc-edu.com`, `rtc-edu.com`, and `rtc-kp.camai.kh`. A staging label beneath one of those domains is rejected. The generator rejects `.invalid`/example hosts, placeholder secrets, ordinary-stack ports, and missing values. Do not reuse production credentials or point either hostname at production.

Choose an unused loopback database port. The example uses `13312`; change it if that port is occupied, and keep it distinct from the backend staging MySQL bind port and any other host service. The generator also requires the Moodle web and DB ports to differ.

```powershell
Copy-Item .\docker-compose.staging.env.example .\docker-compose.staging.env
# Edit docker-compose.staging.env with the assigned staging hostname and API hostname.
# Replace both DB passwords and the Moodle salt with distinct random 64-character hex values.
php .\docker\prepare-moodle-staging-config.php --env-file=.\docker-compose.staging.env
```

Before using the stack, run both safe validations. The hostname check passes deliberately colliding fake names to the local PHP generator and confirms rejection without DNS lookup; the Compose check supplies fake `.invalid` values to `docker compose config`. Neither starts or pulls containers or accesses a database/network.

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
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml up -d --build
docker compose --project-name rtc-moodle-staging --env-file .\docker-compose.staging.env -f .\docker-compose.staging.yml exec -T php php admin/cli/upgrade.php --non-interactive
```

The local web service is available at `http://127.0.0.1:<MOODLE_STAGING_WEB_PORT>`; configure any local staging reverse proxy to target that loopback address and preserve the staging Host and forwarded HTTPS headers. `down` preserves the staging volumes. Do not add `-v` unless intentionally deleting the local staging database and Moodle data. This stack does not copy or migrate production data.
