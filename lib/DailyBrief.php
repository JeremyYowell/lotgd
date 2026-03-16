<?php
/**
 * lib/DailyBrief.php — Daily Adventurer's Brief generator
 * =========================================================
 * Generates a fantasy-flavored market + realm summary once per day.
 * Called by the nightly price_update.php cron after prices are loaded.
 * Output is cached in the settings table as 'daily_brief_html' and
 * 'daily_brief_date' — the dashboard reads from cache, never calls Claude
 * directly on page load.
 */

class DailyBrief {

    private Database $db;
    private string   $apiKey;
    private string   $finnhubKey;

    private const MODEL      = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 600;

    private const SYSTEM = <<<PROMPT
You are the herald of the Realm of Fiscal Destiny. Each morning you deliver
the Daily Adventurer's Brief — a dramatic, fantasy-flavored summary of
yesterday's real-world financial markets and the realm's own player activity.

Write in the style of a medieval town crier who has also read the Wall Street
Journal. Be vivid and specific. Reference actual tickers, numbers, and player
names by name. Keep financial facts accurate — only the framing is fantasy.

Respond with EXACTLY this JSON structure and nothing else:
{
  "market_p1": "First market paragraph (2-3 sentences, fantasy framing of SPX move and key movers)",
  "market_p2": "Second market paragraph (1-2 sentences, macro context or forward look)",
  "realm_p1": "First realm paragraph (adventure activity, Gold earned, new players)",
  "realm_p2": "Second realm paragraph (notable achievements, leaderboard changes, store purchases, tavern activity)"
}
PROMPT;

    public function __construct() {
        $this->db         = Database::getInstance();
        $this->apiKey     = ANTHROPIC_API_KEY;
        $this->finnhubKey = $this->db->getSetting('finnhub_api_key', '');
    }

    // =========================================================================
    // PUBLIC: Generate and cache today's brief
    // =========================================================================

    /**
     * Generate today's brief and store in settings table.
     * Safe to call multiple times — skips if already generated today.
     * Returns true on success, false on failure.
     */
    public function generate(bool $force = false): bool {
        $today     = date('Y-m-d');
        $lastDate  = $this->db->getSetting('daily_brief_date', '');

        if (!$force && $lastDate === $today) {
            return true; // Already generated today
        }

        // -----------------------------------------------------------------------
        // 1. Gather market data from our own stock_prices table
        // -----------------------------------------------------------------------
        $marketData = $this->gatherMarketData();
        if (!$marketData) {
            appLog('warn', 'DailyBrief: no market data available yet');
            return false;
        }

        // -----------------------------------------------------------------------
        // 2. Gather realm stats from game tables
        // -----------------------------------------------------------------------
        $realmData = $this->gatherRealmData();

        // -----------------------------------------------------------------------
        // 3. Optionally pull Finnhub headlines for texture
        // -----------------------------------------------------------------------
        $headlines = $this->fetchFinnhubHeadlines(3);

        // -----------------------------------------------------------------------
        // 4. Build prompt and call Claude
        // -----------------------------------------------------------------------
        $prompt = $this->buildPrompt($marketData, $realmData, $headlines);
        $json   = $this->callClaude($prompt);

        if (!$json) {
            appLog('error', 'DailyBrief: Claude call failed');
            return false;
        }

        // -----------------------------------------------------------------------
        // 5. Render to HTML and cache
        // -----------------------------------------------------------------------
        $html = $this->renderHtml($json, $marketData);

        $this->db->setSetting('daily_brief_html',  $html);
        $this->db->setSetting('daily_brief_date',  $today);
        $this->db->setSetting('daily_brief_generated_at', date('Y-m-d H:i:s'));

        appLog('info', 'DailyBrief: generated successfully for ' . $today);
        return true;
    }

    /**
     * Get the cached brief HTML for display on the dashboard.
     * Returns null if no brief has been generated yet.
     */
    public function getCached(): ?string {
        $html = $this->db->getSetting('daily_brief_html', '');
        return $html ?: null;
    }

    /**
     * Get the date the brief was last generated.
     */
    public function getLastDate(): string {
        return $this->db->getSetting('daily_brief_date', '');
    }

