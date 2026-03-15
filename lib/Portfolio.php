<?php
/**
 * lib/Portfolio.php — Portfolio module business logic
 * =====================================================
 * Handles trades, holdings calculations, snapshots,
 * leaderboard cache, and Gold/USD conversion.
 */

class Portfolio {

    private Database $db;
    private int      $goldToUsd;   // 1 Gold = $goldToUsd USD

    public function __construct() {
        $this->db        = Database::getInstance();
        $this->goldToUsd = (int) $this->db->getSetting('gold_to_usd_rate', 1000);
    }

    // =========================================================================
    // CONVERSION HELPERS
    // =========================================================================

    public function goldToUsd(float $gold): float {
        return $gold * $this->goldToUsd;
    }

    public function usdToGold(float $usd): float {
        return $usd / $this->goldToUsd;
    }

    // =========================================================================
    // PRICE LOOKUPS
    // =========================================================================

    /**
     * Get the most recent closing price for a ticker.
     * Returns false if no price data exists.
     */
    public function getLatestPrice(string $ticker): array|false {
        return $this->db->fetchOne(
            "SELECT ticker, price_date, close_price
             FROM stock_prices
             WHERE ticker = ?
             ORDER BY price_date DESC
             LIMIT 1",
            [strtoupper($ticker)]
        );
    }

    /**
     * Get the SPX price on or after a given date (for benchmark comparison).
     */
    public function getSpxPriceOnDate(string $date): float|false {
        $row = $this->db->fetchOne(
            "SELECT close_price FROM stock_prices
             WHERE ticker = '^GSPC' AND price_date >= ?
             ORDER BY price_date ASC LIMIT 1",
            [$date]
        );
        return $row ? (float)$row['close_price'] : false;
    }

    /**
     * Get latest SPX price.
     */
    public function getLatestSpxPrice(): array|false {
        return $this->getLatestPrice('^GSPC');
    }

    // =========================================================================
    // HOLDINGS
    // =========================================================================

    /**
     * Get all open positions for a user (with current prices joined in).
     */
    public function getHoldings(int $userId): array {
        return $this->db->fetchAll(
            "SELECT h.*,
                    s.company_name, s.sector,
                    sp.close_price AS current_price,
                    sp.price_date  AS price_as_of,
                    ROUND(h.shares * sp.close_price, 4)                        AS current_value_usd,
                    ROUND(h.shares * sp.close_price / ?, 4)                    AS current_value_gold,
                    ROUND((sp.close_price - h.avg_cost_basis) / h.avg_cost_basis * 100, 4) AS position_pct_return
             FROM portfolio_holdings h
             JOIN stocks s ON s.ticker = h.ticker
             LEFT JOIN stock_prices sp ON sp.ticker = h.ticker
                 AND sp.price_date = (
                     SELECT MAX(price_date) FROM stock_prices WHERE ticker = h.ticker
                 )
             WHERE h.user_id = ? AND h.shares > 0
             ORDER BY current_value_usd DESC",
            [$this->goldToUsd, $userId]
        );
    }

    /**
     * Get a single holding for a user+ticker.
     */
    public function getHolding(int $userId, string $ticker): array|false {
        return $this->db->fetchOne(
            "SELECT * FROM portfolio_holdings WHERE user_id = ? AND ticker = ?",
            [$userId, strtoupper($ticker)]
        );
    }

    /**
     * Calculate total portfolio value in USD for a user.
     */
    public function getTotalValue(int $userId): float {
        $result = $this->db->fetchValue(
            "SELECT SUM(h.shares * sp.close_price)
             FROM portfolio_holdings h
             LEFT JOIN stock_prices sp ON sp.ticker = h.ticker
                 AND sp.price_date = (
                     SELECT MAX(price_date) FROM stock_prices WHERE ticker = h.ticker
                 )
             WHERE h.user_id = ? AND h.shares > 0",
            [$userId]
        );
        return (float)($result ?? 0);
    }

