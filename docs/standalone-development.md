# Standalone development status

Current status: **2026.09.26.02**, audited **2026-09-26**. See the [claim-by-claim documentation audit](status-audit.md) for code and fixture evidence.

## Implemented and published

ZFS SnapSync is published as the standalone `zfs.snapsync` plugin through the `main` manifest. It has independent configuration, runtime, service, cron, interface and update identities. It does not automatically import the original ZFS Auto Snapshot plugin's configuration or execution authority. The two plugins must not independently manage the same work; naming isolation is not shared resource coordination.

Native local replication supports manual sends, automatic schedules and Run all jobs now. Its phases capture membership, inspect destinations, register references, perform authorized cleanup, check measured space, transfer and verify every required child. Local reviewed recovery reuses original snapshots and validates receive/resume state. Per-job older-anchor cleanup is opt-in; source checkpoint retention follows verified local runs and requires review where applicable.

Deletion batches use coordinator-owned item execution. Non-delete batches use bounded workers with coordinator item authorization/results. Installation checks ownership before package replacement, blocks busy updates, and verifies explicit activation. The production workspace includes task navigation, guided setup, direct scoped job saves and reviewed snapshot actions.

## Remaining work

1. Integrate SSH/network work into native coordinator phases. Existing SSH execution remains available; spiped is hidden from the WebGUI.
2. Implement independently validated shared cleanup owners. Current shared reference protection does not grant multiple cleanup authorizations.
3. Extend safe replanning beyond queued, never-attempted automatic work. Do not infer that partially executed mutations are safe to replay after a revision change.
4. Represent remaining internal Auto Snapshot mutations as individual coordinator tasks. Existing run ownership and dataset/migration gates remain in force.
5. Complete broad release acceptance: all-path flash-write tracing, reboot/fault, scale/idle and actual Unraid-host checks. Later implementation records already contain scoped real-ZFS and tracing evidence; those results are not a claim of full coverage.

Runtime histories and reviews remain in RAM. After reboot, manual work requires explicit recovery/review; exactly-once execution and reconstructed manual authority are not promised. Persistent pauses and migration recovery checkpoints remain separate.

Use the [README](../README.md) for installation and operation, the [implementation record](job-coordination-progress.md) for historical milestones, and the [reliability audit](reliability-audit.md) for recorded verification.
