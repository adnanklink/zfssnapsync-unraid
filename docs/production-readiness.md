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
| 5. Native SSH execution and recovery | Complete in fixtures; native admission enabled; 39-suite regression run passed; host acceptance remains separate |
| 6. SSH cleanup parity | Complete in fixtures; real-SSH/coordinator checks and 39-suite regression run passed; host acceptance remains separate |
| 7. Individual Auto Snapshot mutation tasks | Implemented; policy, actual-daemon, cancellation and lifecycle fixtures pass; 40-suite regression run passed |
| 8. Safe partial automatic replanning | Implemented; state and actual-daemon continuation/identity regressions pass |
| 9. Lifecycle and reboot compatibility | Complete in fixtures; actual watchdog/installer regressions pass; Unraid platform acceptance remains separate |
| 10. Operational UI feedback | Complete in fixtures; status, browser and syntax checks pass |
| 11. Fault, flash-write and scale acceptance | Deterministic fault/flash/10,000-task checks pass; real-host scale and 48-hour soak pending |
| 12. Documentation, screenshots and release preparation | Candidate docs and inspected screenshots updated; version/artifact promotion awaits acceptance |
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

## Task 5 native SSH execution and recovery

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
Scheduled SSH admission, Run all jobs now and public recovery now use the native
coordinator together with the cleanup parity below. Immutable schedule captures
include only saved connection fields. Run All keeps stable command receipts and
does not shift schedule anchors. Legacy local/SSH admission is disabled; unstarted
queue entries are retired while active workers retain their existing ownership.
Old cleanup inbox entries cannot become new native cleanup authority. The existing
network-only wrapper flag remains accepted for compatibility.

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

The combined cutover regression run passed all 39 CI suites, including real SSH
transport with deterministic ZFS commands, actual coordinator cleanup, scheduling,
retention, endpoint and browser regressions, syntax and package verification.
All three read-only-flash suites also passed, including actual source cleanup.
Reports: `/tmp/snapsync-tests-20260928T134206-331437/results.json` and
`/tmp/snapsync-tests-20260928T134631-00f1bc/results.json`. These local reports are
development evidence; they do not replace the versioned release acceptance record.

## Task 7 individual automatic mutations

The established Bash policy driver retains retention, zero-change, recursive
inventory, pressure-constraint ordering and Dry Run behavior. Live mutations are
proposed individually through its current grant. Each deletion receives independent
shared-cleanup ownership; each creation receives its own worker and result. The
driver waits for verified child completion before continuing and does not perform
ZFS mutations itself. Idle result reads do not append journal events.

Capture and execution bind dataset GUID, snapshot GUID/TXG, inventory, prefix,
configuration revision and the live policy attempt. Workers recheck retention,
leases, holds, clones, registered references, historical replication prefixes and
current pressure constraints under their own gates. Pending freeing counts toward
effective space as before; a refquota-only constraint cannot authorize deletion.
Changed inventory or policy stops remaining work. Interrupted or unverified mutation
results are not automatically replayed. Cancellation and policy-driver loss revoke
child authority and wait for verified process-group shutdown. Lifecycle draining
allows an already-owned child handoff to finish while unrelated admission stays
blocked. Journal format 7 prevents older executors from recovering this ownership.

Policy and state regressions, the existing Auto endpoint/replan fixtures, syntax,
and an actual daemon using the unmodified production policy path pass. The latter
checks deletion-before-creation ordering, distinct task identities, preserved newest
checkpoints, Dry Run, update draining, cancellation and injected policy-driver loss.
Full CI and real-host acceptance remain separate verification steps.

## Task 8 partial automatic replanning

After a configuration change stops an automatic occurrence, the coordinator can
append a continuation within that same operation. It retains prior task outcomes
and attempt diagnostics, command receipts and schedule acceptance. Superseded
failures remain historical evidence. Completed deletions are not replayed;
completed creations carry exact dataset and snapshot GUID proofs, are protected
as checkpoints, and are verified before continuing. The policy driver skips
creation for those already completed datasets and evaluates unfinished work under
the current saved policy.

Continuation requires the same enabled schedule, the latest accepted occurrence,
no competing Auto run, verified worker shutdown and no ambiguous mutation grant.
Manual approvals, explicit pauses, lifecycle barriers, changed schedules and
uncertain outcomes do not gain automatic authorization. Journal format 8 makes the
supersession boundary explicit to older readers. Completed-work captures remain
in RAM and are pruned with their task ownership.

