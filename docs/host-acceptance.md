# Candidate host acceptance

This is the remaining execution checklist, not a passing record. Use the exact
candidate package on dedicated test machines. Do not use production datasets.
Main may publish explicitly labeled experimental builds before this finishes. These gates remain required before claiming production readiness.

## Required environment record

Record SSH aliases/users (never credentials), Unraid 6.12.0 and the tested stable
7.x version, Linux receiver distribution, OpenZFS userland/kernel versions,
verified receiver host-key fingerprint, pool GUIDs and disposable dataset roots.
Confirm the client used for WebGUI testing is a different host. Record the package
SHA-256 and `verify-acceptance.py --digest` output before tests start.

The Linux receiver must have OpenZFS and key-based SSH access; do not install the
plugin on it. Use a compatible test image with ZFS userland for the `zfs` group.
The standard PHP/browser image intentionally does not contain ZFS userland.

## Execution and evidence

| Gate | Required evidence |
| --- | --- |
| Installation/lifecycle | Install through the plugin manifest on both Unraid versions; verify activation/handshake and schedules. Try an update with active local and SSH work; replacement must wait for verified shutdown. Kill the daemon with workers active, restart via watchdog, then reboot and verify fresh review and persistent pauses. |
| Local and SSH replication | Transfer and verify recursive members, incremental bases and received GUIDs. Interrupt a receive, review original work, retry only reviewed members and verify completed members are not retransmitted. Change receiver identity/host key and confirm fail-closed behavior. |
| Cleanup | Exercise source keep-3, paused receiver bases, holds, clones, active references, unavailable receivers and resume tokens. Exercise destination retention and opt-in pressure cleanup. Cancel selected/unselected shared owners and confirm one physical deletion has intact authority. Record exclusions and final GUID inventories. |
| Dataset retirement | Retire one dataset with local and SSH destinations; verify exact saved settings, destination-first deletion, holds/clones/other-job exclusions, explicit recovery abandonment, cancellation and changed identities. Disconnect the receiver and verify source history remains. Reboot and require a fresh review. |
| Replanning | Save settings after a verified Auto mutation, confirm completed identities remain and only eligible remaining work continues. Change a completed identity, schedule, manual authorization or uncertain outcome and confirm no automatic replay. |
| Faults/flash/scale | Exercise disconnects, daemon/worker loss, quota/freeing waits and stale reviews. Trace flash-write attempts during runtime work. Run 10,000-snapshot selection and real-pool work; record elapsed time, memory, polling latency and final inventories. |
| WebGUI | From another host, test authenticated routes, saves, status, menus, keyboard focus and narrow/light/dark layouts. Confirm stale/error responses preserve drafts and do not issue duplicate mutations. |
| Soak | Run configured Auto, local and SSH schedules for at least 172800 elapsed seconds. Include successful cleanup, idle periods and planned faults/recovery. Record start/end times, observed runs, failures, process/RAM/journal growth, flash tracing and final integrity checks. Waiting 48 hours without exercising and verifying work does not satisfy this gate. |

Run `python3 tests/run.py ci` and `python3 tests/run.py flash` for the final inputs.
On the dedicated ZFS host only, set `ZFSAS_DISPOSABLE_POOL_TEST=1`, select the
compatible image via `SNAPSYNC_TEST_IMAGE`, and set `ZFSAS_POOL_FIXTURE_ROOT` to
an existing absolute scratch directory before `python3 tests/run.py zfs`. Each
suite mounts its unique scratch directory at the same host/container path.
Retain failed fixture paths and pool GUIDs; inspect ownership before cleanup.
Never remove an unfamiliar pool merely because its name resembles a test pool.

Record failures and fixes rather than resetting evidence to hide them. A source,
test, packaging or version change invalidates the prior acceptance input digest;
repeat affected checks and establish acceptance for the final candidate.

## Promotion

Keep test evidence in repository-relative files with SHA-256 hashes. Populate the
versioned acceptance record described in [production readiness](production-readiness.md)
only after every required gate passes. Verify the candidate package content and
acceptance record, require the repository's `tests` and `package` checks on main,
and promote the exact accepted package bytes. Keep generated release artifacts
in a separate commit from source/documentation. Do not rebuild after acceptance
and substitute different bytes under the same evidence.
