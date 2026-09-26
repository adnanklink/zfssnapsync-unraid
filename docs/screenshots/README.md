# Workspace screenshots

These images show the **2026.09.26.02 production PHP views and JavaScript**, rendered in Chromium with controlled demo data. They do not show a live server or the surrounding Unraid shell. The separate `docs/preview` prototype is not the source of these screenshots.

| Image | View | Capture output |
| --- | --- | --- |
| `overview.png` | Task summaries, schedules and recent work; light theme | `section-overview-light.png` |
| `automation.png` | Configured automatic-snapshot summaries | `automation-configured.png` |
| `automation-history.png` | History section with fields directly visible | `automation-history.png` |
| `automation-advanced.png` | Advanced section with fields directly visible | `automation-advanced.png` |
| `snapshots.png` | Snapshot browsing, dataset actions and pagination | `snapshots-browse-light-1440.png` |
| `snapshots-selected.png` | Contextual selection actions | `snapshots-selected-light-1440.png` |
| `replication-dark.png` | First step of backup job creation; dark theme | `replication-editing-dark-1440.png` |

Overview and backup setup use a 1440 × 1000 viewport. Automatic-snapshot images use a 1366 × 768 viewport with full-page capture. Snapshot browsing uses a 1440 × 900 viewport; full-page capture includes the list and pagination. All fixture datasets, counts, dates, runtime outcomes and errors are examples. `ZFSAS_DOC_CAPTURE=1` uses readable demo paths and timestamps; normal test runs retain the long-path stress fixture. The automation images show the successful-save fixture after the suite has separately exercised scheduler-application failure.

## Refresh

Run these suites in the disposable test runtime from the repository root. They render actual production pages and mock endpoint responses; do not run production-path endpoint fixtures on an installed Unraid host.

```sh
mkdir -p /tmp/zfsas-ui-screenshots
docker run --rm -e ZFSAS_DOC_CAPTURE=1 -v "$PWD:/work:ro" \
  -v "$(command -v node):/usr/local/bin/node:ro" \
  -v /tmp/zfsas-ui-screenshots:/tmp/zfsas-ui-screenshots \
  -w /work zfsas-test-runtime bash -c \
  'node tests/reliability/workspace_browser.cjs && node tests/reliability/guided_workflow_browser.cjs && node tests/reliability/browser.cjs'
```

Use a host Node version compatible with the installed Playwright runtime. Copy the output files listed above from `/tmp/zfsas-ui-screenshots`, inspect each image, and update the README captions if the flow changes. The suites also capture dark and narrow layouts and verify branding, selection, saves and keyboard behavior.
