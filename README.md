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