    /**
     * Get total cost basis in USD for a user.
     */
    public function getTotalCostBasis(int $userId): float {
        $result = $this->db->fetchValue(
            "SELECT SUM(gold_invested) * ? FROM portfolio_holdings WHERE user_id = ? AND shares > 0",
            [$this->goldToUsd, $userId]
        );
        return (float)($result ?? 0);
    }

    // =========================================================================
    // TRADING
    // =========================================================================

    /**
     * Execute a BUY order.
     * Returns ['success' => bool, 'error' => string|null, 'gold_spent' => float]
     */
    public function buy(int $userId, string $ticker, float $goldAmount): array {
        $ticker = strtoupper($ticker);

        // Validate stock exists and is active
        $stock = $this->db->fetchOne(
            "SELECT * FROM stocks WHERE ticker = ? AND is_active = 1", [$ticker]
        );
        if (!$stock) {
            return ['success' => false, 'error' => 'That ticker is not available for trading.'];
        }

        // Get latest price
        $priceRow = $this->getLatestPrice($ticker);
        if (!$priceRow) {
            return ['success' => false, 'error' => 'No price data available for ' . $ticker . '. Try again after the next price update.'];
        }

        $priceUsd    = (float)$priceRow['close_price'];
        $goldAmount  = round($goldAmount, 4);
        $usdAmount   = $this->goldToUsd($goldAmount);
        $shares      = round($usdAmount / $priceUsd, 6);

        if ($goldAmount <= 0) {
            return ['success' => false, 'error' => 'Gold amount must be greater than zero.'];
        }
        if ($shares <= 0) {
            return ['success' => false, 'error' => 'Trade amount too small.'];
        }

        // Check user has enough gold
        $userGold = (float)$this->db->fetchValue(
            "SELECT gold FROM users WHERE id = ?", [$userId]
        );
        if ($userGold < $goldAmount) {
            return ['success' => false, 'error' => 'Insufficient Gold. You have ' . number_format($userGold, 2) . ' Gold.'];
        }

        $this->db->beginTransaction();
        try {
            // Deduct gold from user
            $this->db->run(
                "UPDATE users SET gold = gold - ? WHERE id = ?",
                [$goldAmount, $userId]
            );

            // Update or insert holding
            $existing = $this->getHolding($userId, $ticker);
            if ($existing) {
                // Recalculate weighted average cost basis
                $totalShares    = $existing['shares'] + $shares;
                $totalCostUsd   = ($existing['shares'] * $existing['avg_cost_basis']) + ($shares * $priceUsd);
                $newAvgCost     = $totalCostUsd / $totalShares;
                $newGoldInvested = $existing['gold_invested'] + $goldAmount;

                $this->db->run(
                    "UPDATE portfolio_holdings
                     SET shares = ?, avg_cost_basis = ?, gold_invested = ?
                     WHERE user_id = ? AND ticker = ?",
                    [round($totalShares, 6), round($newAvgCost, 4), round($newGoldInvested, 4), $userId, $ticker]
                );
            } else {
                $this->db->run(
                    "INSERT INTO portfolio_holdings
                     (user_id, ticker, shares, avg_cost_basis, gold_invested, first_purchased)
                     VALUES (?, ?, ?, ?, ?, CURDATE())",
                    [$userId, $ticker, round($shares, 6), round($priceUsd, 4), round($goldAmount, 4)]
                );
            }

            // Record trade
            $this->db->run(
                "INSERT INTO portfolio_trades
                 (user_id, ticker, trade_type, shares, price_per_share, gold_amount)
                 VALUES (?, ?, 'buy', ?, ?, ?)",
                [$userId, $ticker, round($shares, 6), round($priceUsd, 4), round($goldAmount, 4)]
            );

            $this->db->commit();

            appLog('info', 'Portfolio buy', [
                'user_id' => $userId, 'ticker' => $ticker,
                'shares'  => $shares, 'gold'   => $goldAmount,
            ]);

            return [
                'success'    => true,
                'error'      => null,
                'shares'     => round($shares, 6),
                'price'      => $priceUsd,
                'gold_spent' => $goldAmount,
                'as_of_date' => $priceRow['price_date'],
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            appLog('error', 'Portfolio buy failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Transaction failed. Please try again.'];
        }
    }

    /**
     * Execute a SELL order.
     * $sharesToSell = null means sell entire position.
     * Returns ['success' => bool, 'error' => string|null, 'gold_received' => float]
     */
    public function sell(int $userId, string $ticker, ?float $sharesToSell = null): array {
        $ticker  = strtoupper($ticker);
        $holding = $this->getHolding($userId, $ticker);

        if (!$holding || $holding['shares'] <= 0) {
            return ['success' => false, 'error' => 'You do not hold any shares of ' . $ticker . '.'];
        }

        $priceRow = $this->getLatestPrice($ticker);
        if (!$priceRow) {
            return ['success' => false, 'error' => 'No price data available for ' . $ticker . '.'];
        }

        $priceUsd    = (float)$priceRow['close_price'];
        $sharesToSell = $sharesToSell ?? $holding['shares'];
        $sharesToSell = min(round($sharesToSell, 6), $holding['shares']);

        if ($sharesToSell <= 0) {
            return ['success' => false, 'error' => 'Invalid share quantity.'];
        }

        $usdReceived  = round($sharesToSell * $priceUsd, 4);
        $goldReceived = round($this->usdToGold($usdReceived), 4);
        $sharesLeft   = round($holding['shares'] - $sharesToSell, 6);

        // Proportional gold_invested reduction
        $goldInvestedRemaining = $sharesLeft > 0
            ? round($holding['gold_invested'] * ($sharesLeft / $holding['shares']), 4)
            : 0;

        $this->db->beginTransaction();
        try {
            if ($sharesLeft <= 0.000001) {
                // Full sell — remove holding
                $this->db->run(
                    "DELETE FROM portfolio_holdings WHERE user_id = ? AND ticker = ?",
                    [$userId, $ticker]
                );
            } else {
                // Partial sell — update holding
                $this->db->run(
                    "UPDATE portfolio_holdings
                     SET shares = ?, gold_invested = ?
                     WHERE user_id = ? AND ticker = ?",
                    [$sharesLeft, $goldInvestedRemaining, $userId, $ticker]
                );
            }

            // Credit gold to user immediately
            $this->db->run(
                "UPDATE users SET gold = gold + ? WHERE id = ?",
                [$goldReceived, $userId]
            );

            // Record trade
            $this->db->run(
                "INSERT INTO portfolio_trades
                 (user_id, ticker, trade_type, shares, price_per_share, gold_amount)
                 VALUES (?, ?, 'sell', ?, ?, ?)",
                [$userId, $ticker, round($sharesToSell, 6), round($priceUsd, 4), round($goldReceived, 4)]
            );

            $this->db->commit();

            appLog('info', 'Portfolio sell', [
                'user_id' => $userId, 'ticker' => $ticker,
                'shares'  => $sharesToSell, 'gold_received' => $goldReceived,
            ]);

            return [
                'success'       => true,
                'error'         => null,
                'shares_sold'   => round($sharesToSell, 6),
                'price'         => $priceUsd,
                'gold_received' => $goldReceived,
                'as_of_date'    => $priceRow['price_date'],
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            appLog('error', 'Portfolio sell failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Transaction failed. Please try again.'];
        }
    }

    // =========================================================================
    // SNAPSHOTS & LEADERBOARD
    // =========================================================================

    /**
     * Build today's snapshot for a single user.
     * Called by the nightly cron for every user with holdings.
     */
    public function buildSnapshot(int $userId): void {
        $totalValueUsd  = $this->getTotalValue($userId);
        $costBasisUsd   = $this->getTotalCostBasis($userId);
        $goldEquivalent = $this->usdToGold($totalValueUsd);

        // % return since first trade
        $pctReturn = $costBasisUsd > 0
            ? round((($totalValueUsd - $costBasisUsd) / $costBasisUsd) * 100, 4)
            : 0;

        // SPX % return since user's first trade date
        $firstTradeDate = $this->db->fetchValue(
            "SELECT MIN(traded_at) FROM portfolio_trades WHERE user_id = ?", [$userId]
        );

        $spxPctReturn = 0;
        if ($firstTradeDate) {
            $inceptionSpx = $this->getSpxPriceOnDate(date('Y-m-d', strtotime($firstTradeDate)));
            $latestSpx    = $this->getLatestSpxPrice();
            if ($inceptionSpx && $latestSpx && $inceptionSpx > 0) {
                $spxPctReturn = round(
                    (((float)$latestSpx['close_price'] - $inceptionSpx) / $inceptionSpx) * 100, 4
                );
            }
        }

        $beatsIndex = ($pctReturn > $spxPctReturn) ? 1 : 0;

        $this->db->run(
            "INSERT INTO portfolio_snapshots
             (user_id, snapshot_date, total_value_usd, gold_equivalent,
              cost_basis_usd, pct_return, spx_pct_return, beats_index)
             VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_value_usd = VALUES(total_value_usd),
                gold_equivalent = VALUES(gold_equivalent),
                cost_basis_usd  = VALUES(cost_basis_usd),
                pct_return      = VALUES(pct_return),
                spx_pct_return  = VALUES(spx_pct_return),
                beats_index     = VALUES(beats_index)",
            [$userId, $totalValueUsd, $goldEquivalent, $costBasisUsd,
             $pctReturn, $spxPctReturn, $beatsIndex]
        );
    }

    /**
     * Rebuild the portfolio leaderboard cache.
     * Shows all users who have ever made a trade, sorted by pct_return DESC.
     */
    public function refreshLeaderboard(): void {
        $this->db->exec("TRUNCATE TABLE portfolio_leaderboard_cache");

        $this->db->exec(
            "INSERT INTO portfolio_leaderboard_cache
                (position, user_id, username, class, pct_return,
                 total_value_usd, beats_index, spx_pct_return)
             SELECT
                ROW_NUMBER() OVER (ORDER BY ps.pct_return DESC) AS position,
                u.id, u.username, u.class,
                ps.pct_return, ps.total_value_usd,
                ps.beats_index, ps.spx_pct_return
             FROM portfolio_snapshots ps
             JOIN users u ON u.id = ps.user_id
             WHERE ps.snapshot_date = CURDATE()
               AND u.is_banned = 0
             ORDER BY ps.pct_return DESC"
        );
    }

    /**
     * Award monthly Gold bonus to users who beat the index.
     * Called on last business day of month by cron.
     */
    public function awardMonthlyBonuses(): int {
        $bonus = (int)$this->db->getSetting('portfolio_monthly_bonus', 100);

        $winners = $this->db->fetchAll(
            "SELECT DISTINCT user_id FROM portfolio_snapshots
             WHERE snapshot_date = CURDATE() AND beats_index = 1"
        );

        foreach ($winners as $w) {
            $this->db->run(
                "UPDATE users SET gold = gold + ? WHERE id = ?",
                [$bonus, $w['user_id']]
            );
            appLog('info', 'Monthly index-beating bonus awarded', [
                'user_id' => $w['user_id'], 'gold' => $bonus,
            ]);
        }

        return count($winners);
    }

    // =========================================================================
    // TRADE HISTORY
    // =========================================================================

    public function getTradeHistory(int $userId, int $limit = 20): array {
        return $this->db->fetchAll(
            "SELECT t.*, s.company_name
             FROM portfolio_trades t
             LEFT JOIN stocks s ON s.ticker = t.ticker
             WHERE t.user_id = ?
             ORDER BY t.traded_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    // =========================================================================
    // STOCK SEARCH
    // =========================================================================

    public function searchStocks(string $query, int $limit = 10): array {
        $q = '%' . $query . '%';
        return $this->db->fetchAll(
            "SELECT ticker, company_name, sector
             FROM stocks
             WHERE is_active = 1
               AND (ticker LIKE ? OR company_name LIKE ?)
             ORDER BY
                CASE WHEN ticker = ? THEN 0
                     WHEN ticker LIKE ? THEN 1
                     ELSE 2 END,
                company_name ASC
             LIMIT ?",
            [$q, $q, strtoupper($query), strtoupper($query) . '%', $limit]
        );
    }
}
