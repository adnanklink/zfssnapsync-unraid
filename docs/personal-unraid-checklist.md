# Personal Unraid candidate checklist

Use this as a result sheet, not evidence that these tests already passed. Record
PASS / FAIL / NOT TESTED for each item, with the operation ID and relevant logs.
This supplements [host acceptance](host-acceptance.md); one personal server cannot
establish compatibility with every supported Unraid version.

## Install the experimental release

In Unraid **Plugins → Install Plugin**, use:

```text
https://raw.githubusercontent.com/adnanklink/zfssnapsync-unraid/main/dist/zfs.snapsync.plg
```

Confirm version **2026.09.28.01** after activation. This installs the testing build
of the existing SnapSync plugin, not a separate side-by-side plugin. Its update URL
tracks main, which now distributes this experimental release. Preserve configuration before installation; do not downgrade with active
work or assume an older coordinator can read the candidate's runtime journal.

Package SHA-256:
`bffb71cfb032cc6a1c007e6b8a39018e4c08911aa8835f4bd69631da4757243b`.
Candidate metadata is in `dist/testing/candidate.json`. It is not a passing
production acceptance certificate. The release record explicitly declares pending host acceptance; production-ready
promotion still requires complete evidence.

## Before starting

- [ ] Record Unraid and OpenZFS versions, candidate version, source commit, package
  SHA-256, browser/client host, receiver host-key fingerprint and test dataset roots.
  Never include passwords, private keys or receive-token contents in reports.
- [ ] Verify you installed the candidate containing **Stop automation and clean up…**.
  Pushing source to main alone does not update the published plugin package.
- [ ] Back up plugin configuration and inventory existing jobs/schedules. Use new,
  disposable source and destination datasets, including one child dataset and an
  unrelated sibling. Keep copies of test files outside those datasets.
- [ ] Use a separate disposable destination for SSH. The Linux receiver needs ZFS
  and trusted key-based SSH, but no SnapSync installation.
- [ ] Perform browser checks from another computer. Avoid page-wide Run all jobs
  now when real jobs are configured; use test schedules or a test-only configuration.

## First pass: normal operation

1. **Install and reconnect**
   - [ ] Install/update through the plugin installer. Activation completes, version
     is correct, and Activity loads without a missing-capability or handshake error.
   - [ ] Reload, log out/in and reconnect from another computer. No indefinite
     loading, blank dataset list, repeated login failure or stale cached controls.
2. **Save boundaries and usability**
   - [ ] Save one Auto dataset, schedule and retention policy. Edit then discard;
     saved values remain unchanged. History/Advanced fields need no nested dropdown.
   - [ ] Create, edit and cancel a backup job. Create/Save finishes the editor with
     no second page-level save. Other jobs and unsaved shared drafts stay unchanged.
   - [ ] Save shared settings separately. SSH shows relevant connection fields.
   - [ ] Use keyboard-only menus/dialogs and a narrow screen; focus returns to the
     trigger. Long dataset names, three dataset actions and pagination remain usable.
3. **Auto snapshots and local replication**
   - [ ] Allow the test schedule to run. Confirm timestamps/next run and intended
     datasets; unrelated and excluded child datasets must not acquire new snapshots.
   - [ ] Replicate test files locally, change files, then replicate again. Confirm
     destination contents through a safe read-only inspection and matching snapshot
     GUIDs. Recursive jobs include only their reviewed members.
4. **SSH replication and recovery**
   - [ ] Repeat initial and incremental replication to the Linux receiver. Activity
     shows progress and verified completion; the receiver needs no installed plugin.
   - [ ] Interrupt a disposable transfer using Cancel or a controlled disconnect.
     Review recovery and retry the original work. Completed members are not resent;
     unresolved receive state is explained rather than silently discarded.