    // =========================================================================
    // PRIVATE: Data gathering
    // =========================================================================

    private function gatherMarketData(): ?array {
        // Get yesterday's SPX close and the day before for % change
        $spxRows = $this->db->fetchAll(
            "SELECT price_date, close_price FROM stock_prices
             WHERE ticker = '^GSPC'
             ORDER BY price_date DESC LIMIT 2"
        );

        if (count($spxRows) < 1) return null;

        $spxToday = (float)$spxRows[0]['close_price'];
        $spxPrev  = count($spxRows) >= 2 ? (float)$spxRows[1]['close_price'] : $spxToday;
        $spxDate  = $spxRows[0]['price_date'];
        $spxChg   = $spxToday - $spxPrev;
        $spxPct   = $spxPrev > 0 ? round(($spxChg / $spxPrev) * 100, 2) : 0;

        // Top 5 gainers and losers from latest prices vs previous day
        $latestDate = $this->db->fetchValue(
            "SELECT MAX(price_date) FROM stock_prices WHERE ticker != '^GSPC'"
        );
        $prevDate = $this->db->fetchValue(
            "SELECT MAX(price_date) FROM stock_prices
             WHERE ticker != '^GSPC' AND price_date < ?",
            [$latestDate]
        );

        $gainers = [];
        $losers  = [];

        if ($latestDate && $prevDate) {
            $movers = $this->db->fetchAll(
                "SELECT t.ticker,
                        t.close_price AS today_price,
                        p.close_price AS prev_price,
                        ROUND((t.close_price - p.close_price) / p.close_price * 100, 2) AS pct_change
                 FROM stock_prices t
                 JOIN stock_prices p ON p.ticker = t.ticker AND p.price_date = ?
                 WHERE t.price_date = ? AND t.ticker != '^GSPC' AND p.close_price > 0
                 ORDER BY pct_change DESC",
                [$prevDate, $latestDate]
            );

            $gainers = array_slice($movers, 0, 5);
            $losers  = array_slice(array_reverse($movers), 0, 5);
        }

        return [
            'spx_close'   => $spxToday,
            'spx_prev'    => $spxPrev,
            'spx_change'  => round($spxChg, 2),
            'spx_pct'     => $spxPct,
            'spx_date'    => $spxDate,
            'gainers'     => $gainers,
            'losers'      => $losers,
        ];
    }

    private function gatherRealmData(): array {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today     = date('Y-m-d');

        // Adventure stats yesterday
        $advStats = $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total_adventures,
                SUM(CASE WHEN outcome IN ('success','crit_success') THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'crit_success' THEN 1 ELSE 0 END) AS crits,
                SUM(CASE WHEN gold_delta > 0 THEN gold_delta ELSE 0 END) AS gold_earned
             FROM adventure_log
             WHERE DATE(adventured_at) = ?",
            [$yesterday]
        );

