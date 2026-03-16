#!/usr/bin/env php
<?php
/**
 * cron/price_update.php — Hourly price download
 * ================================================
 * Pulls latest prices for all S&P 500 tickers + SPY (benchmark) from Finnhub.
 * Safe to run every hour — uses ON DUPLICATE KEY UPDATE so re-runs
 * on the same calendar date simply refresh the price data.
 *
 * DreamHost cron: 0 * * * 1-5
 * (Every hour on weekdays — market hours are 9:30 AM–4 PM ET)
 *
 * The Daily Adventurer's Brief is generated separately by
 * cron/generate_brief.php (run once after market close, e.g. 6 PM ET)
 * or via the admin panel.
 *
 * Finnhub free tier: 60 calls/minute.
 * ~501 tickers takes ~9 minutes. Running hourly keeps data fresh
 * throughout the trading day without hammering the API.
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$apiKey    = $db->getSetting('finnhub_api_key');
$today     = date('Y-m-d');
$hour      = (int)date('G');  // 0-23
$startTime = microtime(true);

if (!$apiKey || $apiKey === 'YOUR_KEY_HERE') {
    cronLog('ERROR', 'Finnhub API key not configured in settings table.');
    exit(1);
}

// ---------------------------------------------------------------------------
// Get ticker list (active S&P 500 + SPY as benchmark)
// ---------------------------------------------------------------------------
$tickers    = $db->fetchAll("SELECT ticker FROM stocks WHERE is_active = 1 ORDER BY ticker");
$tickerList = array_column($tickers, 'ticker');
// SPY (S&P 500 ETF) is used as the index benchmark.
// ^GSPC requires a Finnhub paid subscription; SPY is available on the free tier
// and tracks the index almost perfectly.
if (!in_array('SPY', $tickerList)) {
    $tickerList[] = 'SPY';
}

$total       = count($tickerList);
$success     = 0;
$skipped     = 0;
$failed      = 0;
$callCount   = 0;
$minuteStart = microtime(true);

cronLog('INFO', "Starting hourly price update for {$total} tickers (run at {$hour}:00).");

// ---------------------------------------------------------------------------
// Pull prices — rate limit to 55 calls/minute
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

    // ON DUPLICATE KEY UPDATE — safe to run multiple times per day
    $db->run(
        "INSERT INTO stock_prices (ticker, price_date, close_price)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE close_price = VALUES(close_price)",
        [$ticker, $today, $price]
    );
    $success++;
}

// ---------------------------------------------------------------------------
// Set SPX inception if this is the very first price run ever
// ---------------------------------------------------------------------------
$inceptionDate = $db->getSetting('spx_inception_date', '');
if (empty($inceptionDate)) {
    $spxRow = $db->fetchOne(
        "SELECT close_price FROM stock_prices
         WHERE ticker = 'SPY' ORDER BY price_date ASC LIMIT 1"
    );
    if ($spxRow) {
        $db->setSetting('spx_inception_date',  $today);
        $db->setSetting('spx_inception_price', (string)$spxRow['close_price']);
        cronLog('INFO', "SPX inception baseline set: {$today} @ {$spxRow['close_price']}");
    }
}

// ---------------------------------------------------------------------------
// Rebuild portfolio snapshots for all users with holdings
// ---------------------------------------------------------------------------
$portfolio         = new Portfolio();
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
// Refresh portfolio leaderboard cache
// ---------------------------------------------------------------------------
try {
    $portfolio->refreshLeaderboard();
} catch (Exception $e) {
    cronLog('ERROR', 'Leaderboard refresh failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Monthly index-beating bonus — only on last business day of month
// ---------------------------------------------------------------------------
if (isLastBusinessDayOfMonth()) {
    // Only award once — check if already awarded today
    $bonusLastRun = $db->getSetting('portfolio_bonus_last_awarded', '');
    if ($bonusLastRun !== $today) {
        $awarded = $portfolio->awardMonthlyBonuses();
        $db->setSetting('portfolio_bonus_last_awarded', $today);
        cronLog('INFO', "Monthly index-beating bonuses awarded to {$awarded} players.");
    }
}

// ---------------------------------------------------------------------------
// Daily brief — generate once per day after market close (5 PM+ ET / 17:00+)
// The brief uses today's final prices so we only generate it post-close.
// Skip if already generated today or if it's before 5 PM server time.
// ---------------------------------------------------------------------------
$briefDate = $db->getSetting('daily_brief_date', '');
if ($briefDate !== $today && $hour >= 17) {
    cronLog('INFO', 'Generating Daily Adventurer\'s Brief (post-close)...');
    try {
        $brief = new DailyBrief();
        $ok    = $brief->generate();
        cronLog($ok ? 'INFO' : 'WARN', $ok
            ? 'Daily brief generated for ' . $today
            : 'Daily brief generation failed — will retry next hour.'
        );
    } catch (Exception $e) {
        cronLog('ERROR', 'DailyBrief exception: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Update timestamp
// ---------------------------------------------------------------------------
$db->setSetting('portfolio_last_price_update', date('Y-m-d H:i:s'));

$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "Done in {$elapsed}s — prices: {$success} ok / {$skipped} zero / {$failed} failed. Snapshots: {$snapshots}.");

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
