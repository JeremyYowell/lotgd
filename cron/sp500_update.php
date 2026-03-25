#!/usr/bin/env php
<?php
/**
 * cron/sp500_update.php — Quarterly index constituent update
 * ===========================================================
 * Scrapes Wikipedia for BOTH S&P 500 (US) and S&P/TSX 60 (Canada)
 * and syncs the stocks table. Each index is managed independently —
 * deactivation is scoped per exchange so a TSX 60 run never touches
 * S&P 500 rows and vice versa.
 *
 * Sources:
 *   S&P 500 : https://en.wikipedia.org/wiki/List_of_S%26P_500_companies
 *   TSX 60  : https://en.wikipedia.org/wiki/S%26P/TSX_60
 *
 * Finnhub ticker conventions:
 *   US   stocks  → plain ticker (AAPL, BRK-B)
 *   CA   stocks  → TICKER.TO  (RY.TO, TD.TO)
 *
 * DreamHost cron — quarterly (first business day of each quarter):
 *   0 7 1,2 1,4,7,10 1-5  /usr/local/php81/bin/php /path/cron/sp500_update.php >> /path/logs/cron_sp500.log 2>&1
 *
 * NOTE: Safe to run manually at any time.
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$startTime = microtime(true);
cronLog('INFO', '=== Starting quarterly index constituent update ===');

// ============================================================================
// SECTION 1 — S&P 500
// ============================================================================
cronLog('INFO', '--- S&P 500 ---');

$sp500Html = fetchWikipediaPage(
    'https://en.wikipedia.org/wiki/List_of_S%26P_500_companies',
    'S&P 500'
);

if ($sp500Html) {
    $sp500 = parseWikitable($sp500Html, function (array $data): ?array {
        $ticker = preg_replace('/\[.*?\]/', '', $data['Symbol'] ?? $data['Ticker symbol'] ?? '');
        $ticker = trim(str_replace('.', '-', $ticker));   // BRK.B → BRK-B (Finnhub US convention)
        if (empty($ticker)) return null;
        return [
            'ticker'       => strtoupper($ticker),
            'company_name' => $data['Security'] ?? $data['Company'] ?? 'Unknown',
            'sector'       => $data['GICS Sector']        ?? $data['Sector']       ?? null,
            'sub_industry' => $data['GICS Sub-Industry']  ?? $data['Sub-Industry'] ?? null,
            'exchange'     => 'SP500',
        ];
    }, tableIndex: 0);

    if (count($sp500) >= 400) {
        cronLog('INFO', 'Parsed ' . count($sp500) . ' S&P 500 tickers.');
        $r = syncStocks($sp500, 'SP500');
        cronLog('INFO', "S&P 500 sync: added={$r['added']} updated={$r['updated']} deactivated={$r['deactivated']}");
        $db->setSetting('portfolio_last_sp500_update', date('Y-m-d H:i:s'));
    } else {
        cronLog('ERROR', 'Only parsed ' . count($sp500) . ' S&P 500 tickers — expected ≥400. Skipping to protect data.');
    }
} else {
    cronLog('ERROR', 'Skipping S&P 500 sync due to fetch failure.');
}

// ============================================================================
// SECTION 2 — S&P/TSX 60
// ============================================================================
cronLog('INFO', '--- S&P/TSX 60 ---');

$tsx60Html = fetchWikipediaPage(
    'https://en.wikipedia.org/wiki/S%26P/TSX_60',
    'TSX 60'
);

if ($tsx60Html) {
    $tsx60 = parseWikitable($tsx60Html, function (array $data): ?array {
        // Wikipedia TSX 60 table uses 'Ticker' or 'Symbol' — no .TO suffix on the page
        $ticker = preg_replace('/\[.*?\]/', '',
            $data['Ticker']         ??
            $data['Symbol']         ??
            $data['Ticker symbol']  ??
            $data['TSX Symbol']     ??
            $data['TSX ticker']     ??
            ''
        );
        $ticker = strtoupper(trim($ticker));
        if (empty($ticker)) return null;

        // Append .TO for Finnhub TSX convention (never double-append)
        if (!str_ends_with($ticker, '.TO')) {
            $ticker .= '.TO';
        }

        return [
            'ticker'       => $ticker,
            'company_name' => $data['Company']           ?? $data['Security']        ?? $data['Name'] ?? 'Unknown',
            'sector'       => $data['GICS Sector']        ?? $data['Sector']          ?? null,
            'sub_industry' => $data['GICS Sub-Industry']  ?? $data['Sub-Industry']    ?? null,
            'exchange'     => 'TSX60',
        ];
    }, tableIndex: 0, fallbackTableIndex: 1);

    if (count($tsx60) >= 50) {
        cronLog('INFO', 'Parsed ' . count($tsx60) . ' TSX 60 tickers.');
        $r = syncStocks($tsx60, 'TSX60');
        cronLog('INFO', "TSX 60 sync: added={$r['added']} updated={$r['updated']} deactivated={$r['deactivated']}");
        $db->setSetting('portfolio_last_tsx60_update', date('Y-m-d H:i:s'));
    } else {
        cronLog('ERROR', 'Only parsed ' . count($tsx60) . ' TSX 60 tickers — expected ≥50. Skipping to protect data.');
        cronLog('INFO', 'Sample parsed rows: ' . json_encode(array_slice($tsx60, 0, 3)));
    }
} else {
    cronLog('ERROR', 'Skipping TSX 60 sync due to fetch failure.');
}

// ============================================================================
// DONE
// ============================================================================
$elapsed = round(microtime(true) - $startTime, 1);
cronLog('INFO', "=== Index update complete in {$elapsed}s ===");

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Fetch a Wikipedia page, return HTML string or false on failure.
 */
