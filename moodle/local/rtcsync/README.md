# RTC Sync Moodle Plugin

This local plugin exposes Moodle web-service functions used by RTC to mirror academic data into Moodle.

## Moodle core compatibility

The plugin metadata declares a minimum Moodle core version of `2025041400`
(Moodle 5.0.0). The core included in this checkout is Moodle 5.0.2 (version
`2025041402`), which meets that install floor. The minimum-version declaration
is not a tested-version matrix and does not establish compatibility with later
Moodle releases. Before selecting another core release, verify the target's
PHP and database requirements and run the RTC Sync test suite and plugin-upgrade
path against that exact release.

## What RTC Syncs

- RTC subject -> Moodle course
- RTC teacher -> Moodle user + editing teacher enrolment
- RTC student enrollment -> Moodle user + student enrolment
- RTC user-program metadata -> Moodle RTC custom profile fields
- Published RTC score -> Moodle manual grade item/grade
- Selected Moodle activity grades -> RTC/SMS formative teacher score

## SMS Formative Sync

Teachers with course editing access can open **SMS formative sync** from the
course navigation. The page keeps the SMS destination fixed as Formative and
lets the teacher:

- turn automatic synchronization on or off for that course;
- include only the quizzes, assignments, or other graded activities they want;
- customize the SMS label for each included activity; and
- assign activity percentages that total 100% (or balance them automatically).

SMS imports only opted-in courses and waits until every selected activity has a
grade for the student. Turning synchronization off stops future updates and does
not erase the last formative score already stored in SMS. The RTC backend
scheduler runs `moodle:import-grades --apply` every five minutes.
Moodle exports formative grades only for nondeleted local users whose account
idnumber matches their RTC ownership marker; duplicate stable IDs or markers
are excluded to avoid assigning one person's grade to an ambiguous identity.
Final RTC score read-back for reconciliation applies the same current ownership
marker, local-host, and uniqueness checks before returning grade rows.
Delivery-managed courses export formative activity grades only after backfill is
ready and only from modules with current delivery lineage; unreviewed and
historical module grades remain out of the formative sync.

Student profile fields include RTC user program ID, program ID/name/Khmer/code/degree/department, generation ID/label/year range, academic year ID/label, study year, user-program promotion, enrollment status/type, previous enrollment ID, and a JSON snapshot of all RTC enrollments for that student.

Soft-deleted RTC subjects are hidden in Moodle and their RTC-managed enrolments are removed. Completed, completed-program, and failed RTC enrolments remain suspended under the existing sync policy. For withdrawn and transferred students, Moodle retains source-scoped markers for released delivery history. Students can open **Released results** from site navigation while their manual enrolment is active or suspended, even when the course is hidden; course visibility and suspended enrolment still block course activities. A course module stays bound to its original delivery once it has been assigned there. If a teacher changes it to another delivery, the plugin preserves its old delivery mapping for released-grade history and places the reused module behind an impossible grouping. Create a separate Moodle activity instance for the new delivery; do not copy learner attempts or submissions. This keeps the prior activity state private while the learner can use the new delivery's activities. The page revalidates the RTC token and active Student identity on each request, sends no-store headers, and does not expose activities, submissions, files, or answers. Delivery-participation history only returns visible, non-null activity grades tied to a verified current or historical module mapping to the matching `rtc-delivery-grouping`, or verified delivery-scoped SMS score items. User-program history remains limited to suspended enrolments. Manual or legacy grade items without a verified delivery mapping are omitted from delivery history. Hidden-category descendants, category/course aggregate totals, and hidden or not-yet-visible grade rows are excluded before pagination. Inactive or mismatched RTC accounts are denied. Soft-deleted mapped RTC users are suspended in Moodle. Unpublished or deleted RTC scores clear the synced Moodle grade.

