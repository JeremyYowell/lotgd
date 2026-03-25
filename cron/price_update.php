#!/usr/bin/env php
<?php
/**
 * cron/price_update.php — Hourly price download
 * ================================================
 * Pulls latest prices for all active stocks and SPY benchmark.
 *
 * Data sources (split by exchange):
 *   SP500 + SPY  → Finnhub free tier  (60 calls/minute, US stocks only)
 *   TSX60        → Yahoo Finance v8   (no API key, free, supports .TO tickers)
 *
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
 * ~501 US tickers takes ~9 minutes. Running hourly keeps data fresh
 * throughout the trading day without hammering the API.
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$apiKey    = $db->getSetting('finnhub_api_key');
$today     = date('Y-m-d');
$startTime = microtime(true);

if (!$apiKey || $apiKey === 'YOUR_KEY_HERE') {
    cronLog('ERROR', 'Finnhub API key not configured in settings table.');
    exit(1);
}

// ---------------------------------------------------------------------------
// Get ticker list, split by data source
// ---------------------------------------------------------------------------
$tickers = $db->fetchAll("SELECT ticker, exchange FROM stocks WHERE is_active = 1 ORDER BY ticker");

// Build exchange map for per-exchange logging
$exchangeMap = [];
foreach ($tickers as $row) {
    $exchangeMap[$row['ticker']] = $row['exchange'];
}
$exchangeMap['SPY'] = 'BENCHMARK';

// Split into Finnhub group (SP500 + SPY) and Yahoo group (TSX60)
$finnhubTickers = array_column(
    array_filter($tickers, fn($r) => $r['exchange'] === 'SP500'),
    'ticker'
);
// SPY benchmark — always via Finnhub
if (!in_array('SPY', $finnhubTickers)) {
    $finnhubTickers[] = 'SPY';
}

$yahooTickers = array_column(
    array_filter($tickers, fn($r) => $r['exchange'] === 'TSX60'),
    'ticker'
);

$usCount = count($finnhubTickers) - 1; // minus SPY
$caCount = count($yahooTickers);
$total   = count($finnhubTickers) + $caCount;

$success     = 0;
$skipped     = 0;
$failed      = 0;

cronLog('INFO', "Starting hourly price update for {$total} tickers (US/SP500={$usCount}, CA/TSX60={$caCount}, +SPY benchmark).");

// ---------------------------------------------------------------------------
// SECTION 1 — Finnhub (SP500 + SPY) — rate limit to 55 calls/minute
// ---------------------------------------------------------------------------
$callCount   = 0;
$minuteStart = microtime(true);

foreach ($finnhubTickers as $ticker) {
    $callCount++;
    if ($callCount % 55 === 0) {
        $elapsed = microtime(true) - $minuteStart;
        if ($elapsed < 60) {
            $sleep = (int)ceil(60 - $elapsed) + 2;
            cronLog('INFO', "Rate limit pause: {$sleep}s after {$callCount} Finnhub calls.");
            sleep($sleep);
        }
        $minuteStart = microtime(true);
    }

    $price = finnhubQuote($ticker, $apiKey);

    if ($price === false) {
        $exch = $exchangeMap[$ticker] ?? '?';
        cronLog('WARN', "No data for {$ticker} [{$exch}]");
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

cronLog('INFO', "Finnhub pass complete: {$callCount} calls.");

// ---------------------------------------------------------------------------
// SECTION 2 — Yahoo Finance (TSX60) — courtesy delay between calls
// ---------------------------------------------------------------------------
if (!empty($yahooTickers)) {
    cronLog('INFO', "Starting Yahoo Finance pass for {$caCount} TSX60 tickers.");
    $yaSuccess = 0;
    $yaFailed  = 0;

    foreach ($yahooTickers as $i => $ticker) {
        // Small courtesy delay — 200ms between calls
        if ($i > 0) {
            usleep(200000);
        }

        $price = yahooQuote($ticker);

        if ($price === false) {
            cronLog('WARN', "No data for {$ticker} [TSX60]");
            $failed++;
            $yaFailed++;
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
        $yaSuccess++;
    }

    cronLog('INFO', "Yahoo Finance pass complete: {$yaSuccess} ok / {$yaFailed} failed.");
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
// Update timestamp
// ---------------------------------------------------------------------------
$db->setSetting('portfolio_last_price_update', date('Y-m-d H:i:s'));

$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "Done in {$elapsed}s — prices: {$success} ok / {$skipped} zero / {$failed} failed (of {$total} tickers: {$usCount} SP500 + {$caCount} TSX60 + 1 SPY). Snapshots: {$snapshots}.");

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

/**
 * Fetch previous close from Finnhub (US stocks, free tier).
 * Returns price as float, or false on any failure.
 */
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

/**
 * Fetch previous close from Yahoo Finance v8 (Canadian .TO tickers, free).
 * Uses chartPreviousClose from the meta object.
 * Returns price as float, or false on any failure.
 */
function yahooQuote(string $ticker): float|false {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
         . urlencode($ticker) . '?interval=1d&range=1d';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; LotGD-PriceBot/1.0)',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return false;
    $data = json_decode($raw, true);

    // chart.result[0].meta.chartPreviousClose
    $price = $data['chart']['result'][0]['meta']['chartPreviousClose'] ?? null;
    if ($price === null) {
        // Fallback: regularMarketPreviousClose
        $price = $data['chart']['result'][0]['meta']['regularMarketPreviousClose'] ?? null;
    }
    if ($price === null) return false;
    return (float)$price;
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
