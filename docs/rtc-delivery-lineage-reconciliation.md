# RTC delivery-lineage reconciliation

The plugin does not treat a module's current delivery grouping as proof of the
delivery for attempts or grades created before lineage tracking. During the
2026102800 upgrade, existing module and transition mappings become unreviewed,
existing delivery-grade mappings remain unverified,
delivery courses are sent through the bounded access backfill again, and
unreviewed modules receive the impossible grouping guard. Released results
ignore unverified module history and unverified manual-grade mappings.

## Evidence and decisions

The importer accepts a UTF-8 JSON root manifest with `schema_version: 1` and
an append-only amendment with `schema_version: 2`. The manifest is a reviewed
assertion from the Moodle data owner; source identities
are not queried from another Moodle database. `source_snapshot_sha256` declares
the expected digest of the backup or snapshot the reviewer used, and
`source_plugin_version` records the claimed source version. The `review` block records the owner, UTC approval time, and a durable
approval reference (for example, the signed migration record). The importer
checks every target identity against the live target Moodle database and
records the manifest hash, source hash, reviewer, reference, CLI operator,
target IDs, delivery IDs, decision, and reason in the lineage audit tables.
The dry-run output shows the declared provenance with `authenticated: false`;
the CLI compares apply-time confirmations with those declarations but does
not authenticate the backup, reviewer, approval reference, or operator name.

The manifest is exhaustive for unresolved plugin mappings in its target course.
Every `deliverycm` row with unreviewed lineage, every unverified
`deliverycmhist` row, every unverified `deliverygrade` row, and every
canonical RTC delivery-score item without a verified mapping must be included
in the manifest. The idnumber is used only to inventory candidate items; it
does not assign a delivery. Omitted mappings remain excluded, and validation
reports them as omissions instead of treating an incomplete file as a
completed transition.

Each module entry must include:

- `source` and `target` module identities: stable course `idnumber`, course
  module ID, module name, activity instance ID, and exact course-module
  `idnumber` (empty string when unset);
- one `decision`: `current`, `historical`, `quarantine`, `archive`, or
  `dispose`;
- `delivery_ids`: exactly one positive ID for `current` or `historical`, and
  an empty array for `quarantine`, `archive`, or `dispose`;
- a human-readable `reason`.

Both course identities must equal the root `course_idnumber`, which must have
the stable form `rtc-subject:<id>`. A current decision is accepted only when
the target course-module identity is exact and its existing grouping matches
the explicitly approved delivery ID. This is a consistency check; the importer
never chooses a delivery from that grouping. A historical decision records one
approved delivery in `deliverycmhist` and leaves the activity inaccessible.
Each `grade_items` entry includes source and target course identity,
grade-item ID, item type/module/instance, exact idnumber, decision, delivery
ID, and reason. `map` requires one positive explicit delivery ID and a manual
target item in the stable target course with exactly one matching target
grouping. For `quarantine`, `archive`, or `dispose`, `delivery_id` must be `0`;
the mapping stays unverified and the decision is audited. Existing mapped
grade items must be explicitly accounted for. Grade items with no mapping
remain absent from Released results until reviewed.

One target activity may be mapped to at most one delivery. Moodle stores a
single final grade per learner and activity grade item, not a delivery ID per
attempt. If a reused module may contain attempts from multiple deliveries, the
module-level data cannot distinguish those rows safely. The importer rejects
multiple delivery IDs and conflicting module history. The owner must provide
separate target activity/grade records with authoritative per-delivery
evidence, or approve quarantine/archive/disposal. Until then the activity and
its results remain unavailable. `archive` and `dispose` record the reviewed
retention decision and lock the module; they do not delete Moodle content or
grades.

Unmapped records omitted from a manifest are not promoted. Every unresolved
module stays quarantined by backfill or its observer. Legacy/manual grade
items without a verified `deliverygrade` association remain absent from
Released results. The normal RTC grade-sync path may verify a mapping when
the request creates a new grade item for that delivery. Sync updates to an
existing legacy item do not promote its mapping: it may contain values from
before lineage tracking and requires a reviewed manifest. Upgrade leaves every
existing delivery-grade mapping unverified regardless of its current idnumber
or grouping.

