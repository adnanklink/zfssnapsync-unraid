<div class="ui-tool-grid">
<section class="ui-card"><h2>Dataset Migrator</h2><p>Review and convert top-level folders into child datasets.</p><a class="btn" href="<?= zfsas_ui_url('settings', 'migrator') ?>">Open migrator <span aria-hidden="true">→</span></a></section>
<section class="ui-card"><h2>Diagnostics</h2><p>Includes redacted configuration, runtime state, logs, and system information.</p><a class="btn" id="diagnostics_download" href="/plugins/zfs.snapsync/php/diagnostics.php" download="zfs_snapsync_diagnostics.zip">Download diagnostics</a><p class="ui-footnote">Review before sharing.</p></section>
</div>

<?php require_once __DIR__ . '/../interface-settings.php'; $interface = zfsas_interface_read('/boot/config/plugins/zfs.snapsync'); ?>
<section class="ui-card"><h2>Interface</h2>
<form id="interface-settings">
<input type="hidden" name="revision" value="<?= zfsas_ui_h($interface['revision']) ?>">
<label><input type="checkbox" name="show_tab" <?= $interface['enabled'] ? 'checked' : '' ?>> Show SnapSync in the Unraid navigation</label>
<p><button type="submit" class="btn">Save interface preference</button> <a id="interface-reload" class="btn" href="/Settings/ZFSSnapSync?section=tools" hidden>Reload navigation</a></p>
<p id="interface-status" role="status" aria-live="polite"></p>
</form></section>
<script src="/plugins/zfs.snapsync/js/interface-settings.js"></script>