Delivery-aware courses use Moodle's standard availability grouping condition on every activity module. Modules assigned to an exact `rtc-delivery-grouping:<deliveryid>` receive that grouping condition. Untagged modules receive an impossible grouping condition and are inaccessible to students. The first delivery preflight hides the course and suspends active managed Student enrolments while a bounded backfill guards existing modules; the `local_rtcsync_prepare_delivery_course` service returns `ready=false` until the guard is complete. A successful fresh Laravel `syncSubject` reactivates only users in its current authoritative roster; Moodle readiness or a stale grouping snapshot never reactivates users. Activity access also requires the complete per-user delivery scope synchronized by `local_rtcsync_sync_student_delivery_scope`. That scope is replaced by a monotonic SMS revision before course enrolment is activated; lower revisions cannot restore a previous delivery, and a missing scope fails closed. Desired delivery-group rosters are staged separately from real Moodle membership, so roster writes outside the effective scope cannot expose old-delivery calendar events; a scope update applies staged memberships only for newly active deliveries and removes memberships from prior deliveries. Active subject enrolment applies eligible staged groups under the course access lock, and page requests reconcile them again before calendar rendering. Retained released marks remain readable from source-scoped history markers. Activity and course-search guards also recheck the synced Moodle account's deleted/suspended status, including existing sessions after an account is deactivated. Upgrade does not infer scopes from Moodle group membership, so active learners need a current SMS scope sync before delivery activities reopen. If the queued sync exhausts its retries, the users remain suspended until the failed Moodle sync is retried from the monitored recovery path. Moodle's scheduled task continues pending backfills. Availability restrictions must be enabled on the Moodle site; otherwise preflight remains fail-closed.

Each `rtc-delivery-grouping:<deliveryid>` idnumber must resolve to exactly one grouping in its subject course. Duplicate identities block roster synchronization, scopes that include that delivery, new score mappings, history grants, current module access, group calendar visibility, and Released results until the duplicate is reviewed and resolved.

The scope API accepts native nonnegative PHP integer revisions on 64-bit Moodle and persists them in a signed 64-bit field. Revisions below `2026102505` are rejected. SMS uses a `YYYYMMDD * 1,000,000,000` date floor, then increments within the day, so eligibility changes at a date boundary remain newer even without a participation-row mutation. Equal revisions are idempotent only for the same delivery set.

For course-module-scoped `require_login()` requests, the plugin also checks the
live module row against its persisted delivery mapping and guarded availability.
This denies Student activity, module-file, and module-context web-service
requests while an edited or new module is waiting for its observer guard. Staff
with activity-management or availability-override permissions keep their
existing access. For course-context requests without a module object, active
Students in a delivery-managed course are allowed through only when
every live module has a matching persisted guard and the cached course modinfo
agrees with the live availability and grouping fields. This protects
course-content and search routes that filter results using module visibility.
The global forum post report checks all managed courses for active and suspended
manual Student enrolments, even though it calls `require_login()` without a
course. Suspended Students stay on the separate Released results surface and
cannot use cached forum visibility to read course posts. Managed status comes
from the plugin's persisted course state or delivery grouping, so changing a
course's editable `idnumber` cannot disable the runtime guard. Moodle's core
course-contents web service filters delivery modules before returning metadata
or descriptions, activity intro attachments check the current delivery, and
SCORM AICC runtime requests revalidate the exact module for the authenticated
learner. Feedback item attachments require a module-aware login. Course-context
APIs that return activity data without a module context and do not apply modinfo
visibility need their own access check. The standalone Released results page
does not require course login and remains available under its separate
identity/history checks.

The User and Overview grade reports, their mobile web services, and the course
profile grade view are not the retained-history surface; a current RTC Student
is sent to Released results instead. The site-level or course-level completion
report is also redirected for the Student's own managed-course history.
Core calendar collectors (`calendar_get_events`, `calendar_get_legacy_events`,
and `calendar_get_view`) and the calendar event-list and event-by-ID web
services call `calendar_event_is_in_current_delivery`. This keeps HTML views,
blocks, iCal feeds, and calendar web services scoped to current delivery
modules; personal, site, and unmanaged-course events remain available.
Event-description attachments use the same current-delivery check. A
module-less group event is shown only when the active Student is an actual
member of its delivery-scoped group and that group's delivery is in the stored
scope. Scope changes remove out-of-scope memberships immediately, and roster
syncs stage desired membership until both the scope and active manual Student
enrolment authorize it. The authoritative scope remains the last accepted SMS
revision until a new scope sync arrives, so transfer timing still depends on
that sync completing; after it lands, later stale roster writes cannot restore
the old membership. Course events and malformed or unmapped groups stay hidden.
H5P content-file requests trace the core content ID back to its
`mod_h5pactivity` package and require the same current module access check;
H5P statements, state reads/writes/deletes, state-ID listings and bulk state
deletes also enforce current delivery access for managed H5P activities.
Package exports use the same guard. H5P content-bank items and unrelated xAPI
components keep Moodle's normal handling.

