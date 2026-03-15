<?php
/**
 * api/stock_search.php — JSON endpoint for live stock search
 * Used by the portfolio page autocomplete.
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$portfolio = new Portfolio();
$results   = $portfolio->searchStocks($q, 8);

// Attach latest price to each result
foreach ($results as &$r) {
    $price = $portfolio->getLatestPrice($r['ticker']);
    $r['latest_price']  = $price ? $price['close_price'] : null;
    $r['price_date']    = $price ? $price['price_date']  : null;
}

echo json_encode(array_values($results));
