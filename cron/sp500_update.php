#!/usr/bin/env php
<?php
/**
 * cron/sp500_update.php — Quarterly S&P 500 constituent list update
 * ==================================================================
 * Scrapes the Wikipedia S&P 500 holdings table and syncs the
 * stocks table (adds new entrants, marks removed stocks inactive).
 *
 * DreamHost cron setup:
 *   Command: /usr/bin/php /home/USERNAME/DOMAIN/cron/sp500_update.php
 *   Schedule: First business day of each quarter — set 4 cron entries:
 *
 *   January  (run Jan 2 in case Jan 1 is holiday):
 *     0 7 2 1 1-5 /usr/bin/php /path/cron/sp500_update.php
 *   April:
 *     0 7 1 4 1-5 /usr/bin/php /path/cron/sp500_update.php
 *   July:
 *     0 7 1 7 1-5 /usr/bin/php /path/cron/sp500_update.php
 *   October:
 *     0 7 1 10 1-5 /usr/bin/php /path/cron/sp500_update.php
 *
 * NOTE: This script can also be run manually at any time safely.
 *       Run it once immediately after deploying to populate the stocks table.
 *
 * Source: https://en.wikipedia.org/wiki/List_of_S%26P_500_companies
 * The Wikipedia table columns are:
 *   Symbol | Security | GICS Sector | GICS Sub-Industry | ...
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$startTime = microtime(true);
cronLog('INFO', 'Starting S&P 500 constituent update from Wikipedia.');

// ---------------------------------------------------------------------------
// Fetch Wikipedia page
// ---------------------------------------------------------------------------
$url = 'https://en.wikipedia.org/wiki/List_of_S%26P_500_companies';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: LotGD-SP500-Updater/1.0 (educational finance game; contact via site)',
    ],
]);

$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $code !== 200) {
    cronLog('ERROR', "Failed to fetch Wikipedia page. HTTP {$code}");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse the first wikitable (the S&P 500 constituents table)
// ---------------------------------------------------------------------------
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

$xpath  = new DOMXPath($dom);
$tables = $xpath->query("//table[contains(@class,'wikitable')]");

if ($tables->length === 0) {
    cronLog('ERROR', 'Could not find wikitable on Wikipedia page. Page structure may have changed.');
    exit(1);
}

$table = $tables->item(0);
$rows  = $xpath->query('.//tr', $table);

$parsed  = [];
$headers = [];

foreach ($rows as $i => $row) {
    $cells = $xpath->query('.//th|.//td', $row);
    if ($i === 0) {
        // Header row — map column names
        foreach ($cells as $j => $cell) {
            $headers[$j] = trim($cell->textContent);
        }
        continue;
    }

    $data = [];
    foreach ($cells as $j => $cell) {
        $data[$headers[$j] ?? $j] = trim($cell->textContent);
    }

    // Extract ticker — Wikipedia uses 'Symbol' column
    // Some tickers have footnote markers like BRK.B[note] — clean them
    $ticker = preg_replace('/\[.*?\]/', '', $data['Symbol'] ?? $data['Ticker symbol'] ?? '');
    $ticker = trim(str_replace('.', '-', $ticker));  // BRK.B → BRK-B (Finnhub convention)

    if (empty($ticker)) continue;

    $parsed[] = [
        'ticker'       => strtoupper($ticker),
        'company_name' => $data['Security'] ?? $data['Company'] ?? 'Unknown',
        'sector'       => $data['GICS Sector'] ?? $data['Sector'] ?? null,
        'sub_industry' => $data['GICS Sub-Industry'] ?? $data['Sub-Industry'] ?? null,
    ];
}

if (count($parsed) < 400) {
    cronLog('ERROR', 'Only parsed ' . count($parsed) . ' tickers — expected ~500. Aborting to avoid data loss.');
    exit(1);
}

cronLog('INFO', 'Parsed ' . count($parsed) . ' tickers from Wikipedia.');

// ---------------------------------------------------------------------------
// Sync to database
// ---------------------------------------------------------------------------
$today         = date('Y-m-d');
$newTickers    = array_column($parsed, 'ticker');
$added         = 0;
$updated       = 0;
$deactivated   = 0;

// Get current active tickers from DB
$existingActive = $db->fetchAll(
    "SELECT ticker FROM stocks WHERE is_active = 1"
);
$existingActiveTickers = array_column($existingActive, 'ticker');

// Add new / update existing
foreach ($parsed as $stock) {
    $existing = $db->fetchOne(
        "SELECT ticker, is_active FROM stocks WHERE ticker = ?", [$stock['ticker']]
    );

    if (!$existing) {
        // Brand new ticker
        $db->run(
            "INSERT INTO stocks (ticker, company_name, sector, sub_industry, is_active, added_at)
             VALUES (?, ?, ?, ?, 1, ?)",
            [$stock['ticker'], $stock['company_name'], $stock['sector'], $stock['sub_industry'], $today]
        );
        cronLog('INFO', "Added: {$stock['ticker']} — {$stock['company_name']}");
        $added++;
    } else {
        // Update name/sector in case they changed, reactivate if was removed
        $reactivated = !$existing['is_active'] ? ', is_active = 1, removed_at = NULL' : '';
        $db->run(
            "UPDATE stocks
             SET company_name = ?, sector = ?, sub_industry = ? {$reactivated}
             WHERE ticker = ?",
            [$stock['company_name'], $stock['sector'], $stock['sub_industry'], $stock['ticker']]
        );
        if (!$existing['is_active']) {
            cronLog('INFO', "Reactivated: {$stock['ticker']}");
        }
        $updated++;
    }
}

// Deactivate tickers no longer in the index
$removed = array_diff($existingActiveTickers, $newTickers);
foreach ($removed as $ticker) {
    $db->run(
        "UPDATE stocks SET is_active = 0, removed_at = ? WHERE ticker = ?",
        [$today, $ticker]
    );
    cronLog('INFO', "Deactivated (removed from S&P 500): {$ticker}");
    $deactivated++;
}

// Update last-run timestamp
$db->setSetting('portfolio_last_sp500_update', date('Y-m-d H:i:s'));

$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "S&P 500 update complete in {$elapsed}s. Added: {$added}, Updated: {$updated}, Deactivated: {$deactivated}");

// ---------------------------------------------------------------------------
// HELPER
// ---------------------------------------------------------------------------
function cronLog(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    echo $line;
    file_put_contents(ROOT_PATH . '/logs/cron_sp500.log', $line, FILE_APPEND | LOCK_EX);
}
