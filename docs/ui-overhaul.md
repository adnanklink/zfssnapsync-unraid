# Production workspace

Updated for **2026.09.26.02**. The earlier `feat/ui-overhaul` preview instructions, original-plugin identity and two-stage job save flow are superseded. Install the standalone plugin through the [current main manifest](../README.md#install-and-update).

The workspace is rendered by `workspace.php` through the Unraid `.page`. Section names are allowlisted; navigation uses normal URLs. Existing settings, send, migrator, and Snapshot Manager URLs enter the same shell. Legacy Automation and Tools links still resolve. Mutation endpoints retain their validation and authorization boundaries.

## Workflows

- **Overview:** snapshot/backup configuration, bounded recent work, upcoming schedules and reported failures. It does not certify pool health or backup completeness.
- **Automatic snapshots:** three-step first setup, then Data, Schedule, History to keep and Advanced summaries. Section editing shows fields directly. Saves retain revision checks, legacy schedule conversion, Dry Run and separate scheduler-application feedback.
- **Snapshots:** combined dataset/search header, optional filters, aligned Take snapshot and Preview cleanup controls, server pagination, captured matching selection, protection and per-row actions. Exact identities, review expiry and bounded batch execution remain unchanged.
- **Backup copies:** in-page setup and section editing. Create job and Save job persist directly; Cancel asks before discarding changes. Shared History, Connection and Performance settings have their own save boundary and can return to a preserved job draft. Run all jobs now uses saved configuration.
- **Activity:** reported phases, checklist, waits, errors, cancellation and applicable recovery. Job logs and shared logs have distinct scopes; technical details are optional. Cancellation acceptance is distinct from verified worker shutdown.
- **Settings:** interface preference, Dataset Migrator and diagnostics. Migration follows Select data, Review and Run using the existing preview/acknowledgment and worker safety checks.
- **Help:** operating guidance, schedule semantics, reboot limits and support.

## Runtime boundaries

Summary/status requests read bounded runtime sources and saved configuration without starting services or scanning ZFS/SSH/Docker inventories. Explicit discovery and preview requests perform inspection. Unavailable sources are reported. Visibility-aware polling, response generations and retained UI state prevent stale responses from replacing current work.

Local replication uses native coordinator phases; SSH retains the network queue path. Runtime history and review authority disappear on reboot; persistent pauses and migration recovery checkpoints are separate. See [current implementation gaps](status-audit.md#gaps-that-remain-supported-by-the-evidence).

## Verification and screenshots

- `workspace_browser.cjs`: production sections, themes, responsive layouts, scoped saves, drafts, runtime failure and actions.
- `guided_workflow_browser.cjs`: 1366×768 setup bounds, direct section fields, cancel restoration and shared-connection return.
- `browser.cjs`: 10,000 snapshots, frozen matching selection, filters, stale responses, snapshot dialogs and action/pagination alignment.
- `source_retention_browser.cjs` and `recovery_browser.cjs`: review binding, log scope, partial recovery and stale-response rejection.
- `workspace_endpoints.php`: actual legacy routes and bounded read-only runtime interfaces.

The older `config_browser.cjs` still references removed editor controls and is not current coverage of this UI. Its relevant journeys are covered by the suites above. Run production-path fixtures only in disposable containers. [Screenshot documentation](screenshots/README.md) explains current captures; fixture screenshots do not replace validation within an actual Unraid host theme.
