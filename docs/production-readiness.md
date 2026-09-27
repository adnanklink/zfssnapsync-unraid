# Production readiness implementation

Work begins from `23ce3ae`, release `2026.09.26.02`, on `feat/production-readiness`.
Each numbered task receives a separate commit. Generated artifacts are last and
remain separate from source. This record is not a release acceptance certificate.

| Task | Status |
| --- | --- |
| 1. Reproducible verification and repaired fixtures | Complete |
| 2. Release gates and content verification | Complete (repository checks; promotion still gated) |
| 3. Endpoint-aware coordination | Complete (SSH execution remains gated on task 5) |
| 4. Independent shared cleanup owners | Pending |
| 5. Native SSH execution and recovery | Pending |
| 6. SSH cleanup parity | Pending |
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
additive endpointIdentity capability; protocol 1 and journal format 3 remain
compatible because captured parameters and reference records already support
these fields. Existing build-identity checks still reject mixed running builds.