        // New players yesterday
        $newPlayers = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?", [$yesterday]
        );

        // Achievements awarded yesterday
        $achievementsAwarded = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM user_achievements WHERE DATE(earned_at) = ?", [$yesterday]
        );

        // Most notable achievement earned yesterday
        $topAchievement = $this->db->fetchOne(
            "SELECT u.username, a.name AS achievement_name, a.xp_reward
             FROM user_achievements ua
             JOIN users u ON u.id = ua.user_id
             JOIN achievements a ON a.id = ua.achievement_id
             WHERE DATE(ua.earned_at) = ?
             ORDER BY a.xp_reward DESC LIMIT 1",
            [$yesterday]
        );

        // Leaderboard leader (portfolio)
        $leader = $this->db->fetchOne(
            "SELECT u.username, ps.pct_return
             FROM portfolio_snapshots ps
             JOIN users u ON u.id = ps.user_id
             WHERE ps.snapshot_date = ?
             ORDER BY ps.pct_return DESC LIMIT 1",
            [$yesterday ?: $today]
        );

        // Most active tavern poster yesterday
        $tavernStar = $this->db->fetchOne(
            "SELECT u.username, COUNT(*) AS posts
             FROM tavern_messages m
             JOIN users u ON u.id = m.user_id
             WHERE DATE(m.posted_at) = ? AND m.is_deleted = 0
             GROUP BY m.user_id ORDER BY posts DESC LIMIT 1",
            [$yesterday]
        );

        // Store purchase of the day (most expensive item bought)
        $bigPurchase = $this->db->fetchOne(
            "SELECT u.username, si.name AS item_name, si.price
             FROM user_inventory ui
             JOIN users u ON u.id = ui.user_id
             JOIN store_items si ON si.id = ui.item_id
             WHERE DATE(ui.acquired_at) = ?
             ORDER BY si.price DESC LIMIT 1",
            [$yesterday]
        );

        // Total active players (ever logged in)
        $totalPlayers = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE is_banned = 0"
        );

        return [
            'adventures'          => (int)($advStats['total_adventures'] ?? 0),
            'wins'                => (int)($advStats['wins'] ?? 0),
            'crits'               => (int)($advStats['crits'] ?? 0),
            'gold_earned'         => (int)($advStats['gold_earned'] ?? 0),
            'new_players'         => $newPlayers,
            'achievements_awarded'=> $achievementsAwarded,
            'top_achievement'     => $topAchievement,
            'portfolio_leader'    => $leader,
            'tavern_star'         => $tavernStar,
            'big_purchase'        => $bigPurchase,
            'total_players'       => $totalPlayers,
        ];
    }

    private function fetchFinnhubHeadlines(int $count = 3): array {
        if (empty($this->finnhubKey) || $this->finnhubKey === 'YOUR_KEY_HERE') {
            return [];
        }

        $url = 'https://finnhub.io/api/v1/news?category=general&token='
             . urlencode($this->finnhubKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $code !== 200) return [];

        $data = json_decode($raw, true);
        if (!is_array($data)) return [];

        $headlines = [];
        foreach (array_slice($data, 0, $count) as $item) {
            if (!empty($item['headline'])) {
                $headlines[] = $item['headline'];
            }
        }

        return $headlines;
    }

    // =========================================================================
    // PRIVATE: Prompt + Claude call
    // =========================================================================

    private function buildPrompt(array $md, array $rd, array $headlines): string {
        $spxDir   = $md['spx_change'] >= 0 ? 'rose' : 'fell';
        $spxSign  = $md['spx_pct'] >= 0 ? '+' : '';
        $gainStr  = implode(', ', array_map(
            fn($g) => $g['ticker'] . ' ' . ($g['pct_change'] >= 0 ? '+' : '') . $g['pct_change'] . '%',
            $md['gainers']
        ));
        $loseStr  = implode(', ', array_map(
            fn($l) => $l['ticker'] . ' ' . $l['pct_change'] . '%',
            $md['losers']
        ));

        $headlineStr = !empty($headlines)
            ? 'Market headlines: ' . implode(' | ', $headlines)
            : '';

        $realmStr = implode("\n", array_filter([
            "Total adventures yesterday: {$rd['adventures']} ({$rd['wins']} wins, {$rd['crits']} critical successes)",
            "Gold earned by players: {$rd['gold_earned']}",
            $rd['new_players'] > 0 ? "New adventurers who joined: {$rd['new_players']}" : '',
            $rd['achievements_awarded'] > 0 ? "Achievements awarded: {$rd['achievements_awarded']}" : '',
            $rd['top_achievement'] ? "Most notable achievement: {$rd['top_achievement']['username']} earned \"{$rd['top_achievement']['achievement_name']}\"" : '',
            $rd['portfolio_leader'] ? "Portfolio leaderboard leader: {$rd['portfolio_leader']['username']} at +" . number_format((float)$rd['portfolio_leader']['pct_return'], 2) . '% return' : '',
            $rd['tavern_star'] ? "Most active tavern poster: {$rd['tavern_star']['username']} with {$rd['tavern_star']['posts']} posts" : '',
            $rd['big_purchase'] ? "Biggest store purchase: {$rd['big_purchase']['username']} bought {$rd['big_purchase']['item_name']} for {$rd['big_purchase']['price']} Gold" : '',
            "Total realm population: {$rd['total_players']} adventurers",
        ]));

        return <<<PROMPT
MARKET DATA ({$md['spx_date']}):
S&P 500 {$spxDir} {$md['spx_change']} points ({$spxSign}{$md['spx_pct']}%) to close at {$md['spx_close']}.
Top gainers: {$gainStr}
Top losers: {$loseStr}
{$headlineStr}

REALM DATA (yesterday):
{$realmStr}

Write the Daily Adventurer's Brief in fantasy voice. Be specific — use the actual ticker symbols, player names, and numbers. Keep each paragraph to 2-3 sentences. Return only the JSON object.
PROMPT;
    }

    private function callClaude(string $prompt): ?array {
        $payload = json_encode([
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => self::SYSTEM,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init(ANTHROPIC_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . ANTHROPIC_API_VER,
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $code !== 200) return null;

        $response = json_decode($raw, true);
        $text     = '';
        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') $text .= $block['text'];
        }

        // Strip markdown fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', trim($text));
        $text = preg_replace('/\s*```$/m', '', $text);

        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) return null;

        return $parsed;
    }

    // =========================================================================
    // PRIVATE: HTML rendering
    // =========================================================================

    private function renderHtml(array $json, array $md): string {
        $date      = date('l, F j, Y', strtotime($md['spx_date']));
        $spxSign   = $md['spx_pct'] >= 0 ? '+' : '';
        $spxColor  = $md['spx_pct'] >= 0 ? 'var(--color-green)' : 'var(--color-red)';
        $topGainer = !empty($md['gainers']) ? $md['gainers'][0] : null;
        $topLoser  = !empty($md['losers'])  ? $md['losers'][0]  : null;

        $p1 = htmlspecialchars($json['market_p1'] ?? '', ENT_QUOTES);
        $p2 = htmlspecialchars($json['market_p2'] ?? '', ENT_QUOTES);
        $r1 = htmlspecialchars($json['realm_p1']  ?? '', ENT_QUOTES);
        $r2 = htmlspecialchars($json['realm_p2']  ?? '', ENT_QUOTES);

        $gainerHtml = $topGainer
            ? '<div class="brief-stat"><div class="brief-stat-label">Top Raider</div>'
              . '<div class="brief-stat-val">' . htmlspecialchars($topGainer['ticker']) . '</div>'
              . '<div class="brief-stat-sub brief-green">'
              . ($topGainer['pct_change'] >= 0 ? '+' : '') . $topGainer['pct_change'] . '%</div></div>'
            : '';

        $loserHtml = $topLoser
            ? '<div class="brief-stat"><div class="brief-stat-label">Fallen Giant</div>'
              . '<div class="brief-stat-val">' . htmlspecialchars($topLoser['ticker']) . '</div>'
              . '<div class="brief-stat-sub brief-red">'
              . $topLoser['pct_change'] . '%</div></div>'
            : '';

        return <<<HTML
<div class="daily-brief-wrap">
    <div class="brief-header">
        <div class="brief-header-left">
            <span class="brief-icon">📜</span>
            <span class="brief-title">The Daily Adventurer's Brief</span>
        </div>
        <span class="brief-date">{$date}</span>
    </div>
    <div class="brief-body">
        <div class="brief-market-text">
            <p>{$p1}</p>
            <p>{$p2}</p>
        </div>
        <div class="brief-stats-strip">
            <div class="brief-stat">
                <div class="brief-stat-label">S&amp;P 500</div>
                <div class="brief-stat-val">{$md['spx_close']}</div>
                <div class="brief-stat-sub" style="color:{$spxColor}">{$spxSign}{$md['spx_change']} ({$spxSign}{$md['spx_pct']}%)</div>
            </div>
            {$gainerHtml}
            {$loserHtml}
        </div>
        <div class="brief-realm">
            <div class="brief-realm-header">
                <span>⚔</span>
                <span>From the Realm</span>
            </div>
            <p>{$r1}</p>
            <p>{$r2}</p>
        </div>
    </div>
</div>
HTML;
    }
}