State-machine tests verify preserved identities, references, outcomes and timing,
and reject manual, stale and ambiguous work. Actual daemon tests save settings
immediately after snapshot creation: the original operation continues and creates
only the newly selected dataset's checkpoint. A changed completed checkpoint GUID
stops continuation before another mutation. Reliability regressions pass; dedicated
host acceptance and the final combined verification remain release gates.

## Task 9 lifecycle and recovery startup

Package replacement continues to require idle, verified local and remote ownership.
An unverified receiver blocks preparation even when its local SSH group has exited.
When the daemon itself has died, watchdog/activation may restart the coordinator
with intact recorded ownership so its executor can revoke grants and verify shutdown.
That exception neither replaces the package nor adopts unknown legacy processes;
PID/start identity mismatches and unrecorded workers remain blockers. The lifecycle
inspector itself never signals workers or rewrites journal authority.

An actual watchdog test kills the coordinator while an individual Auto deletion
is blocked. Restart revokes the recorded driver/child, preserves operation history,
and requires fresh review without mutation replay. Lifecycle ownership tests cover
surviving descendants, reused identities, unverified receiver shutdown and unchanged
queued receipts. Coordinator and installer compatibility suites pass. Existing
Auto tests cover complete RAM loss and preserved pause/Resume decisions. Real
Unraid 6.12.0 and stable 7.x installation/reboot verification remains task 13.

## Task 10 operational feedback

Auto operation Details now shows recorded policy evaluation, cleanup and snapshot
creation stages, grouped by dataset with bounded pages. Completed work remains
visible after safe continuation; superseded failures remain in history without
masking current status. Auto failures direct users to snapshot settings and recorded
results instead of transfer recovery. Unverified receiver shutdown explicitly says
that dataset ownership remains reserved while shutdown confirmation is pending.

Status regressions cover supersession, absent mutation evidence and receiver shutdown.
The browser regression opens Auto Details and expands its checklist using the keyboard
at 1440px and 390px, then verifies focus restoration. All ten existing browser suites,
reliability units and syntax checks passed. No navigation or execution behavior changed.

## Task 11 reproducible fault and scale evidence

The runner freezes the checkout once for all suites and records its acceptance
input digest. It rejects changes during capture, preventing a long regression run
from combining source versions. Every suite still receives a separate container.

The actual coordinator journal accepts 10,000 tasks, preserves duplicate receipts,
records completion, reloads persisted state, returns a bounded final checklist page,
and cancels pending work without changing the completed result. A measured run took
0.402 seconds with 100,532,224 bytes of peak PHP allocation; the regression enforces
30 seconds and 512 MiB. This is a journal measurement, not execution throughput.
All three read-only flash/mount suites pass after the Auto and lifecycle changes.
Existing fault suites cover policy-driver/daemon loss, receiver disconnect, stale
identity, canceled shared owners and denied authorization. Real-pool pressure,
platform restart and the full 48-hour soak remain required dedicated-host evidence.

## Task 12 documentation and candidate visuals

README and unreleased changelog now describe local/SSH ownership, source retention,
pressure cleanup, temporary receiver helpers, partial Auto continuation and current
acceptance limits. Published 2026.09.26.02 behavior is explicitly separated from this
candidate. The old status audit remains a labeled historical release baseline.
The in-app development footer now says production acceptance is pending.

Seven README screenshots were regenerated from production views with demo data.
History/Advanced fields, snapshot actions and pagination were inspected, alongside
light/dark desktop/narrow empty, editing, selected, running-draft and error states.
All three capture suites passed. Screenshot hashes and capture input digest are in
`docs/screenshots/capture.json`; these are fixture images, not host acceptance.
Versioned release artifacts remain unchanged until an accepted candidate is ready.

The remaining dedicated-host execution and evidence checklist is in
[host acceptance](host-acceptance.md). Host connection details and disposable roots
are still required; no platform or soak acceptance is claimed.

## Combined verification on 2026-09-28