Grade-feedback and grade-history-feedback files follow current module access.
Students can fetch only non-null final marks that are visible now; grade display
must be enabled, the module item must be visible, and no grade-category ancestor
may be hidden. `grade:viewall` staff retain gradebook access, while active manual
Student enrolments still require current module access. Suspended learners use
Released results for retained marks; that page does not serve feedback files.
The course-context `core_grades_get_feedback` AJAX endpoint applies the same
Released-results restriction to managed Students before returning grader
feedback; gradebook staff with `moodle/grade:viewall` retain access.

Delivery rosters use delivery-scoped child group idnumbers in the form `rtc-delivery-class-group:<deliveryid>:<childgroupid>`. Ordinary class rosters keep `rtc-class-group:<childgroupid>`. This gives each delivery its own Moodle group even when SMS uses the same child group in multiple deliveries; legacy shared class groups are detached safely as new scoped groups arrive.

History marker writes use `historyaccess` (`0` or `1`), `historysourcetype` (`user_program` or `delivery_participation`), a positive `historysourceid`, and `deliveryid` (`0` for user-program history, positive for delivery participation). The marker key is Moodle user + course + source type + source ID; a valid delivery marker also stores its delivery ID. A delivery-participation source cannot be remapped to a different delivery after it has been recorded; represent a transfer into another delivery with its distinct participation source ID. A conflicting grant repeat fails and leaves the original marker intact. A delivery-history clear for a source already bound to another delivery fails with error code `deliveryhistorymarkermismatch` and leaves the conflicting marker intact. `historyaccess=1` grants remain source-specific and can retain released history for a delivery outside the learner's current scope. For `historyaccess=0` with `historysourcetype=delivery_participation`, `sync_history_marker`, `enrol_user`, and `unenrol_user` require `expectedscoperevision`; Moodle clears only when it exactly matches the current stored scope revision and that scope includes the source delivery. A missing or stale revision, absent/invalid scope, or out-of-scope delivery leaves the marker unchanged and returns `applied=0` (or `historyapplied=0` for enrolment calls) plus the effective `scoperevision`. These applied flags report whether a marker row changed, so an authorized repeat clear of an already-absent row also returns `0`. User-program marker clears remain independent and return scope revision `0`. `sync_history_marker` can retain delivery history for active or suspended manual Student enrolments; `enrol_user` ties user-program history grants to suspended enrolments. The `local_rtcsync_prepare_delivery_course` response exposes the installed `plugin_version` and stays not-ready below `2026103101`; Backend must require at least `2026103101` before sending `expectedscoperevision`, and must use the effective `revision` returned by `local_rtcsync_sync_student_delivery_scope`. That scope service accepts a full `deliveryids` list and revision `>= 2026102505`; it validates course readiness and exact delivery grouping IDs, stores sorted unique IDs, ignores lower revisions, and rejects a same-revision set mismatch. An empty list explicitly denies delivery activity access while leaving history markers intact.

The `local_rtcsync_upsert_grade` payload accepts `deliveryid`. Positive IDs require the exact item idnumber `rtc-subject-score:<subjectid>:delivery:<deliveryid>` and create an idempotent delivery-grade association. An existing association must match the requested course and delivery; stale mappings are rejected. `deliveryid=0` preserves legacy subject-wide score sync but does not make that grade visible in delivery history. An item that already has a delivery-grade association cannot be updated through the legacy (`deliveryid=0`) path; use the matching positive delivery ID or a separate legacy grade item.

The student Released results page uses bidirectional keyset pagination with a maximum of 50 grade rows per request. This keeps deep history pages reachable without increasing SQL offset work; cursor values only choose the page position and every query remains scoped to the signed-in Moodle user.

## RTC User Identity Reconciliation

The canonical Moodle user `idnumber` and `local_rtcsync_user.idnumber` are `rtc-user:<RTC users.id>`. ID cards, names, and email addresses are not stable RTC identity keys. SSO resolves an existing account only through that canonical idnumber or a managed marker with the exact canonical idnumber; it creates new RTC users with the canonical value and marker. A legacy ID-card account is migrated by the sync API only when it receives an explicit Moodle user mapping or already has a marker for the exact stable RTC ID. Duplicate, conflicting, or card/email-only matches fail closed. The plugin relies on the `moodleid` supplied by the sync call and does not independently verify the provenance of the backend mapping. Before migrating an old account without a stable marker, the data owner must confirm the stored RTC-to-Moodle mapping or repair it from authoritative records; do not link it by matching personal details. Released results require the canonical marker. A legacy Moodle idnumber is tolerated only while it uniquely matches the current ID card and its marker already proves the same stable RTC user; changed or duplicate legacy cards remain denied until the account is migrated.
Stable IDs remain reserved by ownership markers on deleted Moodle accounts. Conflicting or duplicated markers are omitted from user-state and formative-grade reads and deny Released results; the data owner must resolve tombstoned ownership from authoritative records before a replacement account can use that RTC ID.

