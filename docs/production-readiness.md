# Production readiness implementation

Work begins from `23ce3ae`, release `2026.09.26.02`, on `feat/production-readiness`.
Each numbered task receives a separate commit. Generated artifacts are last and
remain separate from source. This record is not a release acceptance certificate.

| Task | Status |
| --- | --- |
| 1. Reproducible verification and repaired fixtures | Complete |
| 2. Release gates and content verification | In progress |
| 3. Endpoint-aware coordination | Pending |
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