## Dry-run and apply

Run from the Moodle code root under an OS account authorized to access this
Moodle installation and its intended target database. If a Moodle auth plugin
maps a CLI user, that account is recorded as the actor; the `--operator` name
and approval fields remain declarations, not verified identities. Dry-run is
the default and is read-only. It prints mapping/disposition counts, target
module and grade-item IDs to be quarantined, conflicts, declared provenance,
and a `plan_sha256` that
covers the manifest hash and live target identities, groupings, availability,
and mapping state. It does not return learner names or grade values. Manifest,
amendment, and evidence JSON inputs are limited to 8 MiB; the source snapshot
is hashed as a stream and its path and contents are not retained.

For Windows PowerShell, invoke the existing CLI from the Moodle code root.
This previews against the supplied source snapshot, stops on a nonzero exit or
invalid plan, and retains the reviewed plan hash for apply:

```powershell
$script = '.\local\rtcsync\cli\reconcile_delivery_lineage.php'
$manifest = 'C:\secure\lineage.json'
$sourceBackup = 'C:\secure\source-snapshot.sql'
$reviewedManifest = Get-Content -Raw $manifest | ConvertFrom-Json

$previewOutput = & php $script "--manifest=$manifest" "--source-backup=$sourceBackup"
$previewExitCode = $LASTEXITCODE
$preview = $previewOutput | ConvertFrom-Json
$preview | ConvertTo-Json -Depth 8
if ($previewExitCode -ne 0 -or -not $preview.valid) {
    throw 'Preview failed. Resolve the reported conflicts and review a fresh plan.'
}
```

After reviewing the preview and approval record, repeat the manifest's
provenance values and exact preview hash. Set `$operator` to the importing
operator's name; the other values below come from the reviewed manifest:

```powershell
$operator = 'Named importing operator'
$applyArgs = @(
    "--manifest=$manifest",
    "--source-backup=$sourceBackup",
    '--apply',
    "--plan-sha256=$($preview.plan_sha256)",
    "--operator=$operator",
    "--approved-by=$($reviewedManifest.review.approved_by)",
    "--approved-at=$($reviewedManifest.review.approved_at)",
    "--approval-reference=$($reviewedManifest.review.approval_reference)",
    "--source-snapshot-sha256=$($reviewedManifest.source_snapshot_sha256)",
    "--source-plugin-version=$($reviewedManifest.source_plugin_version)"
)
& php $script @applyArgs
if ($LASTEXITCODE -ne 0) {
    throw 'Apply failed. Review the error and verify the manifest before retrying.'
}
```

The importer records approval and operator claims without authenticating them.
Use post-apply verification to check the persisted import and current target.

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php --manifest=/secure/path/lineage.json
```

Dry-run may also hash a local source snapshot artifact without retaining its
path or contents:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --manifest=/secure/path/lineage.json \
  --source-backup=/secure/path/source-snapshot.sql
```

Review the output and the referenced source backup/approval record. Apply only
after a fresh dry-run on the same target and pass its exact plan hash:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --manifest=/secure/path/lineage.json \
  --source-backup=/secure/path/source-snapshot.sql \
  --apply \
  --plan-sha256="<plan_sha256-from-dry-run>" \
  --operator="<named-operator>" \
  --approved-by="<manifest-reviewer>" \
  --approved-at="<exact-utc-time-from-manifest>" \
  --approval-reference="<exact-manifest-reference>" \
  --source-snapshot-sha256="<exact-source-hash>" \
  --source-plugin-version="<exact-source-version>"