## RTC Credit Course Ownership

Each newly created `rtc-credit-course:<id>` is bound to the exact parent Moodle subject course supplied by the sync call. Moodle stores that owner and the unchanged credit idnumber in `local_rtcsync_creditowner`; the link remains after the credit course is hidden. Repeated sync and cleanup are allowed only when the stored parent course ID matches exactly. Course category equality is not ownership evidence.

The v2026110200 upgrade creates the ownership table empty and does not backfill legacy credit courses. Sync or cleanup of an unlinked legacy credit fails with a data-owner remediation message. Confirm the exact RTC credit-to-subject mapping from authoritative records before any reviewed repair; do not infer it from category, name, or roster. The additive table can be removed during a rollback only while it is empty. Once ownership rows have been written, retain the table and forward-roll the plugin rather than discarding the ownership record or running older credit mutations that ignore it.

## Subject-Course Source Revisions

The local_rtcsync_upsert_course service accepts optional source_revision and idempotency_key fields inside its course payload. Versioned requests must use a positive integer revision and deterministic key rtc-course:<subject id>:<revision> for the stable course identity rtc-subject:<subject id>. The key is checked against both identities before any Moodle write. Moodle stores the latest revision, key, normalized source-payload hash, visibility, course ID, and conditional-claim token in local_rtcsync_coursesync; the unique stable-ID row is retained when a subject is soft-archived (visible=0). A legacy upsert may create an identity row at revision zero; this is not a source baseline.

Before the first versioned request for a subject, unversioned calls remain compatible. That first request establishes the positive revision fence; later unversioned mutations for that subject are rejected. A strictly higher revision is conditionally claimed inside the database transaction before category or course mutations. Competing claims retry from a fresh transaction snapshot, so the highest stored revision cannot be overwritten by a lower concurrent request even when Moodle's configured named-lock factory is node-local. A lower revision is an unapplied no-op and returns the effective revision. Repeating the same revision, key, and payload is an unapplied idempotent replay; reusing the revision with a different payload or key is rejected. Missing or manually reassigned Moodle targets behind an existing fence fail closed instead of being recreated or remapped.

The v2026110600 upgrade creates the revision table empty and the v2026110800 upgrade adds the conditional-claim token; neither infers a starting revision from Moodle course fields. Before enabling versioned calls, the source owner must arrange for the first accepted revision to represent the authoritative subject state and ensure every later course create, update, and soft-delete path uses the same monotonic source revision and deterministic key. Legacy source calls must be drained or otherwise serialized before opt-in; after opt-in the receiver rejects them. The Moodle receiver implementation alone does not prove that the RTC source increments revisions atomically with its outbox or that all mutation paths publish them; Backend coverage remains a separate release gate. Do not seed a baseline from course name, dates, visibility, or local modification time.

The pre-revision plugin does not enforce rows in `local_rtcsync_coursesync`. If a code rollback to that version is required after any revision row has been written, stop course-sync workers and inbound mutations first, preserve the revision table, restore the revision-aware plugin, and forward-roll before resuming sync. Do not let an older receiver process queued course mutations while revision rows exist; that would bypass the freshness fence.

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

## Delivery-Lineage Reconciliation

Current groupings do not prove the delivery of legacy attempts or grades.
Existing and restored activity modules remain inaccessible until the data
owner's reviewed mapping or explicit quarantine/archive/disposal decision is
applied. Validate first, then apply with the exact dry-run plan hash:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php --manifest=/secure/path/lineage.json
```

Dry-run may include `--source-backup=<path>` to hash the source snapshot
artifact. Apply requires that option and rechecks the file immediately before
the write transaction. The importer stores only the digest, verified flag, and
time; it does not store or emit the path or contents. A digest match proves the
file bytes match the manifest declaration, but does not authenticate the
backup, source, reviewer, approval, or operator.

Applying requires the matching plan hash, operator, and exact reviewer/source
provenance confirmations printed by the manifest; those declarations are
recorded but not authenticated. v2 amendments append reviewed decisions only
for the exact quarantined records in their named parent import. The verifier
checks the full amendment chain and current chain-head state. The v1 manifest,
v2 amendment contract, conflict rules, target checks, audit fields, and
unresolved multi-delivery limitation are documented in
`docs/rtc-delivery-lineage-reconciliation.md`.

After apply, verify the exact recorded import with the reviewed manifest:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --verify --manifest-id="<exact manifest_id>" --manifest=/secure/path/lineage.json
```

