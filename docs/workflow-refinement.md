# Workflow refinement — 2026.09.25.01

> Release-specific record for 2026.09.25.01. The save contracts remain relevant, but the layout was superseded by [guided workflows](workflow-redesign.md) and the 2026.09.26.02 section/spacing fixes. Use the [README](../README.md) for current controls.

Replication uses one Source and destination → Schedule → Retention and space editor. Create and update save directly; Cancel restores the saved job. Removal has its own confirmation. Shared destination retention, connection and performance settings have an independent save boundary. The existing page-wide execution action is labeled “Run all jobs now.”

The save endpoint accepts `scope=job_create`, `job_update`, `job_remove`, or `shared`; an omitted scope preserves full-form compatibility. Job requests use index zero. Scoped requests merge with a configuration pair read under the configuration lock. The exclusive write transaction rechecks the submitted revision, preventing a concurrent change from turning the merge into a lost update. Responses include canonical jobs/settings, revision, and separate `saved` and `schedulerApplied` results. Source cleanup authorization and schedule conversion still run through their existing validators.

Automation separates runtime controls from Datasets → Schedule → Retention and advanced settings. Its header checkbox selects only shown eligible datasets, preserving filtered-out selections. Snapshot Manager keeps page selection in the table header, offers frozen all-matching selection contextually, and groups protection and row actions. Send/Restore dialogs explain target, read-only/writable and mount behavior before submission. Existing batch approval, GUID validation and exclusion checks remain in place.

## Verification

Run in disposable `zfsas-test-runtime` containers, with the host Node 22 binary mounted because the image's Node 18 is too old for its Playwright installation:

- Full `tests/stage1/run.sh` and `tests/reliability/run.sh`.
- `workspace_browser.cjs`, `browser.cjs`, and `source_retention_browser.cjs`: direct create/edit/cancel/save, scoped request isolation, shared drafts, failed saves, duplicate clicks, scheduler failure, source review, stale responses, 10,000 snapshots, disabled rows, page/filter selection and captured matching identities.
- `scoped_replication_save.php`: create/update/remove/shared isolation, job identity/order preservation, duplicate creation, stale revisions, failed validation, unchanged schedule timing and saved configuration with failed scheduler application.
- Actual `source_retention_endpoints.php`, with plugin/sbin runtime mounts and an explicitly started coordinator. The fixture otherwise races its immediate readiness check; waiting for the socket resolves it without production changes.
- PHP and JavaScript syntax checks, `git diff --check`, and release-package/source verification.

Screenshots captured in `/tmp/zfsas-ui-screenshots` include light/dark desktop and narrow empty, configured, editing, selected, running/draft and error states, plus Send and Restore dialogs. Editor, Automation, selection and Restore screenshots were visually inspected. These are simulated host fixtures; no installed Unraid server or live pool was modified.
