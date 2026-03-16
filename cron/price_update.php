#!/usr/bin/env php
<?php
/**
 * cron/price_update.php — Nightly price download + daily brief generation
 * =========================================================================
 * DreamHost cron: 0 18 * * 1-5
 * (Weekdays at 6 PM server time, after US market close)
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$apiKey    = $db->getSetting('finnhub_api_key');
$today     = date('Y-m-d');
$startTime = microtime(true);

if (!$apiKey || $apiKey === 'YOUR_KEY_HERE') {
    cronLog('ERROR', 'Finnhub API key not configured.');
    exit(1);
}

// Skip if already ran today
$lastRun = $db->getSetting('portfolio_last_price_update', '');
if ($lastRun && date('Y-m-d', strtotime($lastRun)) === $today) {
    cronLog('INFO', 'Price update already ran today. Skipping.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Get ticker list
// ---------------------------------------------------------------------------
$tickers    = $db->fetchAll("SELECT ticker FROM stocks WHERE is_active = 1 ORDER BY ticker");
$tickerList = array_column($tickers, 'ticker');
$tickerList[] = '^GSPC';

$total       = count($tickerList);
$success     = 0;
$skipped     = 0;
$failed      = 0;
$callCount   = 0;
$minuteStart = microtime(true);

cronLog('INFO', "Starting price update for {$total} tickers.");

// ---------------------------------------------------------------------------
// Pull prices
// ---------------------------------------------------------------------------
foreach ($tickerList as $ticker) {
    $callCount++;
    if ($callCount % 55 === 0) {
        $elapsed = microtime(true) - $minuteStart;
        if ($elapsed < 60) {
            $sleep = (int)ceil(60 - $elapsed) + 2;
            cronLog('INFO', "Rate limit pause: {$sleep}s after {$callCount} calls.");
            sleep($sleep);
        }
        $minuteStart = microtime(true);
    }

    $price = finnhubQuote($ticker, $apiKey);

    if ($price === false) {
        cronLog('WARN', "No data for {$ticker}");
        $failed++;
        continue;
    }

    if ($price <= 0) {
        $skipped++;
        continue;
    }

    $db->run(
        "INSERT INTO stock_prices (ticker, price_date, close_price)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE close_price = VALUES(close_price)",
        [$ticker, $today, $price]
    );
    $success++;
}

// ---------------------------------------------------------------------------
// Set SPX inception if first run
// ---------------------------------------------------------------------------
$inceptionDate = $db->getSetting('spx_inception_date', '');
if (empty($inceptionDate)) {
    $spxRow = $db->fetchOne(
        "SELECT close_price FROM stock_prices WHERE ticker = '^GSPC' ORDER BY price_date ASC LIMIT 1"
    );
    if ($spxRow) {
        $db->setSetting('spx_inception_date',  $today);
        $db->setSetting('spx_inception_price', (string)$spxRow['close_price']);
        cronLog('INFO', "SPX inception set: {$today} @ {$spxRow['close_price']}");
    }
}

// ---------------------------------------------------------------------------
// Portfolio snapshots
// ---------------------------------------------------------------------------
require_once LIB_PATH . '/Portfolio.php';
$portfolio = new Portfolio();

$usersWithHoldings = $db->fetchAll(
    "SELECT DISTINCT user_id FROM portfolio_holdings WHERE shares > 0"
);

$snapshots = 0;
foreach ($usersWithHoldings as $row) {
    try {
        $portfolio->buildSnapshot((int)$row['user_id']);
        $snapshots++;
    } catch (Exception $e) {
        cronLog('ERROR', "Snapshot failed for user {$row['user_id']}: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Refresh portfolio leaderboard
// ---------------------------------------------------------------------------
try {
    $portfolio->refreshLeaderboard();
    cronLog('INFO', 'Portfolio leaderboard refreshed.');
} catch (Exception $e) {
    cronLog('ERROR', 'Leaderboard refresh failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Monthly index-beating bonus
// ---------------------------------------------------------------------------
if (isLastBusinessDayOfMonth()) {
    $awarded = $portfolio->awardMonthlyBonuses();
    cronLog('INFO', "Monthly bonuses awarded to {$awarded} players.");
}

// ---------------------------------------------------------------------------
// Generate Daily Adventurer's Brief
// ---------------------------------------------------------------------------
cronLog('INFO', 'Generating Daily Adventurer\'s Brief...');
try {
    require_once LIB_PATH . '/DailyBrief.php';
    $brief = new DailyBrief();
    $ok    = $brief->generate();
    cronLog($ok ? 'INFO' : 'ERROR', $ok ? 'Daily brief generated.' : 'Daily brief generation failed.');
} catch (Exception $e) {
    cronLog('ERROR', 'DailyBrief exception: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Update timestamp + finish
// ---------------------------------------------------------------------------
$db->setSetting('portfolio_last_price_update', date('Y-m-d H:i:s'));

$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "Done in {$elapsed}s. Prices: {$success} ok / {$skipped} skipped / {$failed} failed. Snapshots: {$snapshots}.");

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

function finnhubQuote(string $ticker, string $apiKey): float|false {
    $url = 'https://finnhub.io/api/v1/quote?symbol='
         . urlencode($ticker) . '&token=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return false;
    $data = json_decode($raw, true);
    if (!$data || !isset($data['pc'])) return false;
    return (float)$data['pc'];
}

function isLastBusinessDayOfMonth(): bool {
    $today       = new DateTime();
    $lastOfMonth = new DateTime('last day of this month');
    while ($lastOfMonth->format('N') >= 6) {
        $lastOfMonth->modify('-1 day');
    }
    return $today->format('Y-m-d') === $lastOfMonth->format('Y-m-d');
}

function cronLog(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    echo $line;
    file_put_contents(ROOT_PATH . '/logs/cron_prices.log', $line, FILE_APPEND | LOCK_EX);
}