5. **Retention and protection**
   - [ ] Enable source Keep latest 3 on the test job; review existing history when
     requested. Run enough successful cycles to exercise cleanup. Counts follow the
     policy plus explained exclusions; the receiver's incremental base survives.
   - [ ] Confirm destination retention, manual snapshots, plugin holds, an external
     hold and a disposable clone dependency behave as shown in the cleanup review.
     Protected snapshots remain; ordinary Delete does not bypass protection.
   - [ ] Pause/resume a test schedule. Pause persists across reload/reboot; it does
     not by itself authorize deleting replication history.

## Priority: stop managing and clean up

6. **Configured automation still exists**
   - [ ] Choose the source in Snapshots → Stop automation and clean up….
     Inspection identifies the exact Auto membership, jobs and destinations.
   - [ ] Stop automation. Only those settings change; schedules for other datasets,
     shared settings, child datasets and current files remain intact.
   - [ ] Review both ends. SnapSync history starts selected; manual/other-tool
     snapshots start unselected. Holds, clones and other operations have exclusions.
   - [ ] Delete the reviewed selection. Destination deletions precede source
     deletions. Closing/reopening never re-enables stopped automation.
7. **Jobs were already removed**
   - [ ] Leave test snapshots on both ends, remove the test job, then start cleanup.
     Use Job already removed? Add its destination for local and then SSH cases.
   - [ ] Old prefix-protected history can be explicitly reviewed/deleted here even
     when ordinary Delete still protects it. Do not release unrelated protections.
   - [ ] If stopped recovery still owns snapshots, explicitly abandon only eligible
     retired-job recovery. Other jobs and unresolved receive tokens stay protected.
   - [ ] Start a fresh review after partial cleanup. Previously selected destinations
     remain visible even though the corresponding job no longer exists.
8. **Failure boundaries**
   - [ ] Disconnect the test receiver after review but before deletion. The operation
     reports failure and source history remains. Reconnect and review remaining work.
   - [ ] Let approval expire for over five minutes, then submit: it must be rejected.
     Inspect settings again and obtain a fresh review.
   - [ ] Change settings after review: stale authorization is rejected. Create a new
     snapshot after review: it is not silently added to the captured deletion set.
   - [ ] Cancel cleanup in Activity. No further work is admitted after verified
     shutdown. Already completed deletions stay recorded; remaining work needs review.
   - [ ] Try retiring a dataset involved in recursive, shared or mixed-dataset work.
     Resolve those scopes separately; unrelated work must not be canceled implicitly.

## Restart, faults and soak

- [ ] With only disposable work active, attempt an update. Package replacement must
  wait for verified shutdown or report why it cannot safely proceed.
- [ ] Exercise daemon loss/watchdog recovery on test work. There must be no duplicate
  mutations or silent replay of uncertain destructive work. Capture Activity and logs.
- [ ] Reboot. Saved settings and pauses survive; RAM-only reviews expire. Retirement
  requires fresh inspection and explicit destination selection where jobs were removed.
- [ ] Run Auto plus local/SSH replication for at least 48 hours. Include cleanup,
  idle periods and one planned recovery. Record runs/failures, RAM/process growth,
  responsiveness, flash-write observations and final source/destination inventories.
- [ ] Exercise 10,000-snapshot browsing on disposable data: filters, page selection,
  disabled rows and captured matching selection. Record load time and responsiveness.

Do not fill a live pool to test pressure cleanup. Real low-space, identity-replacement,
flash-write tracing and destructive pool faults belong on dedicated disposable pool
fixtures. Record them as NOT TESTED until that evidence exists. The other supported
Unraid version and a separate Linux receiver also remain release gates if unavailable.

## Report each problem

```text
Candidate / commit / package SHA-256:
Unraid / OpenZFS / receiver versions:
Checklist item:
Dataset roots and job/operation IDs:
Exact steps:
Expected:
Actual:
Relevant timestamps, screenshot and redacted logs:
Any snapshots/files unexpectedly changed:
```

Stop further destructive tests if unrelated data changes, protected snapshots are
removed, receiver failure permits source deletion, or cancellation leaves unexplained
mutations. Preserve inventories and logs before retrying.
