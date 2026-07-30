# RTC Sync Moodle Plugin

This local plugin exposes Moodle web-service functions used by RTC to mirror academic data into Moodle.

## What RTC Syncs

- RTC subject -> Moodle course
- RTC teacher -> Moodle user + editing teacher enrolment
- RTC student enrollment -> Moodle user + student enrolment
- RTC user-program metadata -> Moodle RTC custom profile fields
- Published RTC score -> Moodle manual grade item/grade

Student profile fields include RTC user program ID, program ID/name/Khmer/code/degree/department, generation ID/label/year range, academic year ID/label, study year, user-program promotion, enrollment status/type, previous enrollment ID, and a JSON snapshot of all RTC enrollments for that student.

Soft-deleted RTC subjects are hidden in Moodle and their RTC-managed enrolments are removed. Completed, completed-program, and failed RTC enrollments are kept in Moodle as suspended enrolments so course and grade history remains available; cancelled/withdrawn rows are removed. Soft-deleted mapped RTC users are suspended in Moodle. Unpublished or deleted RTC scores clear the synced Moodle grade.

## Moodle Setup

1. Install this folder as `local/rtcsync` in the Moodle codebase.
2. Visit Moodle admin upgrade or run Moodle upgrade CLI so the plugin is installed and the RTC Academic Data profile fields are created.
3. Enable web services and REST protocol in Moodle.
4. Create or choose a service account user.
5. Assign the service account the capabilities declared in `db/services.php`.
6. Create a token for the `RTC Sync Service` external service.
7. Put the Moodle base URL and token into the RTC backend env:

```env
MOODLE_SYNC_ENABLED=true
MOODLE_SYNC_URL=https://your-moodle-host
MOODLE_SYNC_TOKEN=replace-with-moodle-token
```

Then run the RTC migration and backfill command:

```bash
php artisan migrate
php artisan moodle:sync --all
```

For one program only:

```bash
php artisan moodle:sync --all --program-id=123
```
## Guarded Clean Rebuild

Before replacing an existing Moodle database or data directory, run the
read-only inventory:

```bash
php local/rtcsync/cli/rebuild_inventory.php --strict
```

The command blocks a clean cutover when it finds activities, submissions,
quiz attempts, final grades, or completion records that RTC synchronization
cannot recreate. Use `--json` to retain machine-readable evidence.

Run candidate-instance acceptance checks after installing the plugin and
before directing production traffic to the new instance:

```bash
php local/rtcsync/cli/rebuild_acceptance.php
```

These commands never modify Moodle. See `docs/rtc-blue-green-rebuild.md` for
the complete backup, synchronization, cutover, and rollback process.