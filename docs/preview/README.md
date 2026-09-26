# SnapSync workflow preview

This is the working design-review checkpoint before production integration. It uses simulated data and in-memory settings. Reloading or changing the scenario resets the example state. The content security policy blocks network connections and form submissions.

## Open the preview

From the repository root, run:

```sh
python3 -m http.server 8765 --bind 127.0.0.1
```

Open http://localhost:8765/docs/preview/ in your browser. You can also open `index.html` directly.

## Suggested review

- Choose **First visit** and set up automatic snapshots through the three focused steps.
- Create a backup job, then edit an individual section. Choose SSH to try connection setup and return to your preserved job draft.
- Browse snapshots, select a page or all matching results, and inspect deletion and restore reviews.
- Try **Needs attention**, **Status unavailable**, **Save fails once**, and **Saved, scheduler fails** scenarios.
- Open Activity details and compare steps, job logs, and shared logs.
- Try Settings, migration, both themes, and a narrow browser window.

## Fixture boundaries

Inventories, dates, logs, reviews, and outcomes are examples, not evidence of actual protection or backend capabilities. Saves and execution are simulated; there are no plugin endpoint calls or disk operations.

The preview demonstrates a subset of advanced filters and settings. Production integration must retain the full advanced criteria, dataset overrides, sorting, exact snapshot identities, pagination semantics, authorization requirements, and legacy routes. Review expiry and save conflicts here are simulations, not backend validation.

These files live outside `source/` and the release package. The design was approved and integrated into the production workspace. These fixtures remain a reference, not a live backend.

## Verification

`tests/reliability/preview_browser.cjs` exercises guided setup, section editing, preserved drafts, connection setup return, save errors, scheduler application failure, stale asynchronous results, captured selection, reviews, log scopes, migration, and responsive layouts. It checks console errors and rejects unexpected application requests.

Run with the existing test runtime (mounting the host Node version):

```sh
docker run --rm -v "$PWD:/work:ro" \
  -v "$(command -v node):/usr/local/bin/node:ro" \
  -v /tmp/zfsas-design-preview:/tmp/zfsas-design-preview \
  -w /work zfsas-test-runtime node tests/reliability/preview_browser.cjs
```

The suite captures desktop and mobile screenshots in `/tmp/zfsas-design-preview`. Selected review images are in [screenshots](screenshots/): [overview](screenshots/overview-light.png), [schedule setup](screenshots/setup-schedule.png), [snapshot selection](screenshots/snapshots-selected.png), [restore dialog](screenshots/restore-dialog.png), and [mobile setup](screenshots/job-setup-dark-mobile.png).
