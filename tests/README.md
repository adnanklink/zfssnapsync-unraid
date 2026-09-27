# Verification

Build the pinned PHP/Node/Chromium/Playwright runtime from the repository root:

```sh
docker build -t snapsync-test-runtime:production-readiness -f tests/runtime/Dockerfile .
python3 tests/run.py ci
```

The runner mounts the checkout read-only and copies the plugin into a fresh
container for every suite. Production-path fixture writes never target the host.
It disables networking, records image ID, source revision, dirty state, exit
codes and durations, and saves logs plus `results.json` under a unique `/tmp`
directory. Nonzero exits, including skipped tests (77), are failures.

Groups: `unit`, `endpoints`, `browser`, `syntax`, `package`, `ci` (all five), `flash`, `zfs`,
and `all`. `--filter substring` selects a focused suite; this is not full acceptance.
`--output /new/path` selects an unused report directory. `SNAPSYNC_TEST_IMAGE`
selects an explicitly prepared alternative image and its digest is recorded.

`flash` needs container mount capability, but does not expose host ZFS devices.
`zfs` and `all` additionally require an image with compatible ZFS userland,
`/dev/zfs`, and `ZFSAS_DISPOSABLE_POOL_TEST=1`. Run them only on the dedicated
acceptance host: containers share its ZFS kernel. The fixtures create uniquely
named file-backed pools and must leave none behind. A missing prerequisite is a
failure, never a successful skip. Host/SSH and soak acceptance remain separate
release gates until their full harnesses are implemented.

Runtime updates are explicit: update the exact image/browser versions and npm
lock, rebuild, and rerun the suites. An unavailable pinned dependency must fail
the build rather than silently select a different version. The archived test
image digest identifies the actual environment used for a release.
