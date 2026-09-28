# Production readiness implementation

Work begins from `23ce3ae`, release `2026.09.26.02`, on `feat/production-readiness`.
Each numbered task receives a separate commit. Generated artifacts are last and
remain separate from source. This record is not a release acceptance certificate.

| Task | Status |
| --- | --- |
| 1. Reproducible verification and repaired fixtures | Complete |
| 2. Release gates and content verification | Complete (repository checks; promotion still gated) |
| 3. Endpoint-aware coordination | Complete (SSH execution remains gated on task 5) |
| 4. Independent shared cleanup owners | Complete (33-suite CI passed; host acceptance remains separate) |
| 5. Native SSH execution and recovery | In progress; owned phase execution and shutdown verified in fixtures; scheduled cutover awaits cleanup parity |
| 6. SSH cleanup parity | Implemented; focused real-SSH/coordinator fixtures pass; full regression run in progress |
| 7. Individual Auto Snapshot mutation tasks | Pending |
| 8. Safe partial automatic replanning | Pending |
| 9. Lifecycle and reboot compatibility | Pending |
| 10. Operational UI feedback | Pending |
| 11. Fault, flash-write and scale acceptance | Pending |
| 12. Documentation, screenshots and release preparation | Pending |
| 13. Dedicated host acceptance | Pending host connection details |
| 14. Verified artifact publication | Blocked until acceptance passes |

## Agreed boundaries

Support local and SSH replication with full cleanup parity, ordinary Linux
OpenZFS receivers, and Unraid 6.12+. spiped stays unsupported and hidden.
Runtime history and manual authority remain RAM-only. Reboot requires fresh
review; exactly-once execution is not promised. Preserve configuration formats,
routes, scoped saves, schedule anchors and explicit destructive authorization.

## Release gates

All required suites must pass, including real local/SSH ZFS operations, verified
cancellation and recovery, independent cleanup authorization, fault injection,
read-only flash tracing, 10,000-snapshot/task scale and a 48-hour soak. Test the
packaged candidate on Unraid 6.12.0 and the recorded stable 7.x release, plus a
separate Linux receiver. Inspect keyboard flows, light/dark and narrow/desktop
screenshots, and WebGUI access from another host. Promote the exact accepted
package bytes. Missing or skipped host evidence blocks publication.

## Task 1 verification

The pinned runtime builds with PHP 8.3.33, Node 22.22.2, Chromium
154.0.8037.57 and Playwright Core 1.63.0. The 29-suite CI inventory was exercised:
27 passed on the full run; the two exposed fixture races passed focused reruns
following repair. No production behavior was changed to make fixtures pass.
The repaired 601-item batch fixture uses the real coordinator and real retry
delays; the configuration browser covers the current guided forms. Flash,
real-ZFS and dedicated-host acceptance are not claimed by this CI baseline.

## Task 2 verification and promotion contract

Candidate generation is manual and read-only to GitHub; it never commits or
publishes a package. CI runs the isolated suite inventory and package regressions.
The `package` check on main-targeting PRs and main requires
`scripts/verify-promotion.sh`: matching source bytes, canonical modes, links,
root ownership, production URLs, checksums and passing acceptance evidence.
Require the `tests` and `package` checks in branch protection before final merge;
remote protection settings have not yet been changed or certified.

Package and manifest reproducibility, altered payload rejection, and missing,
stale, skipped, wrong-platform and insufficient-soak acceptance records pass
regression tests. Archive permissions are canonical Git-style 0755/0644 (0777
for symlinks), independent of developer checkout umask. The accepted candidate
must be retained and promoted unchanged, not rebuilt after the evidence commit.

The eventual `docs/releases/<VERSION>.json` record must contain `version`,
`status: passed`, `inputDigest` (from `verify-acceptance.py --digest`), and
`packageSha256`. Its `checks` map requires ci, local-zfs, ssh-zfs, cleanup,
replanning, lifecycle, flash, scale, webgui and soak. Each contains `status`, a
repository-relative `evidence` path and its SHA-256; soak also contains elapsed
`seconds` of at least 172800. `platforms` records passing versioned
unraidMinimum (6.12.0), unraidCurrent (7.x), and linuxReceiver acceptance.
No such passing record is created until those tests actually finish.

## Task 3 verification

Inspection now separates source and receiver reads, and captured plans bind the
receiver identity. References and resource locks distinguish verified storage
endpoints; legacy unknown identities remain conservative. Local lock names stay
compatible with existing workers. Multiple resource gates acquire in stable
order and release everything on contention. SSH aliases use verified host-key
and pool identity, with locally imported pools sharing local gates.

The reliability suite, deletion-adapter endpoint suite, and syntax checks pass.
Regressions cover identical snapshot names/GUIDs on distinct endpoints, alias
identity, local pool collapse, lock rollback, receiver-only reads, resume tokens,
and changed-identity rejection. This introduces the coordination primitives,
not native SSH execution or real-host acceptance. The handshake advertises an
additive endpointIdentity capability. This step retained protocol 1 and journal
format 3; task 4 below adds a versioned boundary for shared execution authority.
Existing build-identity checks still reject mixed running builds.

## Task 4 independent cleanup ownership

Native coordinator deletion tasks and approved manual deletion items now retain
separate immutable owner records while one physical worker deletes an exact
endpoint/dataset/snapshot/GUID identity. Legacy inbox records and protected
replication references cannot supply shared deletion authority. Joining an active
operation does not replace its selected worker approval.

