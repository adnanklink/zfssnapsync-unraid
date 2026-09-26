<?php
require_once __DIR__ . '/response-helpers.php';
if (!defined('ZFSAS_WORKSPACE')) { define('ZFSAS_WORKSPACE', true); }
function zfsas_ui_h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function zfsas_ui_url($section, $tab = '') { return (!empty($GLOBALS['zfsas_top_tab']) ? '/ZFSSnapSyncTab?' : '/Settings/ZFSSnapSync?') . http_build_query(array_filter(['section' => $section, 'tab' => $tab])); }
$uiSection = is_string($_GET['section'] ?? null) ? $_GET['section'] : 'overview';
$uiTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
if ($uiSection === 'main') { $uiSection = 'snapshots'; $uiTab = 'automation'; }
$uiSection = ['special-features' => 'settings', 'tools' => 'settings', 'snapshot-manager' => 'snapshots', 'backups' => 'replication'][$uiSection] ?? $uiSection;
if ($uiSection === 'snapshots' && $uiTab === 'automation') { $uiSection = 'automation'; $uiTab = ''; }
$uiSections = ['overview' => ['Overview', 'Your snapshots and transfers, at a glance.', 'overview'],
    'automation' => ['Automatic snapshots', 'Keep earlier versions of your data on a schedule.', 'automation'],
    'snapshots' => ['Snapshots', 'Find an earlier version, restore it, or manage its history.', 'snapshots'],
    'replication' => ['Backup copies', 'Keep another copy on a different pool or server.', 'replication'],
    'activity' => ['Activity', 'Follow current work and investigate recent results.', 'activity'],
    'settings' => ['Settings', 'Interface preferences, data organization, and diagnostics.', 'settings'],
    'help' => ['Help', 'Understand the workflows and find your next step.', 'help']];
if (!isset($uiSections[$uiSection])) { $uiSection = 'overview'; }
if ($uiSection === 'snapshots') { $uiTab = $uiTab === 'automation' ? 'automation' : 'browse'; }
if ($uiSection === 'settings') { $uiTab = $uiTab === 'migrator' ? 'migrator' : ''; }
$uiCsrf = zfsas_get_csrf_token();
$uiVersion = 'Development';
$installed = '/var/log/plugins/zfs.snapsync.plg';
if (is_file($installed) && preg_match('/<PLUGIN\b[^>]*version="([^"]+)"/', (string) file_get_contents($installed), $match)) { $uiVersion = $match[1]; }
$uiStandalone = empty($GLOBALS['zfsas_host_shell']);
if ($uiStandalone) { ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= zfsas_ui_h($uiSections[$uiSection][0]) ?> · ZFS SnapSync</title></head><body><?php }
?>
<link rel="stylesheet" href="/plugins/zfs.snapsync/css/workspace.css?v=<?= (int) filemtime(__DIR__ . '/../css/workspace.css') ?>">
<div class="zfsas-workspace" data-section="<?= zfsas_ui_h($uiSection) ?>" data-csrf="<?= zfsas_ui_h($uiCsrf) ?>">
<a class="ui-skip" href="#workspace-content">Skip to content</a>
<aside class="ui-sidebar">
  <a class="ui-brand" href="<?= zfsas_ui_url('overview') ?>"><img class="ui-brand-mark" src="/plugins/zfs.snapsync/images/zfs-snapsync.png?v=2026.09.18.04" alt="" width="40" height="40"><span>SnapSync<small>Snapshots &amp; backup copies</small></span></a>
  <button type="button" class="ui-menu-toggle" aria-expanded="false" aria-controls="workspace-navigation">Menu</button>
  <nav id="workspace-navigation" aria-label="Plugin navigation">
  <?php $icons = ['overview' => '▦', 'automation' => '◷', 'snapshots' => '▤', 'replication' => '⇄', 'activity' => '◷', 'settings' => '⚙', 'help' => '?']; foreach ($uiSections as $key => $item): ?>
    <a href="<?= zfsas_ui_url($key) ?>" <?= $key === $uiSection ? 'aria-current="page"' : '' ?>><span class="ui-nav-icon" aria-hidden="true"><?= $icons[$key] ?></span><?= zfsas_ui_h($item[0]) ?></a>
  <?php endforeach; ?>
  </nav>
  <div class="ui-sidebar-foot"><span class="ui-badge">Development build</span><small><?= zfsas_ui_h($uiVersion) ?></small><a href="<?= zfsas_ui_url('help') ?>#recovery">Runtime history lives in RAM</a></div>
</aside>
<main id="workspace-content" class="ui-content" tabindex="-1">
  <header class="ui-page-header"><div><h1><?= zfsas_ui_h($uiSections[$uiSection][0]) ?></h1><p><?= zfsas_ui_h($uiSections[$uiSection][1]) ?></p></div><a class="ui-subtle-link" href="<?= zfsas_ui_url('help') ?>">Help &amp; recovery <span aria-hidden="true">↗</span></a></header>

  <div id="workspace-notice" role="status" aria-live="polite"></div>
  <script src="/plugins/zfs.snapsync/js/workspace.js?v=<?= (int) filemtime(__DIR__ . '/../js/workspace.js') ?>"></script>
  <script src="/plugins/zfs.snapsync/js/workspace-requests.js?v=<?= (int) filemtime(__DIR__ . '/../js/workspace-requests.js') ?>"></script>
  <script src="/plugins/zfs.snapsync/js/replication-recovery.js?v=<?= (int) filemtime(__DIR__ . '/../js/replication-recovery.js') ?>"></script>
  <?php
  switch ($uiSection) {
      case 'automation': require __DIR__ . '/settings.php'; break;
      case 'snapshots': require __DIR__ . '/snapshot-manager-page.php'; break;
      case 'replication': require __DIR__ . '/send-settings.php'; break;
      case 'settings': require __DIR__ . ($uiTab === 'migrator' ? '/migrate-datasets.php' : '/views/tools.php'); break;
      case 'help': require __DIR__ . '/views/help.php'; break;
      default: require __DIR__ . '/views/operations.php'; break;
  }
  ?>
  <script src="/plugins/zfs.snapsync/js/workflow-layout.js?v=<?= (int) filemtime(__DIR__ . '/../js/workflow-layout.js') ?>"></script>
  <footer class="ui-footer">ZFS SnapSync by Adnan Nashawaty <span>Development preview · Full replication coordination is still in progress.</span> <a href="https://www.paypal.com/paypalme/adnanklink" target="_blank" rel="noopener noreferrer">Support development ↗</a></footer>
</main>
</div>
<?php if ($uiStandalone) { ?></body></html><?php }
