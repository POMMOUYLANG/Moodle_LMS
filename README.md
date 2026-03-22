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

- database name: `moodle_lms`
- database user: `moodle`
- database password: `Moodle@123`
- MySQL root password: `Root@123`

## First-Time Setup

MySQL creates the `moodle_lms` database automatically only when the DB volume is empty on first startup.

Then finish the application installation from the browser:

- Production: `https://moodle.rtc-kp.camai.kh`
- Local/internal route: `http://moodle.rtc-kp.localhost`

Use these values in the Moodle installer:

- Database type: `mysqli`
- Database host: `moodle-db`
- Database name: `moodle_lms`
- Database user: `moodle`
- Database password: `Moodle@123`

## Clean Reset

To remove the current Moodle DB volume and start again:

```bash
docker compose --env-file ../.env down
docker volume rm "${CONTAINER_PREFIX}-moodle_db_data"
docker compose --env-file ../.env up -d
```

If you also want to reset uploaded files and generated Moodle state, review the contents of `moodledata/` before deleting anything manually.

## Useful Commands

Show logs:

```bash
docker compose --env-file ../.env logs -f
```

Check running containers:

```bash
docker compose --env-file ../.env ps
```