```

Apply revalidates under the course access lock and one delegated transaction.
All apply-only confirmations are required and must match the reviewed
manifest. A mismatch fails before writes. The named operator is stored as a
declaration alongside Moodle's current actor ID; this is not an independent
authentication mechanism.
Target drift, stale IDs, cross-course references, module/type/instance
mismatches, missing or duplicate delivery groupings, reused target identities,
and contradictory mappings fail the run. A manifest ID is immutable: an
identical repeat is a no-op only with the exact plan hash saved by its original
apply; different content under the same ID is a conflict.
Per-entry decisions are retained in `local_rtcsync_lineageaudit`; import-level
provenance is retained in `local_rtcsync_linemanifest`.

The operator may ask dry-run to hash a local backup/snapshot artifact. For
apply, `--source-backup` is required: the importer streams the file in bounded
chunks and compares its SHA-256 with `source_snapshot_sha256`. It rejects an
absent, unreadable, nonregular, mismatched, or observably changed file. Apply
checks it before entering the course lock and checks it again under that lock
immediately before the write transaction. Only the computed digest, verified
flag, and verification time are retained; the path and file contents are not
stored or emitted. Post-apply verification checks this saved digest against the
manifest declaration. Imports from before this evidence existed remain
incomplete and are not backfilled as verified.

A matching digest proves only that the supplied file bytes match the digest
declared by the manifest. It does not authenticate who supplied the file,
prove that it is a particular database backup, authenticate its source, or
authenticate the reviewer, approval, or operator. The data owner must establish
those facts and keep any signed approval/backup evidence separately. A
disposable target-engine PHPUnit/upgrade rehearsal and a restore rehearsal
remain required before any operational transition.

## Append-only amendments

An applied quarantine is immutable. To resolve it later, create a new reviewed
manifest with `schema_version: 2` and an `amendment` object that names the exact
parent import and its manifest digest:

```json
{
  "schema_version": 2,
  "manifest_id": "lineage-amendment-02",
  "amendment": {
    "parent_manifest_id": "lineage-import-01",
    "parent_manifest_sha256": "<sha256-of-the-exact-parent-manifest-file>"
  }
}
```

The child repeats the parent's stable course identity, source snapshot digest,
and source plugin version. The parent must be the current chain head, must have
an applied plan hash and matching verified source-artifact digest, and must
have no existing child amendment. Apply requires the same source snapshot
artifact; its bytes must hash to the inherited source digest. This comparison
does not authenticate the backup or its custodian.

For an amendment, use the same CLI sequence shown above: first preview the v2
manifest with no `--apply`, review its parent identity, affected target IDs,
counts, conflicts, quarantine outcomes, and plan hash, then apply with that
exact plan hash and the repeated review/source provenance values. The source
digest must remain identical to the parent import. Any change to the parent
import, its source-artifact evidence, or the live target state invalidates the
preview and requires a newly reviewed manifest and dry-run.

Imports created before `2026103100` do not have the persisted original apply
plan hash or manifest bytes needed for amendment parent verification. They may
be inspected with their exact reviewed manifest, but cannot be amended through
this workflow; their quarantine remains until the data owner approves a
separate recovery decision.

The child must contain exactly every parent audit record whose decision was
`quarantine`, with no omitted or additional records. Each child entry repeats
the exact source and target identities retained in the parent audit. Module
decisions may become `current` or `historical`, or remain `quarantine`; grade
decisions may become `map` or remain `quarantine`. The amendment has its own
review metadata. It cannot reinterpret a verified mapping or historical
record. If the parent snapshot has changed, an identity differs, coverage is
incomplete, a parent hash is stale, or a record is ambiguous, preview fails
closed and the parent quarantine stays in force.

Every child decision is inserted as a new import and per-record audit row in
the same transaction as its target changes. The child audit stores the parent
entry hash and exact parent result snapshot; the parent audit is not updated.
An amendment can be followed by another amendment only when the prior child
still has quarantined records. Repeated apply is a no-op only when the caller
supplies the exact plan hash saved by the original apply. A conflicting second
child is rejected.

Each import record retains the exact manifest JSON bytes along with its digest.
The verifier recomputes every chain member's manifest digest and checks every
manifest entry against its immutable per-record audit before following the
parent links. It reports only safe chain identifiers and aggregate issues; it
does not print the stored manifest or free-text reasons.

Preview includes the parent identity and the exact module and grade-item IDs
the child would affect; outputs contain no learner values. `--verify` reports
the root-to-head chain, validates parent links and quarantine coverage, and
compares current target state with the latest audit for each record. Verifying
an ancestor checks the current chain head without rewriting or hiding the
ancestor's original audit. Chain verification checks database consistency; it
does not independently authenticate stored approvals, source backups, or
database audit rows against an external signature.

## Post-apply verification

Use the reviewed manifest file and its exact `manifest_id` to compare the
persisted import, each per-record audit decision, the recorded post-apply
target state, the full amendment chain, and the current chain-head target
state:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --verify \
  --manifest-id="<exact manifest_id>" \
  --manifest=/secure/path/lineage.json
```

