<div class="zfsas-send-wrap">
  <?php if ($datasetDiscoveryError !== null) : ?>
    <div class="zfsas-send-card">
      <div class="zfsas-send-help"><?php echo zfsas_send_h($datasetDiscoveryError); ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($notices)) : ?>
    <div class="zfsas-send-card">
      <?php foreach ($notices as $notice) : ?>
        <div class="zfsas-send-help" style="margin-bottom: 6px;"><?php echo zfsas_send_h($notice); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo zfsas_send_h($saveApiUrl); ?>" data-ajax-action="<?php echo zfsas_send_h($saveApiUrl); ?>" id="zfsas_send_form" novalidate>
    <input type="hidden" name="return_to" value="<?php echo zfsas_send_h($defaultReturnUrl); ?>">
    <?php if ($csrfToken !== '') : ?>
    <input type="hidden" name="csrf_token" value="<?php echo zfsas_send_h($csrfToken); ?>">
    <?php endif; ?>

<section class="ui-card" id="backup-list-panel"><div class="ui-card-heading"><div><h2>Your backup jobs</h2><p class="muted">Choose what to copy and where to keep it. Backups are read-only; restore a writable copy when needed.</p></div><button type="button" class="btn btn-primary" id="open-new-job">Add job</button></div>
<p id="dataset-discovery-status" role="status" aria-live="polite">Discovering ZFS datasets…</p>

