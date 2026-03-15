<?php
/**
 * admin/cron.php — Cron Health Check
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireAdmin();

$logs = [
    'Price Update'   => ROOT_PATH . '/logs/cron_prices.log',
    'S&P 500 Update' => ROOT_PATH . '/logs/cron_sp500.log',
    'App Log'        => ROOT_PATH . '/logs/app.log',
];

$cronStatus = [
    [
        'name'     => 'Nightly Price Update',
        'setting'  => 'portfolio_last_price_update',
        'schedule' => 'Weekdays 6 PM',
        'log'      => 'cron_prices.log',
    ],
    [
        'name'     => 'Quarterly S&P 500 Update',
        'setting'  => 'portfolio_last_sp500_update',
        'schedule' => 'Quarterly (Jan 2, Apr 1, Jul 1, Oct 1)',
        'log'      => 'cron_sp500.log',
    ],
];

/**
 * Read the last N lines of a file without loading the whole thing.
 */
function tailLog(string $path, int $lines = 50): string {
    if (!file_exists($path)) return '(log file not found)';
    $size = filesize($path);
    if ($size === 0) return '(empty)';

    $fp       = fopen($path, 'r');
    $buffer   = '';
    $pos      = -1;
    $lineCount = 0;

    while ($lineCount < $lines && abs($pos) < $size) {
        fseek($fp, $pos, SEEK_END);
        $char = fgetc($fp);
        if ($char === "\n" && $pos !== -1) $lineCount++;
        $buffer = $char . $buffer;
        $pos--;
    }

    fclose($fp);
    return trim($buffer);
}

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
    <div class="cron-status-grid">
        <?php foreach ($cronStatus as $cs):
            $lastRun = $db->getSetting($cs['setting'], '');
            $hoursAgo = $lastRun ? round((time() - strtotime($lastRun)) / 3600, 1) : null;
            $isHealthy = $hoursAgo !== null && $hoursAgo < 30;
        ?>
        <div class="card cron-status-card">
            <div class="cron-status-indicator <?= $isHealthy ? 'healthy' : 'stale' ?>"></div>
            <h3><?= e($cs['name']) ?></h3>
            <div class="cron-meta">
                <div><span class="text-muted">Schedule:</span> <?= e($cs['schedule']) ?></div>
                <div><span class="text-muted">Last run:</span>
                    <?php if ($lastRun): ?>
                        <strong><?= e($lastRun) ?></strong>
                        <span class="text-muted">(<?= $hoursAgo ?>h ago)</span>
                    <?php else: ?>
                        <span class="text-red">Never</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- LOG TAILS -->
    <?php foreach ($logs as $name => $path): ?>
    <div class="card mt-3">
        <div class="log-header">
            <h3><?= e($name) ?></h3>
            <code class="text-muted" style="font-size:0.75rem"><?= e(str_replace(ROOT_PATH, '', $path)) ?></code>
        </div>
        <pre class="log-tail"><?= e(tailLog($path, 40)) ?></pre>
    </div>
    <?php endforeach; ?>

</div>

<?php
$pageContent = ob_get_clean();
require TPL_PATH . '/layout.php';
?>
