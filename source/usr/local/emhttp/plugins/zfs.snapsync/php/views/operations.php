<?php $isOverview = $uiSection === 'overview'; ?>
<?php if ($isOverview): ?>
<?php
require_once __DIR__ . '/../send-helpers.php';
try { $overviewConfig = zfsas_config_read_pair('/boot/config/plugins/zfs.snapsync', true); } catch (Throwable $error) { $overviewConfig = null; }
$overviewData = array_filter(explode(',', $overviewConfig['auto']['DATASETS'] ?? ''));
$overviewErrors = []; $overviewWarnings = [];
$overviewJobs = zfsas_send_parse_jobs($overviewConfig['send']['SEND_JOBS'] ?? '', $overviewErrors, $overviewWarnings);
$datasetCount = count($overviewData);
$jobCount = count($overviewJobs);
?>
<div class="ui-two-column ui-task-cards">
  <section class="ui-card">
    <h2>Automatic snapshots</h2>
    <?php if ($overviewConfig === null): ?><strong>Configuration unavailable</strong>
    <?php elseif ($datasetCount): ?><strong><?= $datasetCount ?> <?= $datasetCount === 1 ? 'dataset' : 'datasets' ?> configured</strong>
    <?php else: ?><p>Keep earlier versions on the source pool.</p><?php endif; ?>
    <?php if (($overviewConfig['auto']['DRY_RUN'] ?? '0') === '1'): ?><p class="ui-notice">Dry Run enabled — no snapshots are created or deleted.</p><?php endif; ?>
    <a class="btn <?= $datasetCount || $overviewConfig === null ? '' : 'btn-primary' ?>" href="<?= zfsas_ui_url('automation') ?>"><?= $datasetCount || $overviewConfig === null ? 'Manage automatic snapshots' : 'Set up automatic snapshots' ?></a>
  </section>
  <section class="ui-card">
    <h2>Backup copies</h2>
    <?php if ($overviewConfig === null): ?><strong>Configuration unavailable</strong>
    <?php elseif ($jobCount): ?><strong><?= $jobCount ?> backup <?= $jobCount === 1 ? 'job' : 'jobs' ?> configured</strong>
    <?php else: ?><p>Keep a read-only copy on another pool or server.</p><?php endif; ?>
    <a class="btn" href="<?= zfsas_ui_url('replication') ?>"><?= $jobCount || $overviewConfig === null ? 'Manage backup copies' : 'Create a backup job' ?></a>
  </section>
</div>

<div class="ui-metrics" aria-label="Runtime summary">
  <div class="ui-metric"><span>Active in recent history</span><strong id="summary-active">—</strong></div>
  <div class="ui-metric"><span>Needs attention</span><strong id="summary-attention">—</strong></div>
  <div class="ui-metric"><span>Paused schedules</span><strong id="summary-paused">—</strong></div>
</div>
<div id="summary-availability" aria-live="polite"></div>
<div class="ui-two-column ui-overview-status"><section class="ui-card"><div class="ui-card-heading"><h2>Upcoming schedules</h2><a href="<?= zfsas_ui_url('snapshots','automation') ?>">Manage →</a></div><div id="upcoming-schedules"><p class="muted">Loading schedule information…</p></div><p class="ui-footnote">Host timezone: <span data-host-zone>—</span></p></section><section class="ui-card"><div class="ui-card-heading"><h2>Needs attention</h2><a href="<?= zfsas_ui_url('activity') ?>&amp;state=failed">Review failures →</a></div><div id="attention-list"><p class="muted">Checking recent records…</p></div></section></div>
<?php else: ?>

<div id="summary-availability" aria-live="polite"></div>
<div class="toolbar"><label>Operation<select id="activity-type"><option value="">All types</option><option value="auto">Automatic snapshots</option><option value="replication">Replication</option><option value="batch">Snapshot batches</option><option value="migration">Migration</option></select></label><label>State<select id="activity-state"><option value="">All states</option><option value="active">Active</option><option value="failed">Needs attention</option><option value="reviewed">Recovered or dismissed</option><option value="complete">Completed</option><option value="canceled">Canceled</option></select></label><button type="button" id="activity-refresh">Refresh</button><span class="muted">Host timezone: <span data-host-zone>—</span></span></div>
<?php endif; ?>
<section class="ui-card"><div class="ui-card-heading"><h2><?= $isOverview ? 'Current & recent work' : 'Operations' ?></h2><span id="activity-updated" class="muted">Connecting…</span></div><div class="table-wrap"><table class="ui-operations-table"><thead><tr><th>Operation</th><th>Dataset / destination</th><th>Status</th><th>Requested</th><th></th></tr></thead><tbody id="operation-rows"><tr><td colspan="5" class="ui-empty">Loading runtime records…</td></tr></tbody></table></div><p class="ui-footnote" id="activity-history-scope"><?= $isOverview ? 'Up to 8 recent operations this boot.' : 'Up to 120 recent operations this boot.' ?></p><?php if (!$isOverview): ?><a href="<?= zfsas_ui_url('help') ?>#recovery">About history and recovery</a><?php endif; ?></section>
<?php if (!$isOverview): ?><section class="ui-card"><details id="activity-logs"><summary>Logs</summary><div class="toolbar"><label>Log source<select id="activity-log-type"><option value="auto">Latest Auto Snapshot run</option><option value="debug">Auto Snapshot debug</option><option value="replication">Replication</option><option value="migration">Migration</option><option value="batch">Snapshot batches</option><option value="coordinator">Coordinator</option></select></label><button type="button" id="activity-log-refresh">Refresh log</button><a class="btn" href="/plugins/zfs.snapsync/php/log-tail.php?download=1">Download Auto Snapshot logs</a></div><p class="ui-footnote">Last 200 lines. Shared logs may include other operations.</p><pre id="activity-log-output">Choose a log source.</pre></details></section><?php endif; ?>
<dialog id="operation-detail" aria-labelledby="operation-title"><div class="ui-dialog-header"><h2 id="operation-title">Operation details</h2><button type="button" data-close-dialog aria-label="Close operation details">Close</button></div><div id="operation-body"></div><div id="operation-actions" class="toolbar"></div><?php if (!$isOverview): ?><button id="next-failure" type="button" hidden>Next failure</button><?php endif; ?><p id="operation-action-message" role="status"></p></dialog>
<script src="/plugins/zfs.snapsync/js/operations.js?v=<?= (int) filemtime(__DIR__ . '/../../js/operations.js') ?>"></script>