<div id="replication-job-list"></div>
<div id="replication-job-storage" hidden><div id="zfsas_send_jobs_body">            <?php foreach (array_merge($formJobs, [['id'=>'', 'source'=>'', 'destination'=>'', 'frequency'=>'6h', 'children'=>'0', 'transport'=>'local', 'threshold'=>'100G']]) as $index => $job) : ?>
              <<?php echo $job['id'] === '' ? 'template id="job-template"' : 'div'; ?>>
              <div data-job data-source-keep="<?php echo $job['id'] === '' ? 3 : (int)zfsas_source_policy($config,$job)['keep']; ?>">
                <div class="zfsas-send-field">
                  <input type="hidden" name="job_id[<?php echo (int) $index; ?>]" value="<?php echo zfsas_send_h($job['id']); ?>">
                  <select name="job_source[<?php echo (int) $index; ?>]" class="zfsas-send-select" required>
                    <option value="">Select source dataset</option>
                    <?php foreach ($availableDatasets as $dataset) : ?>
                      <option value="<?php echo zfsas_send_h($dataset); ?>" <?php echo ($dataset === $job['source']) ? 'selected' : ''; ?>><?php echo zfsas_send_h($dataset); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="zfsas-send-field"><input class="zfsas-send-input" required name="job_destination[<?php echo (int) $index; ?>]" value="<?php echo zfsas_send_h($job['destination']); ?>"></div>
                <div class="zfsas-send-field">
                  <select name="job_frequency[<?php echo (int) $index; ?>]" class="zfsas-send-select">
                    <?php foreach (zfsas_send_frequency_options() as $value => $label) : ?>
                      <option value="<?php echo zfsas_send_h($value); ?>" <?php echo ($value === $job['frequency']) ? 'selected' : ''; ?>><?php echo zfsas_send_h($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="zfsas-send-field">
                  <select name="job_children[<?php echo (int) $index; ?>]" class="zfsas-send-select">
                    <option value="0" <?php echo (($job['children'] ?? '0') === '0') ? 'selected' : ''; ?>>No</option>
                    <option value="1" <?php echo (($job['children'] ?? '0') === '1') ? 'selected' : ''; ?>>Yes</option>
                  </select>
                </div>
                <div class="zfsas-send-field">
                  <?php $jobTransport = (string) ($job['transport'] ?? 'local'); ?>
                  <?php if (array_key_exists($jobTransport, zfsas_send_gui_transport_options())) : ?>
                    <select name="job_transport[<?php echo (int) $index; ?>]" class="zfsas-send-select">
                      <?php foreach (zfsas_send_gui_transport_options() as $value => $label) : ?>
                        <option value="<?php echo zfsas_send_h($value); ?>" <?php echo ($value === $jobTransport) ? 'selected' : ''; ?>><?php echo zfsas_send_h($label); ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else : ?>
                    <input type="hidden" name="job_transport[<?php echo (int) $index; ?>]" value="<?php echo zfsas_send_h($jobTransport); ?>">
                    <span class="zfsas-send-help">Unavailable transport saved in config</span>
                  <?php endif; ?>
                </div>
                <div class="zfsas-send-field"><input class="zfsas-send-input" name="job_threshold[<?php echo (int) $index; ?>]" value="<?php echo zfsas_send_h($job['threshold']); ?>"></div>
                <div class="zfsas-send-field">
                  <?php $cleanupMode=zfsas_send_cleanup_mode($config,$job); ?>
                  <select class="zfsas-send-select" name="job_cleanup_policy[<?php echo (int)$index; ?>]">
                    <option value="retention_only" <?php echo $cleanupMode==='retention_only'?'selected':''; ?>>Preserve retained snapshots</option>
                    <option value="older_anchors" <?php echo $cleanupMode==='older_anchors'?'selected':''; ?>>Delete older retained snapshots when space is needed (local only)</option>
                  </select>
                  <div class="zfsas-send-help">Enabling this permanently removes older daily/weekly restore points, oldest first, until the space target is met. The keep-all window, newest checkpoint and required replication references remain protected. Only this job's snapshots on the receiving dataset are eligible.</div>
                </div>
                <div class="zfsas-send-field">
                  <input type="hidden" name="job_remove[<?php echo (int) $index; ?>]" value="0" class="zfsas-send-remove-flag">
                  <button type="button" class="btn zfsas-send-remove-row">Remove</button>
                </div>
              </div></<?php echo $job['id'] === '' ? 'template' : 'div'; ?>>
            <?php endforeach; ?>
</div></div></section>
<details class="ui-card" id="replication-shared"><summary>Shared backup settings</summary>      <div class="zfsas-send-inline-grid">
        <div class="zfsas-send-field">
          <label for="send_snapshot_prefix">Send snapshot prefix base</label>
          <input id="send_snapshot_prefix" name="send_snapshot_prefix" class="zfsas-send-input" value="<?php echo zfsas_send_h($config['SEND_SNAPSHOT_PREFIX']); ?>">
          <div class="zfsas-send-help">The script appends a per-job id automatically so each replication path gets its own isolated send snapshot namespace.</div>
        </div>
        <div class="zfsas-send-field">
          <label for="send_max_parallel">Parallel send jobs</label>
          <input id="send_max_parallel" name="send_max_parallel" class="zfsas-send-input" type="number" min="1" max="8" value="<?php echo zfsas_send_h($config['SEND_MAX_PARALLEL']); ?>">
          <div class="zfsas-send-help">How many queued send jobs may transfer at the same time. Deletes still run one at a time.</div>
        </div>
        <div class="zfsas-send-field">
          <label for="send_rate_limit">Outbound stream rate limit</label>
          <input id="send_rate_limit" name="send_rate_limit" class="zfsas-send-input" value="<?php echo zfsas_send_h($config['SEND_RATE_LIMIT'] ?? '0'); ?>" placeholder="1M">
          <div class="zfsas-send-help">Caps every ZFS send stream before SSH using mbuffer. Use 0 to disable, or a positive rate such as 1M or 8M. Keep this non-zero on Internet-constrained links.</div>
        </div>
      </div>

      <label for="shared-transport">Connection transport</label><select id="shared-transport"><option value="local">Local datasets</option><option value="ssh">SSH receiver</option></select><div id="shared-ssh-settings" style="margin-top: 14px;">
        <h4 style="margin-top:0;">SSH receiver settings</h4>
        <div class="zfsas-send-help">
          SSH sends run zfs send through an audited ssh receive command. SSH transport uses keys or other preconfigured non-interactive authentication. This page stores connection metadata and an optional local private-key path only; it does not store raw passwords or private-key contents.
        </div>
        <div class="zfsas-send-retention-grid" style="margin-top: 12px;">
          <div class="zfsas-send-field">
            <label for="send_ssh_host">SSH host</label>
            <input id="send_ssh_host" name="send_ssh_host" class="zfsas-send-input" value="<?php echo zfsas_send_h($config['SEND_SSH_HOST'] ?? ''); ?>" placeholder="backup.example.lan">
          </div>
          <div class="zfsas-send-field">
            <label for="send_ssh_port">SSH port</label>
            <input id="send_ssh_port" name="send_ssh_port" class="zfsas-send-input" type="number" min="1" max="65535" value="<?php echo zfsas_send_h($config['SEND_SSH_PORT'] ?? '22'); ?>">
          </div>
          <div class="zfsas-send-field">
            <label for="send_ssh_user">SSH user</label>
            <input id="send_ssh_user" name="send_ssh_user" class="zfsas-send-input" value="<?php echo zfsas_send_h($config['SEND_SSH_USER'] ?? 'root'); ?>" placeholder="root">
          </div>
        </div>
        <div class="zfsas-send-field" style="margin-top: 12px;">
          <label for="send_ssh_key_path">SSH private key path</label>
          <input id="send_ssh_key_path" name="send_ssh_key_path" class="zfsas-send-input" value="<?php echo zfsas_send_h($config['SEND_SSH_KEY_PATH'] ?? ''); ?>" placeholder="/boot/config/ssh/root_id_ed25519">
          <div class="zfsas-send-help">Leave blank to use the system SSH agent/default keys. Prefer a key restricted to the receiver and keep file permissions tight.</div>
        </div>
      </div>

      <div class="zfsas-send-inline-grid" style="margin-top: 18px;">
        <div class="zfsas-send-field">
          <label>Retention Policy</label>
          <div class="zfsas-send-help">
            Scheduled sends queue destination snapshot deletions using the same keep-all / daily / weekly retention pattern as autosnapshot. The newest successful send checkpoint is always protected so the next incremental send still has a valid base.
            This retention pass runs before the transfer, then a zero-change cleanup pass runs after the transfer to queue any duplicate no-change snapshots that slipped in with the replicated history.
          </div>
        </div>
        <div class="zfsas-send-field">
          <label>&nbsp;</label>
          <div class="zfsas-send-help">Deletes are queued in the background instead of blocking the active send.</div>
        </div>
      </div>

      <div class="zfsas-send-retention-grid">
        <div class="zfsas-send-field">
          <label for="send_keep_all_for_days">Keep all snapshots for</label>
          <input id="send_keep_all_for_days" name="send_keep_all_for_days" class="zfsas-send-input" type="number" min="1" max="36500" value="<?php echo zfsas_send_h($config['SEND_KEEP_ALL_FOR_DAYS']); ?>">
          <div class="zfsas-send-help">Newest snapshots inside the destination tree stay at full resolution for this many days.</div>
        </div>
        <div class="zfsas-send-field">
          <label for="send_keep_daily_until_days">Keep daily snapshots until</label>
          <input id="send_keep_daily_until_days" name="send_keep_daily_until_days" class="zfsas-send-input" type="number" min="2" max="36500" value="<?php echo zfsas_send_h($config['SEND_KEEP_DAILY_UNTIL_DAYS']); ?>">
          <div class="zfsas-send-help">After the full-resolution window, the destination keeps one snapshot per day until this age.</div>
        </div>
        <div class="zfsas-send-field">
          <label for="send_keep_weekly_until_days">Keep weekly snapshots until</label>
          <input id="send_keep_weekly_until_days" name="send_keep_weekly_until_days" class="zfsas-send-input" type="number" min="3" max="36500" value="<?php echo zfsas_send_h($config['SEND_KEEP_WEEKLY_UNTIL_DAYS']); ?>">
          <div class="zfsas-send-help">After the daily window, the destination keeps one snapshot per week until this age.</div>
        </div>
      </div>
    <?php echo zfsas_config_tools_markup('send', $configDir, $pageConfig); ?>
<div class="ui-form-footer"><button type="button" class="btn btn-primary" id="save_send_btn">Save shared settings</button><span id="shared-save-status" role="status"></span></div>
    </details>
<section hidden class="ui-flow" id="edit-job-dialog" aria-labelledby="edit-job-title"><div class="ui-dialog-header"><h2 id="edit-job-title">Replication job</h2><button type="button" id="cancel-job-edit" class="btn-quiet">Cancel</button></div><div id="job-editor-body"></div><p id="job-editor-error" role="alert"></p><div class="ui-form-footer"><button type="button" class="btn btn-primary" id="finish-job-edit">Save job</button></div></section>
    <div class="zfsas-send-actions">
      <div id="send_run_status" class="zfsas-send-run-status">Manual ZFS send is ready.</div>
      <div id="send_feedback" class="zfsas-send-feedback">
        <?php if (!empty($errors)) : ?>
          <div class="zfsas-send-alert zfsas-send-alert-error">
            <?php foreach ($errors as $error) : ?>
              <div><?php echo zfsas_send_h($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <button type="button" class="btn" id="run_send_now">Run all jobs now</button>
      <noscript><button type="submit" class="btn btn-primary">Save replication</button></noscript>
    </div>

  </form>
<script src="/plugins/zfs.snapsync/js/config-tools.js"></script>

<p class="ui-footnote">Monitor transfers and review failures in <a href="/Settings/ZFSSnapSync?section=activity&type=replication">Activity</a>.</p>
</div>
<script type="application/json" id="replication-options"><?php echo json_encode([$saveApiUrl, $runApiUrl, $queueStatusApiUrl, $queueStreamApiUrl, $queueActionApiUrl, $queueLogDownloadApiUrl], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="/plugins/zfs.snapsync/js/source-retention.js?v=<?= (int) filemtime(__DIR__ . '/../../js/source-retention.js') ?>"></script>
<script src="/plugins/zfs.snapsync/js/replication.js?v=<?= (int) filemtime(__DIR__ . '/../../js/replication.js') ?>"></script>
<script id="send-schedule-specs" type="application/json"><?php
$displaySpecs = [];
foreach ($formJobs as $job) { $displaySpecs[$job['id']] = zfsas_send_schedule_spec($config, $job); }
echo json_encode($displaySpecs ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?></script>
<script src="/plugins/zfs.snapsync/js/send-schedule.js"></script>
<script src="/plugins/zfs.snapsync/js/dataset-discovery.js"></script>


<script src="/plugins/zfs.snapsync/js/replication-editor.js?v=<?= (int) filemtime(__DIR__ . '/../../js/replication-editor.js') ?>"></script>