All 44 CI suites passed against the frozen clean checkout `620d3d0`, input digest
`c39c7bd946522d6110eb3e61ab9e1e064ebf8bb5b3eb04063f8e42658a90663b`.
The local report is `/tmp/snapsync-tests-20260928T165154-9fc53f/results.json`.
This includes actual-daemon continuation/watchdog tests, real-SSH fixture ownership
and cleanup, 11 browser suites, scheduling/retention regressions, syntax and package
checks. The three flash suites also passed at
`/tmp/snapsync-tests-20260928T165051-dccf59/results.json`.

Later changes corrected dedicated-host fixture paths, Auto message CSS visibility
and development wording. Focused Auto visibility/keyboard checks passed at
`/tmp/snapsync-tests-20260928T165852-5b470d/results.json`; workspace capture and
operation-layout suites passed in `/tmp/snapsync-readiness-final-layout.log`.
Final syntax passed at `/tmp/snapsync-tests-20260928T170049-965f6a/results.json`.
Separate package-content, acceptance-gate and reproducibility checks passed at
`/tmp/snapsync-tests-20260928T165751-b48edd/results.json` before the final text/CSS
changes. These scoped results do not claim a single final-candidate acceptance run.

No release acceptance certificate, new versioned package, main merge or publication
was produced. Dedicated Unraid minimum/current hosts, the separate Linux receiver,
real-pool scale/fault evidence, cross-host WebGUI acceptance, 48-hour soak and final
accepted-byte promotion remain outstanding. Required host connection details and
disposable roots have been requested. The source branch and documentation are cleanly
committed; final acceptance must use the eventual versioned candidate's exact inputs.

## Dataset retirement candidate

The Snapshots dataset action now separates stopping automation from explicitly
authorizing snapshot deletion. Exact-source jobs and the selected Auto membership
are removed under revision/configuration locks. Mixed or recursive scope is blocked.
A captured review covers local or SSH destinations and the source, with destination
deletions first. Holds, clones, unresolved receives and other operations remain
protected. A separate acknowledgment can abandon only fully stopped recovery owned
solely by retired jobs. Already-removed jobs require explicit destination selection.

Backend, guided dialog, regression tests and review-renewal fixes are separate
commits (`435544a`, `20cf27a`, `2028e26`, `4464eea`). Review renewal retains destinations
even after job removal. Ordinary snapshot deletion protection remains unchanged.

Focused local/real-SSH daemon tests passed at
`/tmp/snapsync-tests-20260928T191022-1a806d/results.json`; local interruption,
identity replacement, expiry and captured-selection faults passed at
`/tmp/snapsync-tests-20260928T191105-03e9f1/results.json`. Updated reliability
coverage passed at `/tmp/snapsync-tests-20260928T191905-5468fd/results.json`,
including already-removed jobs and mixed Auto scope. Desktop/narrow browser
reinspection passed at `/tmp/snapsync-tests-20260928T192130-33dc25/results.json`.
Syntax passed at `/tmp/snapsync-tests-20260928T191941-f67dab/results.json`.
These are scoped, frozen-input results; they are not a production certificate.

Four light/dark desktop/narrow retirement screenshots were captured from production
views with controlled demo responses and visually inspected. Their hashes and
implementation revision are in `screenshots/retirement-capture.json`.
The 10,000-count browser fixture tests global captured selection; these images do
not establish live ZFS scale performance.

The existing stage-1 README assertions still require wording superseded by earlier
SSH documentation changes. Automatic approval review rejected changing those
assertions, citing documentation-gate integrity. They remain untouched and their
failure is recorded separately from retirement verification. Dedicated-host gates
in `host-acceptance.md` now include this workflow; publication remains gated.

### Combined retirement regression results

The complete 47-suite CI run finished with **46 passing and one failing**. Report:
`/tmp/snapsync-tests-20260928T191655-fb16c6/results.json`, frozen input digest
`ba2b4617ba7a161bff06c592d459edc80cd73def5a236bf6274d6479ace4fade`.
The only failure is the unchanged stage-one README wording assertion described
above. All endpoint suites, all 12 browser suites, reliability regressions, syntax,
package contents, release-gate checks and reproducible build checks passed.

That full run froze before the final reinspection UI and additional authority
assertions. Their later focused passing reports are listed above. Final committed
package checks also passed at
`/tmp/snapsync-tests-20260928T192342-b263b1/results.json`. These combined results
cover the candidate changes without claiming one clean final-candidate CI run.
No deployment, main merge, versioned release artifact or publication was performed.
