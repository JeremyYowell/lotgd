<?php
/**
 * admin/cron.php — Cron Health Check
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

$cronStatus = [
    [
        'name'          => 'Hourly Price Update',
        'setting'       => 'portfolio_last_price_update',
        'schedule'      => 'Weekdays every hour (0 * * * 1-5)',
        'healthy_hours' => 2,
    ],
    [
        'name'            => 'Daily Adventurer\'s Brief',
        'setting'         => 'daily_brief_date',
        'schedule'        => 'Daily after market close (auto via hourly cron ≥17:00)',
        'healthy_hours'   => 28,
        'setting_is_date' => true,
    ],
    [
        'name'          => 'Quarterly S&P 500 Update',
        'setting'       => 'portfolio_last_sp500_update',
        'schedule'      => 'Quarterly (Jan 2, Apr 1, Jul 1, Oct 1)',
        'healthy_hours' => 24 * 95,
    ],
];

// Log definitions — default_off levels are unchecked by default
$logs = [
    [
        'label'       => 'Price Update & Brief',
        'id'          => 'log_prices',
        'path'        => ROOT_PATH . '/logs/cron_prices.log',
        'default_off' => ['WARN'],  // after-hours Finnhub WARN noise
    ],
    [
        'label'       => 'S&P 500 Update',
        'id'          => 'log_sp500',
        'path'        => ROOT_PATH . '/logs/cron_sp500.log',
        'default_off' => [],
    ],
    [
        'label'       => 'App Log',
        'id'          => 'log_app',
        'path'        => ROOT_PATH . '/logs/app.log',
        'default_off' => [],
    ],
];

$allLevels = ['ERROR', 'WARN', 'INFO', 'DEBUG'];

function parseTailLog(string $path, int $lines = 80): array {
    if (!file_exists($path)) return [];
    $size = filesize($path);
    if ($size === 0) return [];

    $fp = fopen($path, 'r');
    $buffer = '';
    $pos = -1;
    $lineCount = 0;

    while ($lineCount < $lines && abs($pos) < $size) {
        fseek($fp, $pos, SEEK_END);
        $char = fgetc($fp);
        if ($char === "\n" && $pos !== -1) $lineCount++;
        $buffer = $char . $buffer;
        $pos--;
    }
    fclose($fp);

    $rawLines = array_reverse(explode("\n", trim($buffer)));
    $parsed   = [];

    foreach ($rawLines as $line) {
        $line = rtrim($line);  // strip trailing whitespace/CR
        if ($line === '') continue;
        $level = 'INFO';
        if (preg_match('/\[(ERROR|WARN|INFO|DEBUG)\]/', $line, $m)) {
            $level = $m[1];
        }
        $parsed[] = ['level' => $level, 'text' => $line];
    }

    return $parsed;
}

$levelColors = [
    'ERROR' => '#ef4444',
    'WARN'  => '#f59e0b',
    'INFO'  => '#22c55e',
    'DEBUG' => '#6b82a0',
];

$pageTitle = 'Cron Health';
$bodyClass = 'page-admin';
$extraCss  = ['admin.css'];

ob_start();
?>

<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="<?= BASE_URL ?>/admin/index.php" class="admin-back">← Admin</a>
            <h1>🕐 Cron Health Check</h1>
        </div>
    </div>

    <!-- STATUS CARDS -->
    <div class="cron-status-grid" style="grid-template-columns:repeat(3,1fr)">
        <?php foreach ($cronStatus as $cs):
            $rawVal   = $db->getSetting($cs['setting'], '');
            $hoursAgo = null;
            if (!empty($rawVal)) {
                $hoursAgo = round((time() - strtotime($rawVal)) / 3600, 1);
            }
            $threshold = (int)($cs['healthy_hours'] ?? 30);
            $isHealthy = $hoursAgo !== null && $hoursAgo < $threshold;
            $dayOfWeek = (int)date('N');
            if ($cs['setting'] === 'portfolio_last_price_update' && $dayOfWeek >= 6) {
                $isHealthy = $hoursAgo !== null;
            }
        ?>
        <div class="card cron-status-card">
            <div class="cron-status-indicator <?= $isHealthy ? 'healthy' : 'stale' ?>"></div>
            <h3 style="font-size:0.92rem"><?= e($cs['name']) ?></h3>
            <div class="cron-meta">
                <div><span class="text-muted">Schedule:</span>
                    <small><?= e($cs['schedule']) ?></small>
                </div>
                <div><span class="text-muted">Last run:</span>
                    <?php if ($rawVal): ?>
                        <strong><?= e($rawVal) ?></strong>
                        <?php if ($hoursAgo !== null): ?>
                        <span class="text-muted">
                            (<?= $hoursAgo < 24
                                ? $hoursAgo . 'h ago'
                                : round($hoursAgo / 24, 1) . 'd ago' ?>)
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-red">Never</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- BRIEF STATUS DETAIL -->
    <?php
    $briefDate  = $db->getSetting('daily_brief_date', '');
    $briefGenAt = $db->getSetting('daily_brief_generated_at', '');
    $briefHtml  = $db->getSetting('daily_brief_html', '');
    ?>
    <div class="card mt-3" style="padding:1rem 1.5rem">
        <h3 class="mb-2" style="font-size:0.9rem">📜 Daily Brief Status</h3>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;font-size:0.85rem">
            <div>
                <span class="text-muted">Last generated:</span><br>
                <strong><?= $briefDate ?: 'Never' ?></strong>
                <?php if ($briefGenAt): ?>
                <br><small class="text-muted"><?= e($briefGenAt) ?></small>
                <?php endif; ?>
            </div>
            <div>
                <span class="text-muted">Content cached:</span><br>
                <strong><?= !empty($briefHtml)
                    ? 'Yes (' . number_format(strlen($briefHtml)) . ' chars)'
                    : 'No' ?></strong>
            </div>
            <div>
                <span class="text-muted">Manual regenerate:</span><br>
                <code style="font-size:0.75rem">php cron/generate_brief.php --force</code>
            </div>
        </div>
    </div>

    <!-- LOG WINDOWS -->
    <?php foreach ($logs as $log):
        $entries       = parseTailLog($log['path'], 80);
        $defaultOff    = $log['default_off'] ?? [];
        $presentLevels = array_unique(array_column($entries, 'level'));
    ?>
    <div class="card mt-3">

        <div class="log-header" style="flex-wrap:wrap;gap:0.75rem;align-items:center;margin-bottom:0.75rem">
            <div style="display:flex;align-items:center;gap:0.75rem;flex:1">
                <h3 style="margin:0"><?= e($log['label']) ?></h3>
                <code class="text-muted" style="font-size:0.72rem">
                    <?= e(str_replace(ROOT_PATH, '', $log['path'])) ?>
                </code>
            </div>

            <!-- Level filter checkboxes -->
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
                <span class="text-muted" style="font-size:0.68rem;font-family:var(--font-heading);
                      letter-spacing:0.1em;text-transform:uppercase">Show:</span>
                <?php foreach ($allLevels as $lvl):
                    $checked    = !in_array($lvl, $defaultOff);
                    $hasEntries = in_array($lvl, $presentLevels);
                    $color      = $levelColors[$lvl] ?? '#888';
                ?>
                <label style="display:flex;align-items:center;gap:0.3rem;cursor:pointer;
                               opacity:<?= $hasEntries ? '1' : '0.3' ?>;
                               font-family:var(--font-heading);font-size:0.68rem;
                               letter-spacing:0.06em;text-transform:uppercase;
                               color:<?= $color ?>">
                    <input type="checkbox"
                           id="<?= $log['id'] ?>_<?= strtolower($lvl) ?>"
                           onchange="filterLog('<?= $log['id'] ?>')"
                           <?= $checked ? 'checked' : '' ?>
                           style="accent-color:<?= $color ?>;width:13px;height:13px">
                    <?= $lvl ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="<?= $log['id'] ?>_entries" class="log-tail"
             style="max-height:380px;overflow-y:auto;overflow-x:auto;background:#060810;
                    border:1px solid var(--color-border);border-radius:var(--radius);
                    padding:0.6rem 0.75rem;font-family:monospace;font-size:0.73rem;
                    line-height:1.45;white-space:pre"><?php
if (empty($entries)):
?><span style="color:#3d5070">(log file empty or not found)</span><?php
else:
    foreach ($entries as $entry):
        $lvl    = $entry['level'];
        $color  = $levelColors[$lvl] ?? '#9aabb8';
        $hidden = in_array($lvl, $defaultOff) ? ' style="display:none"' : '';
?><div class="log-line" data-level="<?= $lvl ?>" data-log="<?= $log['id'] ?>"<?= $hidden ?>><span style="color:<?= $color ?>"><?= e($entry['text']) ?></span></div><?php
    endforeach;
endif;
?></div>

    </div>
    <?php endforeach; ?>

</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = <<<'JS'
<script>
function filterLog(logId) {
    const levels = ['error', 'warn', 'info', 'debug'];
    const shown  = {};

    levels.forEach(lvl => {
        const cb = document.getElementById(logId + '_' + lvl);
        shown[lvl.toUpperCase()] = cb ? cb.checked : true;
    });

    document.querySelectorAll('.log-line[data-log="' + logId + '"]').forEach(row => {
        row.style.display = shown[row.dataset.level] ? '' : 'none';
    });
}
</script>
JS;

require TPL_PATH . '/layout.php';
?>