Exit `0` means the import audit matches and the target matches the current
chain head; `1` reports drift or audit inconsistencies; `2` means the import is
unknown, invalid, or lacks a complete apply-time result or source-artifact
verification. Verification is read-only and does not authenticate the backup,
approver, approval reference, or operator declaration.

## Moodle 5.3.1 purge-protection limits

The Moodle 5.3.1 profile was inspected from the checksum-verified stock
archive. It does not include `core_course\hook\before_course_data_purge`, so
the generic purge callback registered conditionally in `db/hooks.php` is not
available there. The Moodle [Hooks API](https://moodledev.io/docs/5.3/apis/core/hooks)
allows plugins to register callbacks for hooks core actually dispatches.
`StoppableEventInterface` stops later callbacks in that dispatch; the core
operation itself is cancellable only if its emitter checks the stopped state
and aborts before mutating data.

| Preservation case | Moodle 5.3.1 call path | Plugin-only cancellation |
| --- | --- | --- |
| Restore overwrite | `backup/controller/restore_controller.class.php` calls `restore_dbops::delete_course_content()`, which delegates to `remove_course_contents()` in `lib/moodlelib.php`. This removes grades, blocks, module data, and other course content without a pre-purge hook. | No supported veto. Core needs a stoppable hook before the delegate runs and must abort the restore when stopped. Until then, do not overwrite an RTC-managed course; restore into a new course under the approved recovery process. |
| Course reset | `course/reset.php` and `core_course\task\reset_course::execute()` reach `reset_course_userdata()` in `lib/moodlelib.php`. It triggers `core\event\course_reset_started`, then begins reset work; the event is not a stoppable hook. | No supported veto. Core needs a `before_course_reset` stoppable hook and an abort branch before reset mutations. Until then, exclude RTC-managed courses from every reset route, including UI, scheduled, web-service, and CLI routes. |
| Unmapped RTC score identifier during course deletion | `delete_course()` in `lib/moodlelib.php` dispatches `core_course\hook\before_course_deleted` before marking the course for deletion. The hook implements `StoppableEventInterface`, but Moodle 5.3.1 does not check `isPropagationStopped()` after dispatch. | RTC's existing callback fails closed by throwing before the course is marked. The regression now exercises real `delete_course()` and checks the course and score item remain. This exception path is not a documented cancellation contract; core must honor the stopped state and return before deletion for supported cancellation. |
| Standalone grade-item deletion | `grade_item::delete()` in `lib/grade/grade_item.php` deletes grade rows and the item, then triggers `core\event\grade_item_deleted` within its transaction. There is no pre-delete hook; the event follows the destructive calls. | No supported veto. Core needs a stoppable pre-delete hook before `delete_all_grades()` and must abort the method when stopped. Until then, do not manually delete grade items belonging to RTC-managed courses through any UI, API, or CLI path. |
| Released history alone | The preservation test uses the course-reset operation. The history row is stored in `local_rtcsync_history` without a delivery-course marker; the reset path remains `reset_course_userdata()`. | Same reset-hook requirement. The course-deletion callback's history check does not protect the separate reset operation. |

Until core provides those operation-level abort checks or an approved gate is
enforced across all entry points, treat restore-overwrite, course reset,
course deletion, and grade-item deletion on courses with RTC delivery state,
released history, or an RTC score identifier as restricted operations. The
unmapped-score regression targets the real course-delete path and confirms the
course and score item remain when the RTC callback fails closed.

Current isolated Moodle 5.3.1 verification (16 November 2026): the full RTC
Sync PHPUnit suite passed **215 tests, 2,140 assertions, 0 failures, 0 errors,
and 0 notices**, with four skipped tests for restore overwrite, course reset,
standalone grade-item deletion, and released-history-only reset. Those skips
are release blockers because stock Moodle has no supported cancellable
pre-operation hook for those paths. RTC Sync release **1.12.16**
(`2026111601`) also replaces legacy empty course-fence tokens with an inert
32-character sentinel and updates the database default; its upgrade regression
passed. Full logs and profile cleanup evidence are in
`../../../outputs/moodle531-compat-20261116/PROFILE-REPORT.md`.
