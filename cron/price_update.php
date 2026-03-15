#!/usr/bin/env php
<?php
/**
 * cron/price_update.php — Nightly price download
 * ================================================
 * Pulls previous-close prices for all active S&P 500
 * tickers + ^GSPC (S&P 500 index) from Finnhub.
 *
 * DreamHost cron setup:
 *   Command: /usr/bin/php /home/USERNAME/DOMAIN/cron/price_update.php
 *   Schedule: Daily at 6:00 PM (after US market close at 4 PM ET)
 *             or later to ensure Finnhub has final close data.
 *   Cron expression: 0 18 * * 1-5
 *   (Weekdays only — no prices on weekends)
 *
 * Finnhub free tier: 60 API calls/minute
 * With ~501 tickers we throttle to 55 calls/minute to stay safe.
 * Runtime: ~9-10 minutes total.
 */

// ---------------------------------------------------------------------------
// Bootstrap (adjust path if cron/ is not inside your web root)
// ---------------------------------------------------------------------------
define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------
$apiKey    = $db->getSetting('finnhub_api_key');
$goldToUsd = (int) $db->getSetting('gold_to_usd_rate', 1000);
$today     = date('Y-m-d');
$startTime = microtime(true);

if (!$apiKey || $apiKey === 'YOUR_KEY_HERE') {
    cronLog('ERROR', 'Finnhub API key not configured in settings table.');
    exit(1);
}

// Skip if already ran today
$lastRun = $db->getSetting('portfolio_last_price_update', '');
if ($lastRun && date('Y-m-d', strtotime($lastRun)) === $today) {
    cronLog('INFO', 'Price update already ran today. Skipping.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Get ticker list (active S&P 500 stocks + ^GSPC index)
// ---------------------------------------------------------------------------
$tickers = $db->fetchAll(
    "SELECT ticker FROM stocks WHERE is_active = 1 ORDER BY ticker"
);
$tickerList = array_column($tickers, 'ticker');
$tickerList[] = '^GSPC';   // S&P 500 index

$total     = count($tickerList);
$success   = 0;
$skipped   = 0;
$failed    = 0;
$callCount = 0;
$minuteStart = microtime(true);

cronLog('INFO', "Starting price update for {$total} tickers.");

// ---------------------------------------------------------------------------
// Pull prices
// ---------------------------------------------------------------------------
foreach ($tickerList as $ticker) {

    // Rate limiting: max 55 calls/minute (leave headroom under 60 limit)
    $callCount++;
    if ($callCount % 55 === 0) {
        $elapsed = microtime(true) - $minuteStart;
        if ($elapsed < 60) {
            $sleep = (int)ceil(60 - $elapsed) + 2;
            cronLog('INFO', "Rate limit pause: sleeping {$sleep}s after {$callCount} calls.");
            sleep($sleep);
        }
        $minuteStart = microtime(true);
    }

    $price = finnhubQuote($ticker, $apiKey);

    if ($price === false) {
        cronLog('WARN', "No data returned for {$ticker}");
        $failed++;
        continue;
    }

    if ($price <= 0) {
        cronLog('WARN', "Zero/negative price for {$ticker}: {$price}");
        $skipped++;
        continue;
    }

    // Use ON DUPLICATE KEY to safely re-run without creating duplicates
    $db->run(
        "INSERT INTO stock_prices (ticker, price_date, close_price)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE close_price = VALUES(close_price)",
        [$ticker, $today, $price]
    );

    $success++;
}

// ---------------------------------------------------------------------------
// Set SPX inception values if this is the very first run
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
// Build portfolio snapshots for all users with holdings
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
// Rebuild portfolio leaderboard cache
// ---------------------------------------------------------------------------
try {
    $portfolio->refreshLeaderboard();
    cronLog('INFO', 'Portfolio leaderboard cache refreshed.');
} catch (Exception $e) {
    cronLog('ERROR', 'Leaderboard refresh failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Check if today is the last business day of the month — award bonuses
// ---------------------------------------------------------------------------
if (isLastBusinessDayOfMonth()) {
    $awarded = $portfolio->awardMonthlyBonuses();
    cronLog('INFO', "Monthly bonuses awarded to {$awarded} players.");
}

// ---------------------------------------------------------------------------
// Update last-run timestamp
// ---------------------------------------------------------------------------
$db->setSetting('portfolio_last_price_update', date('Y-m-d H:i:s'));

$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "Price update complete in {$elapsed}s. Success: {$success}, Skipped: {$skipped}, Failed: {$failed}, Snapshots: {$snapshots}");

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

/**
 * Call Finnhub /quote endpoint and return the previous close price.
 * Returns false on error.
 */
function finnhubQuote(string $ticker, string $apiKey): float|false {
    $url = 'https://finnhub.io/api/v1/quote?symbol='
         . urlencode($ticker)
         . '&token=' . urlencode($apiKey);

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

    // 'pc' = previous close — exactly what we want
    return (float)$data['pc'];
}

/**
 * Determine if today is the last business day of the current month.
 */
function isLastBusinessDayOfMonth(): bool {
    $today      = new DateTime();
    $lastOfMonth = new DateTime('last day of this month');

    // Walk back from last day of month until we hit a weekday
    while ($lastOfMonth->format('N') >= 6) {
        $lastOfMonth->modify('-1 day');
    }

    return $today->format('Y-m-d') === $lastOfMonth->format('Y-m-d');
}

/**
 * Write a timestamped log line to logs/cron_prices.log
 */
function cronLog(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    echo $line;   // visible in cron email output
    file_put_contents(ROOT_PATH . '/logs/cron_prices.log', $line, FILE_APPEND | LOCK_EX);
}