Verification runs its reads inside Moodle's delegated database transaction and
uses only Moodle read APIs. The database's configured isolation determines its
snapshot behavior. The per-record `resultstate` captures target identity hashes,
delivery mapping fields, module history lineage fields, and hashes of managed
availability; it does not store learner values. The verifier compares that
snapshot with the current target and also checks that each recorded decision
matches the manifest. Output contains aggregate counts, issue field names, and
numeric target IDs only. It does not return names, grade values, activity
content, manifest reasons, idnumbers, or raw availability.

Exit code `0` means the import record and per-record audit match the supplied
manifest and the current target matches the latest per-record result in its
validated chain. Exit code `1` reports detectable
target or audit drift, missing/extra records, or inconsistent provenance
fields. Exit code `2` means the identity/manifest is invalid or unknown, or the
import lacks a recorded result snapshot or source-artifact verification and
cannot be completely verified.
A `match` confirms that the current target matches the latest recorded state in
the chain and that the stored artifact digest matches the manifest declaration; it does not
authenticate the source backup, reviewer, approval, or operator.

Imports created before `resultstate` was introduced remain without a trustworthy
apply-time target snapshot. They are reported as incomplete; the verifier does
not fabricate that historical result from current state. A new reviewed
reconciliation/correction decision is required if an operator needs a complete
record for such an import.

## Redacted evidence bundles

Export a deterministic, versioned JSON bundle from the read-only verifier:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --verify \
  --manifest-id="<exact manifest_id>" \
  --manifest=/secure/path/lineage.json \
  --export-evidence=/secure/evidence/lineage-evidence.json
```

The output path must be new; the CLI will not overwrite an existing file. The
bundle includes the full chain's import and parent IDs/hashes, apply-plan and
source snapshot digests, source-artifact verification state, plugin/schema
versions, apply and verification timestamps, aggregate issue and disposition
counts, and per-record decisions/outcomes with numeric target and delivery IDs.
It excludes learner names, grades, answers, backup paths, reviewer names,
approval references, manifest reasons, and other free-text manifest values.
The verifier and bundle exporter perform no database writes.

The bundle contains an unsigned SHA-256 checksum over its canonical contents.
It detects accidental file changes only when the expected digest is preserved
separately. The CLI prints that digest after export. Store the bundle and keep
the expected digest in a separate controlled evidence record under the
applicable records-retention process; a checksum stored only inside the bundle
does not protect against replacement of both the file and its checksum. No
signing key or institutional approval mechanism is defined by this plugin.
Neither the checksum nor a successful check authenticates the source, backup
custodian, owner, reviewer, approval, or operator.

Check format and checksum consistency, optionally comparing an expected digest
retained separately:

```bash
php local/rtcsync/cli/reconcile_delivery_lineage.php \
  --check-evidence=/secure/evidence/lineage-evidence.json \
  --expected-evidence-sha256="<digest-retained-separately>"
```

The file-only `--check-evidence` mode runs before Moodle bootstrap and does not
connect to the Moodle database. It reads at most 8 MiB and reports only format,
checksum, and safe issue-code results.

To compare a valid bundle with the current target state, add
`--verify-current --manifest=/secure/path/lineage.json`. This reruns the
read-only chain verifier for the bundle's exact import identity and compares
the fresh deterministic evidence digest. Exit `0` means the bundle matches
the current verifier result; `1` reports current drift or a different result;
`2` reports malformed content, checksum mismatch, or incomplete verification.
The file-only check validates structure and the embedded checksum; it does not
verify current Moodle state. A digest supplied with
`--expected-evidence-sha256` must be retained outside the bundle to act as an
independent comparison value.
