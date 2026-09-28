# Verification

Build the pinned PHP/Node/Chromium/Playwright runtime from the repository root:

```sh
docker build -t snapsync-test-runtime:production-readiness -f tests/runtime/Dockerfile .
python3 tests/run.py ci
```

The runner freezes one complete checkout, verifies its acceptance input digest,
mounts that snapshot read-only and copies the plugin into a fresh
container for every suite. Production-path fixture writes never target the host.
It disables networking, records image ID, source revision, dirty state, acceptance input digest, exit
codes and durations, and saves logs plus `results.json` under a unique `/tmp`
directory. Nonzero exits, including skipped tests (77), are failures.

Groups: `unit`, `endpoints`, `browser`, `syntax`, `package`, `ci` (all five), `flash`, `zfs`,
and `all`. `--filter substring` selects a focused suite; this is not full acceptance.
`--output /new/path` selects an unused report directory. `SNAPSYNC_TEST_IMAGE`
selects an explicitly prepared alternative image and its digest is recorded.

`flash` needs container mount capability, but does not expose host ZFS devices.
Read-only flash suites mount `tests/runtime/boot` at `/boot:ro`. This fixture
configures one fake Auto Snapshot dataset with scheduling disabled; the tests
cannot initialize or change flash configuration.
`zfs` and `all` additionally require an image with compatible ZFS userland,
`/dev/zfs`, `ZFSAS_DISPOSABLE_POOL_TEST=1`, and an existing absolute
`ZFSAS_POOL_FIXTURE_ROOT` dedicated to disposable file vdevs. The runner mounts a
unique subdirectory at the same host/container path for each suite and records
it in the report. Failed fixtures remain available for GUID-checked cleanup. Run them only on the dedicated
acceptance host: containers share its ZFS kernel. The fixtures create uniquely
named file-backed pools and must leave none behind. A missing prerequisite is a
failure, never a successful skip. Host/SSH and soak acceptance remain separate
release gates until their full harnesses are implemented.

Runtime updates are explicit: update the exact image/browser versions and npm
lock, rebuild, and rerun the suites. An unavailable pinned dependency must fail
the build rather than silently select a different version. The archived test
image digest identifies the actual environment used for a release.

The unit group includes a 10,000-task journal regression: duplicate admission,
completion, journal reload, final-page projection and cancellation preserving
completed work. It enforces a 30-second/512-MiB PHP budget and reports actual time
and peak allocation. Browser selection and replication inventory also exercise
10,000 entries. These deterministic checks do not replace real-pool scale or the
48-hour host soak.
