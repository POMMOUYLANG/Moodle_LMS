# RTC Moodle Blue-Green Rebuild

## Goal

Replace a mixed legacy/synchronized Moodle with a separately validated clean
instance, without deleting the current production database or `moodledata`.

RTC is authoritative for users, programs, subject courses, teaching credits,
classes/cohorts, enrolments, and published RTC grades. Moodle remains
authoritative for teacher-authored activities, files, submissions, quiz
attempts, Moodle-native grades, and completion. RTC synchronization cannot
recreate Moodle-authored learning content.

## Mandatory safety gates

1. Never reset the live Moodle database or data volume in place.
2. Keep blue intact until the rollback window closes.
3. Do not connect blue and green to the same active synchronization token.
4. Do not cut over with non-zero reconciliation findings.
5. Do not recreate Caddy while a full Moodle synchronization is running.
6. Treat every inventory blocker as data to migrate or explicitly approve for
   disposal.

## 1. Freeze and inventory blue

Record the school target, source Git SHA, plugin/backend versions, change owner,
approver, cutover window, and rollback deadline.

Run inside the live Moodle application container:

```bash
php /var/www/html/local/rtcsync/cli/rebuild_inventory.php --json \
  > /tmp/rtc-moodle-live-inventory.json
php /var/www/html/local/rtcsync/cli/rebuild_inventory.php --strict
```

Exit status `2` means learning data exists that a fresh RTC sync cannot
recreate. Each blocker needs one recorded disposition: migrate, archive,
export, or discard with data-owner approval.

Freeze Moodle content changes only for the final backup/cutover window.

## 2. Verify recoverability

The root deployment repository owns backup/restore. Required evidence is:

- Moodle database dump and checksum;
- complete Moodle data archive and checksum;
- plugin/configuration archive and checksum;
- source Git SHA and backup timestamp;
- successful isolated restore rehearsal.

Example:

```bash
cd /path/to/verified/backup
sha256sum -c SHA256SUMS
```

A database-only backup is insufficient. Stop when a required artifact is
missing or a checksum fails.

## 3. Create green resources

The root deployment project must create separate resources, for example:

- `rtc-bb-moodle-green-app`;
- `rtc-bb-moodle-green-db`;
- `rtc-bb_moodle_green_db_data`;
- `rtc-bb_moodle_green_data`;
- an internal-only candidate hostname.

Green must run the same Moodle core version and required plugins/theme, use new
DB/data volumes, remain off the production hostname, and have a separate RTC
Sync service token.

Install and validate:

```bash
php /var/www/html/admin/cli/upgrade.php --non-interactive
php /var/www/html/admin/cli/purge_caches.php
php /var/www/html/local/rtcsync/cli/rebuild_acceptance.php
```

## 4. Synchronize green

Point a controlled backend execution at the candidate URL/token. Do not replace
production secrets yet. Execute in this order:

1. `moodle:preflight`
2. one known user
3. one known subject
4. one multi-teacher subject and both isolated credits
5. one class/cohort with students
6. one student enrolment
7. one published RTC grade, when available
8. `moodle:sync --all --no-interaction`
9. `moodle:reconcile --dry-run -vv`

Required result:

```text
Moodle sync completed.
0 finding(s)
```

## 5. Preserve learning content

For each live-inventory blocker, migrate Moodle course content using controlled
Moodle backup/restore or preserve it in an archive category. After import,
verify that course restoration did not duplicate RTC-managed users,
enrolments, categories, courses, or idnumbers.

Run acceptance again after content migration:

```bash
php /var/www/html/local/rtcsync/cli/rebuild_acceptance.php
```

## 6. UAT green

Validate with non-administrator accounts:

- one SuperAdmin/manager;
- two teachers assigned separate credits in the same subject;
- one enrolled student;
- one user who must not have access.

Collect evidence for hierarchy, stable RTC idnumbers, teacher-credit isolation,
class/cohort membership, student enrolment, RTC-owned grades, restored
activities/files, and absence of duplicate managed objects.

## 7. Controlled cutover

Cutover requires explicit production approval:

1. announce the maintenance window;
2. stop scheduled/background Moodle synchronization;
3. freeze SMS and Moodle writes;
4. take and verify the final backup;
5. run final green sync and zero-finding reconciliation;
6. change only the Caddy Moodle upstream to green;
7. reload Caddy without recreating unrelated services where possible;
8. validate HTTPS, login, access, and sync health;
9. resume background synchronization;
10. release the write freeze after approval.

Do not delete or reuse blue volumes.

## 8. Rollback

Rollback on authentication failure, missing content, incorrect access,
hierarchy drift, reconciliation findings, persistent API failure, or data-owner
rejection:

1. stop green synchronization;
2. restore the Caddy upstream to blue;
3. validate blue HTTPS/login;
4. restore the backend Moodle URL/token if changed;
5. run blue preflight before resuming synchronization;
6. preserve green logs and data for investigation.

No DB restore is needed when blue remained intact and read-only. If either side
accepted writes after cutover, escalate before selecting an authoritative data
set.

## Closure evidence

Close only after recording verified backup/restore evidence, inventory blocker
dispositions, candidate acceptance, full sync output, zero-finding
reconciliation, UAT, cutover/rollback validation, owner approval, and the date
when blue may be retired.