Each attempt selects one intact owner and rechecks that owner's configuration,
review, metadata, holds/clones, references and conditional cleanup policy before
mutation. Canceling an unselected owner detaches only its request. Canceling the
selected owner stops and verifies its worker process group before a surviving
owner can launch a fresh attempt. Last-owner cancellation stops physical work;
explicit cancellation of the shared operation also resolves its dependent
requests instead of leaving them waiting forever.

A policy rejection is reported only to that owner. A proven deletion is projected
to the remaining unchanged requests with the physical task and authorizing owner
recorded in each result; that observed result does not renew any approval.
Canceled or changed owners are never resurrected. Retry/space-progress state is
reset on owner handoff. Pruning retains the owner/review records while the
physical task needs them. Manual pending-action exclusions are limited to the
same captured snapshot and its registered owners; other exclusions remain.

Before the first shared delegation, the coordinator atomically publishes RAM
journal format 4. New code reads formats 1–4 with existing legacy review rules;
the previous reader rejects format 4, as confirmed by a direct compatibility
probe. The on-disk configuration format is unchanged. Socket protocol 1 adds the
independentCleanupOwners capability and build fingerprint checks remain in force.
No execution authority is reconstructed after reboot.

Focused state and actual-worker tests pass for duplicate admission, distinct
endpoint/GUID identities, late owners, selected/unselected/all-owner cancellation,
verified process shutdown, stale-policy isolation, overlapping reviewed manual
batches, immutable review bindings, result fanout, restart, pruning and journal
downgrade rejection. The full 601-item batch regression passes with real retry
delays; cancellation, recovery, approval, reliability and syntax suites pass.
The final full CI inventory passed all 33 suites against the completed implementation.
Dedicated-host acceptance, all-path flash tracing and the soak remain release
gates; these focused results are not a production acceptance certificate.

## Task 5 work in progress

The read-only SSH receiver adapter uses saved connection settings, strict existing
host trust, noninteractive authentication and fresh nonmultiplexed connections.
It captures the authenticated server key and receiver pool GUID together, checks
that identity on subsequent reads, and preserves endpoint identity across aliases.
Only bounded ZFS/ZPOOL metadata reads are allowed; no receiver plugin is installed.
A real isolated OpenSSH fixture verifies unknown/changed host-key rejection, pool
identity changes, connection binding, local-pool identity, shell argument isolation
and refusal of mutation commands. Oversized output, remote command failures and
stalled connections are rejected within bounded reads and timeouts; private SSH
diagnostic files are removed after success and failure. The SSH fixture and
repository syntax suite pass.

The native phase adapter now executes full, incremental and explicitly reviewed
resume streams over SSH, verifies exact receiver checkpoints, applies read-only
backup or writable restore policy, and preserves unmounted receive semantics.
An ephemeral Bash helper owns each receiver attempt in an independent process
group, holds compatible ancestor dataset gates, and checks pool, dataset and boot
identity before mutation. Receiver authority records require tmpfs `/dev/shm`;
the receiver needs no permanent plugin installation.

Cancellation fences both receiver sub-operations before checking their process
groups. The fence rejects delayed launches and duplicate tokens. Independent,
bounded SSH probes verify shutdown after local client loss; unreachable or
ambiguous receivers retain coordinator ownership and block recovery grants.
Host-key pinning rejects a changed key before executing a captured mutation even
if that new key has since been added to known_hosts. Journal format 5 is published
before granting remote mutation authority, so earlier executors cannot recover
that work using local-only shutdown rules. Local-only journals keep their existing
format until this boundary is needed.

Real SSH fixtures with deterministic ZFS commands cover full/incremental/resume
phases, protection and mount policy, disconnects, unreachable receivers, delayed
launches, PID identity changes, orphaned descendants, duplicate execution,
compatible locks and coordinator restart barriers. Reliability regressions pass.
The completed owned-phase checkpoint passed all 36 isolated CI suites.
These are transport and execution fixtures, not real OpenZFS or host acceptance.
Scheduled SSH admission and its public recovery flow remain on their existing
path until remote cleanup and source-retention parity can be enabled together.

## Task 6 SSH cleanup parity

Destination retention and pressure cleanup use the captured receiver identity,
configuration revision, independent owner and exact snapshot GUID/TXG. A receiver
helper acquires compatible dataset gates and checks its inventory before asking
for a fresh coordinator authorization. The source rechecks policy and available
space after that handshake; a met target or pending freeing withholds deletion.
The receiver checks inventory, holds, clones and resume state again before the
single destroy. Cancellation independently fences destroy as well as receive and
read-only changes; local SSH exit alone never releases ownership.

Source retention accounts for every configured consumer, including paused SSH
jobs and equal dataset paths on distinct endpoints. Unavailable receiver evidence
defers cleanup. Ephemeral shared receiver locks protect each required incremental
checkpoint during source deletion, survive client loss, and require independently
verified shutdown. Source retention approvals bind saved SSH connection settings;
changing those settings invalidates the previous cleanup binding. New-job defaults
and the existing review requirement for applicable cleanup changes are preserved.

RAM journal format 6 is published before remote cleanup authority: older readers
cannot recover deletion or checkpoint guards using receive-only shutdown rules.
Orphaned remote deletion captures are pruned with their journal owners. No saved
configuration format or permanent receiver installation is introduced.

Focused tests pass for real SSH destructive handshakes, revoked approvals, changed
GUID/TXG/inventory/holds/clones, delayed launches, pressure stop decisions, concurrent
checkpoint guards and orphaned clients. Actual coordinator fixtures verify one
physical remote deletion for two independent requests, result fanout, remote source
cleanup and accounting, and verified receiver shutdown. The reliability suite and
local/SSH Run All endpoint pass. These use deterministic ZFS fixtures over real SSH;
real OpenZFS, separate hosts, flash tracing and soak acceptance remain release gates.
