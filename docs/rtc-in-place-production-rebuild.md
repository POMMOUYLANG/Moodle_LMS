# RTC Moodle in-place production rebuild

This runbook rebuilds the existing school Moodle from SMS. It does not create a
second Moodle stack. SMS is the authority for accounts, academic hierarchy,
courses, teacher credit allocations, classes/cohorts, enrolments, and published
RTC final scores.

Moodle-only learning content is not recreated by SMS. Activities, question
banks, quiz attempts, assignment submissions, files, completion, and Moodle
gradebook items must either be preserved separately or explicitly approved for
disposal.

## Safety contract

Do not reset Moodle until all of these gates pass:

1. `php artisan moodle:source-audit` exits `0`.
2. A database backup containing the RTC backend database is checksum-verified.
3. A full Moodle backup containing both `moodle-db.sql.gz` and
   `moodle-files.tar.gz` is checksum-verified.
4. The existing Moodle rebuild inventory has an approved disposition for every
   blocker.
5. Moodle synchronization and its queue workers/scheduled workflow are frozen.
6. The environment has a `MOODLE_FRESH_ADMIN_PASSWORD` secret of at least
   12 characters.
7. The operator supplies both the verified backup ID and exact confirmation
   `RESET-<school>-MOODLE`.

The guarded setup script verifies gates 1, 3, 6, and 7 before it removes either
Moodle volume. The operational owner must record evidence for gates 2, 4, and 5.

## Phase 1: deploy and audit without changing Moodle

Deploy the backend containing the rebuild safety commands, then run:

```bash
sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:source-audit'

sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:reset-mappings'

sudo docker exec rtc-bb-moodle-app \
  php /var/www/html/local/rtcsync/cli/rebuild_inventory.php --strict
```

`moodle:reset-mappings` is preview-only unless `--apply` is supplied. Do not
apply it in this phase.

## Phase 2: freeze and back up

Disable automatic, scheduled, and queued Moodle synchronization. Verify there
is no running `php artisan moodle:sync` process and no pending Moodle queue job.

Create and verify:

- a databases backup for rollback of the RTC mapping table;
- a full Moodle-scope backup for rollback of Moodle DB and Moodledata.

Copy runner-workspace backups to the durable school backup root before reset.

## Phase 3: guarded fresh installation

Use the `Moodle Setup` workflow with:

```text
target_label: rtc-bb
deploy_ref: <reviewed commit SHA>
reset_moodle_db: false
reset_moodle_data: false
fresh_install: true
verified_backup_id: <full Moodle backup ID>
fresh_install_confirmation: RESET-rtc-bb-MOODLE
```

The fresh-install option itself selects both volume resets. It also:

- validates the backup target, scope, checksums, gzip, and tar archive;
- runs the read-only SMS source audit;
- refuses a short/missing administrator password;
- skips all legacy repository Moodledata;
- creates a clean Moodle database non-interactively;
- installs the checked-out `local_rtcsync` plugin;
- never prints the database or administrator password.

## Phase 4: reconnect RTC safely

Create a token for the installed `RTC Sync Service`, replace
`MOODLE_SYNC_TOKEN` in the `rtc-bb` GitHub environment, and keep synchronization
disabled.

Verify the RTC backend database backup again. Preview the stale mappings:

```bash
sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:reset-mappings'
```

Apply only while `MOODLE_SYNC_ENABLED=false`:

```bash
sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:reset-mappings \
    --apply \
    --confirmation=RESET-MAPPINGS-rtc-bb'
```

The command writes a private JSON export under
`storage/app/private/moodle-rebuild/` before deleting mappings. It retains the
historical synchronization-attempt audit table.

Re-enable synchronization with the new token, redeploy, and run:

```bash
sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:preflight'

sudo docker exec -d rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:sync --all --no-interaction \
    > storage/logs/moodle-rebuild-sync.log 2>&1'
```

Do not deploy RTC-BB, RTC-KP, RTC-KC, or shared Caddy while the full sync is
running.

## Phase 5: acceptance

Require all of the following:

```bash
sudo docker exec rtc-bb-rtc_app sh -lc \
  'cd /var/www && php artisan moodle:reconcile --dry-run -vv'

sudo docker exec rtc-bb-moodle-app \
  php /var/www/html/local/rtcsync/cli/rebuild_acceptance.php
```

- Full sync exits successfully.
- Reconciliation reports `0 finding(s)`.
- Rebuild acceptance passes.
- Program/year/semester categories are correct.
- Multiple teachers receive separate isolated credit courses.
- Class/cohort membership matches SMS.
- Administrator, teacher, and student login/UAT pass.
- Only then restore scheduled and background synchronization.

## Rollback

If installation, synchronization, reconciliation, or UAT fails:

1. Freeze synchronization again.
2. Restore the verified RTC backend database backup.
3. Restore the verified Moodle database and Moodledata backup.
4. Restore the previous token/configuration.
5. Start services.
6. Run preflight and reconciliation before reopening access.
