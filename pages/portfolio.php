<?php
/**
 * pages/portfolio.php — Simulated Portfolio Module
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();

if (!(bool)$db->getSetting('portfolio_enabled', '1')) {
    Session::setFlash('info', 'The portfolio module is not yet available.');
    redirect('/pages/dashboard.php');
}

$userModel = new User();
$portfolio = new Portfolio();
$userId    = Session::userId();
$user      = $userModel->findById($userId);

$goldToUsd = (int)$db->getSetting('gold_to_usd_rate', 1000);

// =========================================================================
// HANDLE POST ACTIONS
// =========================================================================
$tradeResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::verifyCsrfPost();

    $action = $_POST['action'] ?? '';

    if ($action === 'buy') {
        $ticker     = strtoupper(trim($_POST['ticker'] ?? ''));
        $goldAmount = (float)($_POST['gold_amount'] ?? 0);

        if (empty($ticker))       Session::setFlash('error', 'Please enter a ticker symbol.');
        elseif ($goldAmount <= 0) Session::setFlash('error', 'Please enter a Gold amount to invest.');
        else {
            $result = $portfolio->buy($userId, $ticker, $goldAmount);
            if ($result['success']) {
                $tradeResult = array_merge($result, ['type' => 'buy', 'ticker' => $ticker]);
                Session::setFlash('success',
                    "Bought {$result['shares']} shares of {$ticker} @ \${$result['price']} "
                    . "(as of {$result['as_of_date']}). {$goldAmount} Gold invested."
                );
            } else {
                Session::setFlash('error', $result['error']);
            }
        }
    }

    if ($action === 'sell') {
        $ticker       = strtoupper(trim($_POST['ticker'] ?? ''));
        $sellAll      = !empty($_POST['sell_all']);
        $sharesToSell = $sellAll ? null : (float)($_POST['shares_to_sell'] ?? 0);

        if (empty($ticker)) {
            Session::setFlash('error', 'Please enter a ticker symbol.');
        } else {
            $result = $portfolio->sell($userId, $ticker, $sharesToSell);
            if ($result['success']) {
                $tradeResult = array_merge($result, ['type' => 'sell', 'ticker' => $ticker]);
                Session::setFlash('success',
                    "Sold {$result['shares_sold']} shares of {$ticker} @ \${$result['price']}. "
                    . "You received {$result['gold_received']} Gold."
                );
            } else {
                Session::setFlash('error', $result['error']);
            }
        }
    }

    $user = $userModel->findById($userId);
    redirect('/pages/portfolio.php');
}

// =========================================================================
// DATA FOR DISPLAY
// =========================================================================
$holdings      = $portfolio->getHoldings($userId);
$tradeHistory  = $portfolio->getTradeHistory($userId, 15);
$totalValueUsd = $portfolio->getTotalValue($userId);
$costBasisUsd  = $portfolio->getTotalCostBasis($userId);
$totalGoldEq   = $portfolio->usdToGold($totalValueUsd);

$overallReturn = $costBasisUsd > 0
    ? round((($totalValueUsd - $costBasisUsd) / $costBasisUsd) * 100, 2)
    : 0;

$spxRow         = $portfolio->getLatestSpxPrice();
$inceptionDate  = $db->getSetting('spx_inception_date', '');
$inceptionPrice = (float)$db->getSetting('spx_inception_price', 0);
$spxReturn      = 0;
if ($spxRow && $inceptionPrice > 0) {
    $spxReturn = round((((float)$spxRow['close_price'] - $inceptionPrice) / $inceptionPrice) * 100, 2);
}

$lastPriceUpdate = $db->getSetting('portfolio_last_price_update', '');
$priceAsOf       = $lastPriceUpdate ? date('M j, Y', strtotime($lastPriceUpdate)) : 'Not yet updated';

$lbTop = $db->fetchAll(
    "SELECT * FROM portfolio_leaderboard_cache ORDER BY position ASC LIMIT 10"
);
$lbBottom = $db->fetchAll(
    "SELECT * FROM portfolio_leaderboard_cache ORDER BY pct_return ASC LIMIT 10"
);

$classIcons = [
    'investor'    => '📈', 'debt_slayer' => '🗡️',
    'saver'       => '🏦', 'entrepreneur'=> '🚀', 'minimalist' => '🧘',
];

// =========================================================================
// RENDER
// =========================================================================
$pageTitle = 'Portfolio';
$bodyClass = 'page-portfolio';
$extraCss  = ['portfolio.css'];

ob_start();
?>

<div class="port-wrap">

    <div class="port-header">
        <div>
            <h1>📊 Your Portfolio</h1>
            <p class="text-muted">
                Prices as of: <strong><?= e($priceAsOf) ?></strong>
                &nbsp;·&nbsp; 1 Gold = $<?= number_format($goldToUsd) ?> USD
            </p>
        </div>
        <div class="port-header-gold">
            <span class="port-gold-label">Available Gold</span>
            <span class="port-gold-val text-gold"><?= number_format((float)$user['gold'], 2) ?></span>
        </div>
    </div>

    <div class="port-summary">
        <div class="psum-stat">
            <span class="psum-label">Portfolio Value</span>
            <span class="psum-val"><?= empty($holdings) ? '—' : '$' . number_format($totalValueUsd, 2) ?></span>
        </div>
        <div class="psum-stat">
            <span class="psum-label">Cost Basis</span>
            <span class="psum-val"><?= empty($holdings) ? '—' : '$' . number_format($costBasisUsd, 2) ?></span>
        </div>
        <div class="psum-stat">
            <span class="psum-label">Your Return</span>
            <span class="psum-val <?= $overallReturn >= 0 ? 'text-green' : 'text-red' ?>">
                <?= $overallReturn >= 0 ? '+' : '' ?><?= $overallReturn ?>%
            </span>
        </div>
        <div class="psum-stat">
            <span class="psum-label">S&P 500 Return</span>
            <span class="psum-val <?= $spxReturn >= 0 ? 'text-green' : 'text-red' ?>">
                <?= $spxReturn >= 0 ? '+' : '' ?><?= $spxReturn ?>%
                <?php if ($spxRow): ?>
                <small class="text-muted">(<?= $spxRow['price_date'] ?>)</small>
                <?php endif; ?>
            </span>
        </div>
        <div class="psum-stat">
            <span class="psum-label">vs Index</span>
            <?php
            $diff       = round($overallReturn - $spxReturn, 2);
            $beatsIndex = !empty($holdings) && $overallReturn > $spxReturn;
            ?>
            <span class="psum-val <?= $beatsIndex ? 'text-green' : 'text-red' ?>">
                <?= $beatsIndex ? '🏆 Beating' : '📉 Behind' ?>
                <?= !empty($holdings) ? '(' . ($diff >= 0 ? '+' : '') . $diff . '%)' : '' ?>
            </span>
        </div>
    </div>

    <div class="port-layout">

        <div class="port-main">

            <div class="card port-holdings">
                <div class="card-header-row mb-3">
                    <h3>📂 Holdings</h3>
                    <span class="text-muted" style="font-size:0.82rem;">
                        <?= count($holdings) ?> position<?= count($holdings) !== 1 ? 's' : '' ?>
                    </span>
                </div>

                <?php if (empty($holdings)): ?>
                <div class="port-empty">
                    <p>You have no open positions. Use Gold to buy your first stock below!</p>
                </div>
                <?php else: ?>
                <div class="holdings-table-wrap">
                    <table class="holdings-table">
                        <thead>
                            <tr>
                                <th>Ticker</th>
                                <th>Company</th>
                                <th class="text-right">Shares</th>
                                <th class="text-right">Avg Cost</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Value</th>
                                <th class="text-right">Return</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($holdings as $h):
                                $ret = (float)$h['position_pct_return'];
                            ?>
                            <tr>
                                <td class="ticker-cell"><strong><?= e($h['ticker']) ?></strong></td>
                                <td class="company-cell text-muted"><?= e($h['company_name']) ?></td>
                                <td class="text-right"><?= rtrim(rtrim(number_format((float)$h['shares'], 6), '0'), '.') ?></td>
                                <td class="text-right text-muted">$<?= number_format((float)$h['avg_cost_basis'], 2) ?></td>
                                <td class="text-right">$<?= number_format((float)$h['current_price'], 2) ?></td>
                                <td class="text-right text-gold">$<?= number_format((float)$h['current_value_usd'], 2) ?></td>
                                <td class="text-right <?= $ret >= 0 ? 'text-green' : 'text-red' ?>">
                                    <?= $ret >= 0 ? '+' : '' ?><?= number_format($ret, 2) ?>%
                                </td>
                                <td>
                                    <button class="btn-sell-quick"
                                            onclick="fillSell('<?= e($h['ticker']) ?>', <?= (float)$h['shares'] ?>)">
                                        Sell
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($tradeHistory)): ?>
            <div class="card port-history">
                <h3 class="mb-3">🕐 Recent Trades</h3>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Ticker</th>
                            <th class="text-right">Shares</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Gold</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tradeHistory as $t): ?>
                        <tr>
                            <td class="text-muted" style="font-size:0.8rem;">
                                <?= date('M j', strtotime($t['traded_at'])) ?>
                            </td>
                            <td>
                                <span class="trade-type-badge <?= $t['trade_type'] === 'buy' ? 'buy-badge' : 'sell-badge' ?>">
                                    <?= strtoupper($t['trade_type']) ?>
                                </span>
                            </td>
                            <td><strong><?= e($t['ticker']) ?></strong></td>
                            <td class="text-right text-muted">
                                <?= rtrim(rtrim(number_format((float)$t['shares'], 6), '0'), '.') ?>
                            </td>
                            <td class="text-right text-muted">$<?= number_format((float)$t['price_per_share'], 2) ?></td>
                            <td class="text-right <?= $t['trade_type'] === 'buy' ? 'text-red' : 'text-green' ?>">
                                <?= $t['trade_type'] === 'buy' ? '-' : '+' ?><?= number_format((float)$t['gold_amount'], 2) ?> 🪙
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>

        <div class="port-sidebar">

            <div class="card trade-card">
                <h3 class="mb-1">⚡ Place a Trade</h3>
                <p class="text-muted mb-3" style="font-size:0.82rem;">
                    Prices are previous market close. Trades execute immediately at that price.
                </p>

                <?= renderFlash() ?>

                <div class="trade-tabs">
                    <button class="trade-tab active" id="tab-buy" onclick="switchTab('buy')">Buy</button>
                    <button class="trade-tab" id="tab-sell" onclick="switchTab('sell')">Sell</button>
                </div>

                <form method="POST" id="form-buy" class="trade-form">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="buy">

                    <div class="form-group">
                        <label for="buy-ticker">Ticker Symbol</label>
                        <div class="ticker-input-wrap">
                            <input type="text" id="buy-ticker" name="ticker"
                                   placeholder="e.g. AAPL, MSFT, NVDA"
                                   maxlength="10" autocomplete="off"
                                   oninput="this.value=this.value.toUpperCase()"
                                   required>
                        </div>
                        <div id="ticker-info" class="ticker-info"></div>
                    </div>

                    <div class="form-group">
                        <label for="gold-amount">Gold to Invest</label>
                        <input type="number" id="gold-amount" name="gold_amount"
                               min="0.01" step="0.01"
                               max="<?= (float)$user['gold'] ?>"
                               placeholder="0.00" required
                               oninput="updateBuyPreview()">
                        <div class="form-hint">
                            Available: <strong class="text-gold"><?= number_format((float)$user['gold'], 2) ?></strong> Gold
                        </div>
                        <div id="buy-preview" class="trade-preview"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full">Buy Shares</button>
                </form>

                <form method="POST" id="form-sell" class="trade-form" style="display:none">
                    <?= Session::csrfField() ?>
                    <input type="hidden" name="action" value="sell">

                    <div class="form-group">
                        <label for="sell-ticker">Ticker Symbol</label>
                        <input type="text" id="sell-ticker" name="ticker"
                               placeholder="e.g. AAPL"
                               maxlength="10" autocomplete="off"
                               oninput="this.value=this.value.toUpperCase();updateSellInfo()"
                               required>
                        <div id="sell-ticker-info" class="ticker-info"></div>
                    </div>

                    <div class="form-group">
                        <label for="shares-to-sell">Shares to Sell</label>
                        <input type="number" id="shares-to-sell" name="shares_to_sell"
                               min="0.000001" step="0.000001"
                               placeholder="0.000000">
                        <div class="form-hint">Leave blank to sell entire position.</div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label" style="margin-top:0">
                            <input type="checkbox" name="sell_all" id="sell-all"
                                   onchange="document.getElementById('shares-to-sell').disabled=this.checked">
                            Sell entire position
                        </label>
                    </div>

                    <button type="submit" class="btn btn-danger btn-full">Sell Shares</button>
                </form>
            </div>

            <div class="card search-card">
                <h3 class="mb-2">🔍 Stock Search</h3>
                <input type="text" id="stock-search" placeholder="Search ticker or company name…"
                       oninput="liveSearch(this.value)" autocomplete="off">
                <div id="search-results" class="search-results mt-2"></div>
            </div>

            <div class="card lb-mini-card">
                <h3 class="mb-1">🏆 Top Performers</h3>
                <p class="text-muted mb-2" style="font-size:0.78rem;">Ranked by % return since first trade</p>

                <?php if (empty($lbTop)): ?>
                <p class="text-muted" style="font-size:0.85rem;">No data yet — be the first investor!</p>
                <?php else: ?>
                <table class="lb-mini-table">
                    <tbody>
                    <?php foreach ($lbTop as $row):
                        $isMe = ((int)$row['user_id'] === $userId);
                        $ret  = (float)$row['pct_return'];
                    ?>
                    <tr class="<?= $isMe ? 'lb-me' : '' ?>">
                        <td class="lbm-pos">#<?= $row['position'] ?></td>
                        <td class="lbm-name">
                            <?= $classIcons[$row['class']] ?? '⚔️' ?>
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($row['username']) ?>"
                               style="color:var(--color-gold-light);text-decoration:none">
                                <?= e($row['username']) ?>
                            </a>
                            <?= $isMe ? '<span class="you-tag">you</span>' : '' ?>
                        </td>
                        <td class="lbm-ret <?= $ret >= 0 ? 'text-green' : 'text-red' ?>">
                            <?= $ret >= 0 ? '+' : '' ?><?= number_format($ret, 2) ?>%
                            <?= $row['beats_index'] ? '🏆' : '' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($lbBottom)): ?>
                <h3 class="mt-3 mb-1" style="font-size:0.95rem;">🏚 The Dungeon</h3>
                <p class="text-muted mb-2" style="font-size:0.78rem;">Bottom performers</p>
                <table class="lb-mini-table">
                    <tbody>
                    <?php foreach (array_reverse($lbBottom) as $row):
                        $isMe = ((int)$row['user_id'] === $userId);
                        $ret  = (float)$row['pct_return'];
                    ?>
                    <tr class="<?= $isMe ? 'lb-me' : '' ?>">
                        <td class="lbm-pos text-muted">💀</td>
                        <td class="lbm-name">
                            <?= $classIcons[$row['class']] ?? '⚔️' ?>
                            <a href="<?= BASE_URL ?>/pages/profile.php?user=<?= urlencode($row['username']) ?>"
                               style="color:var(--color-gold-light);text-decoration:none">
                                <?= e($row['username']) ?>
                            </a>
                            <?= $isMe ? '<span class="you-tag">you</span>' : '' ?>
                        </td>
                        <td class="lbm-ret text-red"><?= number_format($ret, 2) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>

<?php
$pageContent = ob_get_clean();

$goldToUsdJs  = (int)$goldToUsd;
$userGoldJs   = (float)$user['gold'];
$holdingsJson = json_encode(array_column($holdings, null, 'ticker'));
$searchUrl    = BASE_URL . '/api/stock_search.php';

$extraScripts = '<script>' . "\n"
    . 'const goldToUsd = ' . $goldToUsdJs . ';' . "\n"
    . 'const userGold  = ' . $userGoldJs  . ';' . "\n"
    . 'const holdings  = ' . $holdingsJson . ';' . "\n"
    . 'function switchTab(tab) {' . "\n"
    . '    document.getElementById("form-buy").style.display  = tab === "buy"  ? "" : "none";' . "\n"
    . '    document.getElementById("form-sell").style.display = tab === "sell" ? "" : "none";' . "\n"
    . '    document.getElementById("tab-buy").classList.toggle("active",  tab === "buy");' . "\n"
    . '    document.getElementById("tab-sell").classList.toggle("active", tab === "sell");' . "\n"
    . '}' . "\n"
    . 'function fillSell(ticker, shares) {' . "\n"
    . '    switchTab("sell");' . "\n"
    . '    document.getElementById("sell-ticker").value = ticker;' . "\n"
    . '    updateSellInfo();' . "\n"
    . '}' . "\n"
    . 'function updateBuyPreview() {' . "\n"
    . '    const gold = parseFloat(document.getElementById("gold-amount").value) || 0;' . "\n"
    . '    const prev = document.getElementById("buy-preview");' . "\n"
    . '    if (!gold || gold <= 0) { prev.textContent = ""; return; }' . "\n"
    . '    const usd = gold * goldToUsd;' . "\n"
    . '    prev.textContent = "\u2248 $" + usd.toLocaleString("en-US", {minimumFractionDigits:2, maximumFractionDigits:2}) + " USD to invest";' . "\n"
    . '}' . "\n"
    . 'function updateSellInfo() {' . "\n"
    . '    const ticker = document.getElementById("sell-ticker").value.trim().toUpperCase();' . "\n"
    . '    const info   = document.getElementById("sell-ticker-info");' . "\n"
    . '    if (!ticker) { info.textContent = ""; return; }' . "\n"
    . '    const h = holdings[ticker];' . "\n"
    . '    if (h) {' . "\n"
    . '        info.innerHTML = "You hold <strong>" + h.shares + "</strong> shares \u00b7 "' . "\n"
    . '            + "Current value: <strong>$" + parseFloat(h.current_value_usd).toLocaleString("en-US", {minimumFractionDigits:2}) + "</strong>";' . "\n"
    . '    } else {' . "\n"
    . '        info.textContent = "No position in " + ticker;' . "\n"
    . '    }' . "\n"
    . '}' . "\n"
    . 'let searchTimer;' . "\n"
    . 'function liveSearch(q) {' . "\n"
    . '    clearTimeout(searchTimer);' . "\n"
    . '    const out = document.getElementById("search-results");' . "\n"
    . '    if (q.length < 1) { out.innerHTML = ""; return; }' . "\n"
    . '    searchTimer = setTimeout(() => {' . "\n"
    . '        fetch("' . $searchUrl . '?q=" + encodeURIComponent(q))' . "\n"
    . '            .then(r => r.json())' . "\n"
    . '            .then(results => {' . "\n"
    . '                if (!results.length) { out.innerHTML = "<p class=\"text-muted\" style=\"font-size:0.85rem;padding:0.5rem\">No results.</p>"; return; }' . "\n"
    . '                out.innerHTML = results.map(s =>' . "\n"
    . '                    "<div class=\"search-row\" onclick=\"document.getElementById(\'buy-ticker\').value=\'" + s.ticker + "\';switchTab(\'buy\')\">"' . "\n"
    . '                    + "<strong>" + s.ticker + "</strong> "' . "\n"
    . '                    + "<span class=\"text-muted\">" + s.company_name + "</span>"' . "\n"
    . '                    + (s.latest_price ? " <span class=\"text-gold\">$" + parseFloat(s.latest_price).toFixed(2) + "</span>" : "")' . "\n"
    . '                    + "</div>"' . "\n"
    . '                ).join("");' . "\n"
    . '            })' . "\n"
    . '            .catch(() => { out.innerHTML = ""; });' . "\n"
    . '    }, 300);' . "\n"
    . '}' . "\n"
    . '</script>';

require TPL_PATH . '/layout.php';
?>
