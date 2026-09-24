<b>ZFS SnapSync</b><br>
WebUI-based ZFS snapshot scheduling for Unraid with plain-English scheduling,
dataset selection, retention cleanup, low-space protection, dry-run mode, and
built-in run logs.


## Reliable replication and Snapshot Manager batches

Snapshot Manager supports one dataset at a time with server-side filters, stable sorting, 50/100/250-row pages, cross-page selection and exact-identity batch review. “Select all matching” captures existing names and GUIDs. New snapshots never silently join a selection. Bulk Delete, Add plugin hold and Release plugin hold run in bounded chunks; Send and Rollback remain single-snapshot operations. External hold tags cannot be released by the plugin.

Cleanup previews default to Auto Snapshot-managed snapshots. Zero-change and configured retention policies retain newest snapshots and required anchors, and exclude holds, clones, replication references, pending deletes and incomplete metadata. Approval expires after five minutes; identities and protections are checked again during execution. Written bytes do not represent guaranteed reclaimable space.

Cancel persists the decision for the whole replication run before terminating processes and pauses its schedule until Resume. Receives no longer force rollback or destroy destinations to reseed. Prefix overlap is rejected on both settings pages and on the server. Restore tuning defaults requires Save and preserves datasets, prefixes, connections and schedule pauses. Concurrent/stale settings saves are rejected. The UI uses bounded polling and asynchronous dataset discovery.

Upgrade/removal preserves queue records and lock files and verifies process shutdown. Failed upgrade shutdown leaves a maintenance marker under the plugin configuration directory; resolve the reported process issue and rerun installation.

### Backup protection and writable restores

Backup sends set the destination dataset to `readonly=on`; native local sends also protect existing receivers before transfer and verify the property at completion, including already-received retries. Scheduled recovery retries remain backup operations. Network backup receive commands request the same read-only property. This applies when work runs, not as an installation-time sweep of existing datasets. It does not undo existing receiver divergence.

In Snapshot Manager, **Send** creates a protected backup. **Restore** sends the selected snapshot to a **new writable dataset** below an existing parent. Choose the snapshot on the surviving backup; the original source need not exist. Restore does not replace an existing dataset, change the backup's properties, or recursively restore child datasets. Retry preserves the original backup/restore purpose. Receives remain unmounted (`-u`); mount a restored dataset at the intended path when ready to activate it. This is a single-snapshot restore action, not a complete disaster-recovery wizard.

Automatic snapshot Job logs include stdout and stderr captured per attempt in RAM (up to 64 KiB retained; Details shows the latest 12 KiB per attempt). The shared Auto log remains the latest category summary, not the selected job’s history. Captured output follows attempt-history retention and disappears on reboot. Jobs run before this capture was introduced cannot recover their discarded worker output.