function fetchWikipediaPage(string $url, string $label): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: LotGD-IndexUpdater/1.0 (educational finance game; contact via site)',
        ],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $code !== 200) {
        cronLog('ERROR', "Failed to fetch {$label} from Wikipedia. HTTP {$code}");
        return false;
    }
    return $html;
}

/**
 * Parse the first (or fallback) wikitable from Wikipedia HTML.
 * $rowMapper is a callable that receives a row's associative data and returns
 * a stock array or null to skip the row.
 */
function parseWikitable(
    string   $html,
    callable $rowMapper,
    int      $tableIndex        = 0,
    int      $fallbackTableIndex = -1
): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath  = new DOMXPath($dom);
    $tables = $xpath->query("//table[contains(@class,'wikitable')]");

    if ($tables->length === 0) {
        cronLog('ERROR', 'No wikitable found on Wikipedia page.');
        return [];
    }

    // Try primary table index first, fall back to alternate if result is too small
    $candidates = [$tableIndex];
    if ($fallbackTableIndex >= 0 && $fallbackTableIndex !== $tableIndex) {
        $candidates[] = $fallbackTableIndex;
    }

    $best = [];
    foreach ($candidates as $idx) {
        if ($idx >= $tables->length) continue;
        $rows    = $xpath->query('.//tr', $tables->item($idx));
        $headers = [];
        $parsed  = [];

        foreach ($rows as $i => $row) {
            $cells = $xpath->query('.//th|.//td', $row);
            if ($i === 0) {
                foreach ($cells as $j => $cell) {
                    $headers[$j] = trim($cell->textContent);
                }
                continue;
            }
            $data = [];
            foreach ($cells as $j => $cell) {
                $data[$headers[$j] ?? $j] = trim($cell->textContent);
            }
            $stock = $rowMapper($data);
            if ($stock !== null) {
                $parsed[] = $stock;
            }
        }

        if (count($parsed) > count($best)) {
            $best = $parsed;
        }
    }

    return $best;
}

/**
 * Sync a parsed stock list into the DB, scoped to a single exchange.
 * Only stocks for $exchange are considered for deactivation — stocks from
 * other exchanges are never touched.
 *
 * Returns ['added' => int, 'updated' => int, 'deactivated' => int]
 */
function syncStocks(array $parsed, string $exchange): array {
    global $db;
    $today    = date('Y-m-d');
    $added    = 0;
    $updated  = 0;
    $deactivated = 0;

    $newTickers = array_column($parsed, 'ticker');

    // Active tickers in DB for THIS exchange only
    $existingRows = $db->fetchAll(
        "SELECT ticker FROM stocks WHERE is_active = 1 AND exchange = ?",
        [$exchange]
    );
    $existingTickers = array_column($existingRows, 'ticker');

    // Add or update
    foreach ($parsed as $stock) {
        $existing = $db->fetchOne(
            "SELECT ticker, is_active FROM stocks WHERE ticker = ?",
            [$stock['ticker']]
        );

        if (!$existing) {
            $db->run(
                "INSERT INTO stocks
                     (ticker, company_name, sector, sub_industry, exchange, is_active, added_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?)",
                [
                    $stock['ticker'],
                    $stock['company_name'],
                    $stock['sector'],
                    $stock['sub_industry'],
                    $stock['exchange'],
                    $today,
                ]
            );
            cronLog('INFO', "  Added [{$exchange}]: {$stock['ticker']} — {$stock['company_name']}");
            $added++;
        } else {
            $reactivate = !$existing['is_active'] ? ', is_active = 1, removed_at = NULL' : '';
            $db->run(
                "UPDATE stocks
                 SET company_name = ?, sector = ?, sub_industry = ?, exchange = ? {$reactivate}
                 WHERE ticker = ?",
                [
                    $stock['company_name'],
                    $stock['sector'],
                    $stock['sub_industry'],
                    $stock['exchange'],
                    $stock['ticker'],
                ]
            );
            if (!$existing['is_active']) {
                cronLog('INFO', "  Reactivated [{$exchange}]: {$stock['ticker']}");
            }
            $updated++;
        }
    }

    // Deactivate tickers no longer in THIS exchange's index
    $removed = array_diff($existingTickers, $newTickers);
    foreach ($removed as $ticker) {
        $db->run(
            "UPDATE stocks SET is_active = 0, removed_at = ? WHERE ticker = ? AND exchange = ?",
            [$today, $ticker, $exchange]
        );
        cronLog('INFO', "  Deactivated [{$exchange}]: {$ticker}");
        $deactivated++;
    }

    return compact('added', 'updated', 'deactivated');
}

function cronLog(string $level, string $message): void {
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    echo $line;
    file_put_contents(ROOT_PATH . '/logs/cron_sp500.log', $line, FILE_APPEND | LOCK_EX);
}
