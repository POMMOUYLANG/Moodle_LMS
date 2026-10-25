# RTC Sync Moodle Plugin

This local plugin exposes Moodle web-service functions used by RTC to mirror academic data into Moodle.

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

Student profile fields include RTC user program ID, program ID/name/Khmer/code/degree/department, generation ID/label/year range, academic year ID/label, study year, user-program promotion, enrollment status/type, previous enrollment ID, and a JSON snapshot of all RTC enrollments for that student.

Soft-deleted RTC subjects are hidden in Moodle and their RTC-managed enrolments are removed. Completed, completed-program, and failed RTC enrolments remain suspended under the existing sync policy. For withdrawn and transferred students, Moodle retains source-scoped markers for released delivery history. Students can open **Released results** from site navigation while their manual enrolment is active or suspended, even when the course is hidden; course visibility and suspended enrolment still block course activities. A course module stays bound to its original delivery once it has been assigned there. If a teacher changes it to another delivery, the plugin preserves its old delivery mapping for released-grade history and places the reused module behind an impossible grouping. Create a separate Moodle activity instance for the new delivery; do not copy learner attempts or submissions. This keeps the prior activity state private while the learner can use the new delivery's activities. The page revalidates the RTC token and active Student identity on each request, sends no-store headers, and does not expose activities, submissions, files, or answers. Delivery-participation history only returns visible, non-null activity grades tied to a module assigned to the matching `rtc-delivery-grouping`, or explicitly synchronized delivery-scoped SMS score items. User-program history remains limited to suspended enrolments. Manual or legacy grade items without a delivery mapping are omitted from delivery history. Hidden-category descendants, category/course aggregate totals, and hidden or not-yet-visible grade rows are excluded before pagination. Inactive or mismatched RTC accounts are denied. Soft-deleted mapped RTC users are suspended in Moodle. Unpublished or deleted RTC scores clear the synced Moodle grade.

Delivery-aware courses use Moodle's standard availability grouping condition on every activity module. Modules assigned to an exact `rtc-delivery-grouping:<deliveryid>` receive that grouping condition. Untagged modules receive an impossible grouping condition and are inaccessible to students. The first delivery preflight hides the course and suspends active managed Student enrolments while a bounded backfill guards existing modules; the `local_rtcsync_prepare_delivery_course` service returns `ready=false` until the guard is complete. A successful fresh Laravel `syncSubject` reactivates only users in its current authoritative roster; Moodle readiness or a stale grouping snapshot never reactivates users. Activity access also requires the complete per-user delivery scope synchronized by `local_rtcsync_sync_student_delivery_scope`. That scope is replaced by a monotonic SMS revision before course enrolment is activated; lower revisions cannot restore a previous delivery, and a missing scope fails closed. Upgrade does not infer scopes from Moodle group membership, so active learners need a current SMS scope sync before delivery activities reopen. This scope only gates activities and calendar events; source-scoped Released results history remains separate. If the queued sync exhausts its retries, the users remain suspended until the failed Moodle sync is retried from the monitored recovery path. Moodle's scheduled task continues pending backfills. Availability restrictions must be enabled on the Moodle site; otherwise preflight remains fail-closed.

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
Calendar web services, HTML month/day/upcoming views and blocks, and iCal feeds
preserve personal, site, and unmanaged-course events, but filter managed-course
events to current delivery modules. Event-description attachments use the same
current-delivery check. A module-less group event is shown only when its group
is the active Student's explicit member of a matching delivery-scoped grouping;
this depends on the last successful delivery roster sync, so transfer sync-lag
acceptance remains open. Course events and malformed or unmapped groups stay
hidden. H5P content-file requests trace the core content ID back to its
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

Delivery rosters use delivery-scoped child group idnumbers in the form `rtc-delivery-class-group:<deliveryid>:<childgroupid>`. Ordinary class rosters keep `rtc-class-group:<childgroupid>`. This gives each delivery its own Moodle group even when SMS uses the same child group in multiple deliveries; legacy shared class groups are detached safely as new scoped groups arrive.

History marker writes use `historyaccess` (`0` or `1`), `historysourcetype` (`user_program` or `delivery_participation`), a positive `historysourceid`, and `deliveryid` (`0` for suspended user-program history, positive for delivery participation). The marker key is Moodle user + course + source type + source ID; a valid delivery marker also stores its delivery ID. `historyaccess=0` clears only that source marker. `local_rtcsync_sync_history_marker` can retain delivery history for an active or suspended manual Student enrolment; `enrol_user` keeps user-program markers tied to suspended enrolments. Separately, `local_rtcsync_sync_student_delivery_scope` accepts a full `deliveryids` list and nonnegative `revision`; it validates the course readiness and exact delivery grouping IDs, stores sorted unique IDs, ignores lower revisions, and rejects a same-revision set mismatch. An empty list explicitly denies delivery activity access while leaving history markers intact.

The `local_rtcsync_upsert_grade` payload accepts `deliveryid`. Positive IDs require the exact item idnumber `rtc-subject-score:<subjectid>:delivery:<deliveryid>` and create an idempotent delivery-grade association. `deliveryid=0` preserves legacy subject-wide score sync but does not make that grade visible in delivery history.

The student Released results page uses bidirectional keyset pagination with a maximum of 50 grade rows per request. This keeps deep history pages reachable without increasing SQL offset work; cursor values only choose the page position and every query remains scoped to the signed-in Moodle user.

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
