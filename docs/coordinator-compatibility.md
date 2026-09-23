# Coordinator compatibility and Details

The service handshake captures a content fingerprint at startup, protocol revision 1, and supported action names. PHP readers compare it with the installed source. Missing handshake fields are treated as an older service. Status includes compatibility and the RAM refresh blocker; job-history and recovery endpoints return structured capability errors instead of forwarding unknown commands.

`php/coordinator-lifecycle.php` serializes installation and root watchdog changes on a permanent RAM lock. For supported services, a RAM admission barrier stops new attempts while granted workers finish. A status round trip fences admission already in progress. Replacement requires committed attempt completion and empty recorded process groups, using PID start times to reject reused identities. Legacy send records and worker entry points are checked too. Only the idle daemon receives TERM; no transfer receives a lifecycle signal. Queued tasks, journal history, receipts, configuration, pauses, and permanent lock inodes are preserved.

Pre-handshake services only recognize the persistent maintenance flag. Automatic watchdog handling reports that an explicit installation is required, because it cannot safely establish that older barrier without writing a persistent control decision. The plugin manifest extracts incoming lifecycle code into RAM and performs the explicit preflight before `upgradepkg`. A busy preflight leaves installed files intact. The installation hook and final handshake are separately checked, including package managers that ignore `doinst.sh` failures. Direct package-manager invocation cannot offer a before-replacement guarantee; use the plugin manifest.

Details projects seven stages from recorded task phases and dependencies. Missing work is not success. Frozen membership supplies per-stage dataset counts, and each expanded stage returns at most 50 dataset rows with short explanations and attempt counts. Verification of the overall run remains part of the Verify stage; source-retention work stays linked as a separate run. Older unsupported records retain their recorded metadata and history without fabricated stages.

Log and recovery panels render loading, ready, empty, unavailable, and error states. Unsupported actions stop retrying; transient failures offer an explicit retry. Existing content remains visible with a stale label. Request generations reject superseded responses, terminal job logs stop polling, and visibility/dialog state gates further polling. Stage nodes are retained to preserve expansion and focus.

## Validation

Run production-path fixtures only in disposable containers. The added tests are:

- `coordinator_compatibility.php`: missing handshake fields, actual older-service endpoints, active attempt draining, idle refresh, preserved RAM receipts and locks, serialized lifecycle, restart and hook failures.
- `lifecycle_ownership.php`: surviving pipeline children, reused start-time rejection, no worker signals, and unchanged queued history.
- `installation_compatibility.sh`: manifest preflight before replacement, ignored/failed/skipped hooks, and verified activation.
- `operation_stages.php`: successful, failed, canceled, waiting, retrying, manual/recovery, unsupported history, and 10,000-dataset pagination.
- `compatibility_browser.cjs`: 390-pixel layout, failed-stage expansion, dataset pages, unsupported/transient/stale log panels, explicit retry, terminal and closed-dialog polling.
- `coordinator_refresh_zfs.sh`: two unique file-backed disposable pools, a throttled real stream, a pending refresh, queued work, unchanged stream count and receiver GUID, stable receipts, and read-only `/boot`.

The real-pool run passed with syscall tracing: no flash-writing file calls after the read-only marker across 79,988 trace lines. Both test pools were removed and a subsequent pool listing was empty. Existing reliability, stage-one, workspace endpoint, recovery/workspace/layout/attention browser suites, PHP/Bash syntax, ShellCheck error checks, and temporary package-content verification also passed. Generated release artifacts were not updated.

## Installation retry fix (2026.09.23.02)

An explicit retry reuses an existing maintenance file only when its contents are exactly `installation`. It leaves that barrier in place throughout the repeated idle and ownership checks; active workers still prevent package replacement. Other maintenance owners remain blocked. Hook failures are recorded in RAM and watchdog checks during installation preserve their original reason. The manifest and hook merge stderr into stdout because Unraid forwards stdout to its installer display. Stage-specific failure messages identify the failing preflight or activation step.

The installation fixture separately captures stdout and stderr, exercises busy retries, skipped and failed hooks, real permission/migration/cron helpers, and successful retry without manually removing maintenance.

## Explicit activation (2026.09.23.03)

Plugin-managed updates pass `ZFSAS_DEFER_ACTIVATION=1` to `upgradepkg`, then invoke `post-install.sh` explicitly after package registration. A package manager that skips `doinst.sh` therefore still receives full activation. If it invokes the hook, the hook defers activation until replacement finishes. Direct package installs continue to invoke activation through `doinst.sh`. Final readiness and coordinator handshake verification remain mandatory. Fixtures cover both skipped and executed package hooks, exactly one activation, and failures in the explicit activation path.
