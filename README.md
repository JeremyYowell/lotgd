# Legends of the Green Dollar

A multiplayer personal finance RPG web game inspired by Legend of the Red Dragon (LORD). Players go on financial adventures, equip gear from the store, build simulated S&P 500 portfolios, compete on leaderboards, and level up by making smart real-world financial decisions.

**Built with:** PHP 8.1 · MySQL 8 · Vanilla JS · DreamHost shared hosting

---

## Table of Contents

- [Features](#features)
- [Quick Start — Setup Wizard](#quick-start--setup-wizard)
- [Manual Installation](#manual-installation)
- [Cron Jobs](#cron-jobs)
- [Project Structure](#project-structure)
- [Architecture](#architecture)
- [Game Systems](#game-systems)
- [Settings Reference](#settings-reference)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Setup Wizard** — Seven-step browser-based installer. Checks server requirements, tests database connection, runs all SQL schemas automatically, writes config files, creates the admin account, and self-destructs on completion
- **Daily Adventurer's Brief** — AI-generated fantasy market recap delivered every day at market close. Uses SPY price movement and top S&P 500 movers from your own database plus Finnhub headlines, translated into fantasy narrative by Claude Sonnet. One API call per day (~$0.04/month), cached in the settings table and served to all players from cache
- **Adventure System** — Players face real-world financial scenarios (car dealerships, salary negotiations, market crashes) with RPG-style d20 rolls modified by level, class, and equipped gear. 13 seeded scenarios across 6 categories with full narrative outcomes for every result
- **Item Store** — 15 purchasable items across four types (Tools, Armor, Weapons, Consumables). Tools boost category-specific rolls, Armor reduces failure penalties, Weapons increase XP on success. Three equipment slots — buying a new item permanently replaces the old one. Consumables stack to 5 and replenish daily
- **S&P 500 Simulated Portfolio** — Trade any S&P 500 stock using in-game Gold at previous market close prices. Hourly price updates via Finnhub free tier. SPY used as benchmark index (tracks S&P 500, available on Finnhub free tier). Monthly Gold bonus for players who beat the benchmark
- **Leaderboard** — Primary ranking by portfolio % return. Also sortable by XP, Level, and Login Streak. Hall of fame strip for top portfolio, most XP, most adventures, and most devoted players
- **The Tavern** — Community message board with admin pinning, moderation, anti-spam cooldown
- **Email Confirmation** — Required before playing, with resend support, 48-hour token expiry, and XP reward on confirm
- **Admin Panel** — User management, in-browser settings editor, adventure scenario manager with full add/edit UI, cron health check with filterable log windows
- **Five Player Classes** — Investor, Debt Slayer, Saver, Entrepreneur, Minimalist — each with +3 roll bonus in relevant scenario categories
- **Achievement System** — 14 seeded achievements with XP and Gold rewards, awarded automatically
- **Daily Action Limits** — 10 adventures per day per player, resetting at midnight
- **Session Expiry Handling** — Expired sessions redirect cleanly to login with a flash message and 5-second auto-redirect on 404
- **Mobile-Responsive Navigation** — Hamburger menu at ≤860px with animated open/close

---

## Quick Start — Setup Wizard

The recommended way to install for the first time.

### Prerequisites

Before running the wizard, you need:

1. A web server running PHP 8.1+ with Apache and `.htaccess` support
2. A MySQL 8.0+ database created and ready (just the empty database — no tables needed)
3. Your database hostname, username, and password
4. A real email address hosted on your domain (for confirmation emails)
5. Optionally: a [Finnhub](https://finnhub.io) free API key and an [Anthropic](https://console.anthropic.com) API key

### Running the wizard

1. Clone or upload the repository to your web root:
```bash
git clone https://github.com/JeremyYowell/lotgd.git .
```

2. Navigate to `https://yourdomain.com/setup/` in your browser

3. Follow the seven steps:
   - **Step 1** — Requirements check (automatic, shows any issues)
   - **Step 2** — Database credentials (tests connection live)
   - **Step 3** — Base URL, timezone, environment
   - **Step 4** — Finnhub API key, Anthropic API key, email from address
   - **Step 5** — Admin account creation
   - **Step 6** — Review and install
   - **Step 7** — Post-install instructions (chmod, first cron runs)

4. After the wizard completes, run these two commands via SSH:
```bash
chmod 600 config/config.php
chmod 755 logs/
```

5. The `setup/` directory is deleted automatically on completion.

> **Security note:** The wizard refuses to run if `config/config.php` already contains real database credentials. It cannot be re-run after a successful installation.

---

## Manual Installation

For developers who prefer manual setup or are updating an existing installation.

### 1. Clone and configure

```bash
git clone https://github.com/JeremyYowell/lotgd.git
cd lotgd
cp config/config.example.php config/config.php
```

Edit `config/config.php` and fill in your database credentials, base URL, and API keys.

### 2. Create the logs directory

```bash
mkdir logs
chmod 755 logs
chmod 600 config/config.php
```

### 3. Protect sensitive directories

Add an `.htaccess` with `Deny from all` to each of these:

```
config/.htaccess
logs/.htaccess
cron/.htaccess
```

### 4. Run SQL schemas in order

```bash
mysql -u USER -p lotgd_dev < sql/schema.sql
mysql -u USER -p lotgd_dev < sql/portfolio_schema.sql
mysql -u USER -p lotgd_dev < sql/email_confirm_schema.sql
mysql -u USER -p lotgd_dev < sql/adventure_schema.sql
mysql -u USER -p lotgd_dev < sql/store_schema.sql
mysql -u USER -p lotgd_dev < sql/daily_brief_settings.sql
```

### 5. Create your admin account

After registering via the normal registration page:

```sql
UPDATE users SET is_admin = 1, email_confirmed = 1
WHERE username = 'your_username';
```

### 6. Configure required settings

```sql
UPDATE settings SET setting_value = 'your_finnhub_key'
WHERE setting_key = 'finnhub_api_key';

UPDATE settings SET setting_value = 'noreply@yourdomain.com'
WHERE setting_key = 'email_from_address';
```

---

## Cron Jobs

### First-time manual run (required)

```bash
# 1. Populate S&P 500 stocks (~500 tickers from Wikipedia)
php /path/to/lotgd/cron/sp500_update.php

# 2. Download first closing prices (~9 minutes due to Finnhub rate limiting)
php /path/to/lotgd/cron/price_update.php

# 3. Generate first Daily Adventurer's Brief
php /path/to/lotgd/cron/generate_brief.php --force
```

### Recurring cron schedule

| Schedule | Expression | Command |
|---|---|---|
| Hourly price update (weekdays) | `0 * * * 1-5` | `php /path/cron/price_update.php` |
| S&P 500 update — January | `0 7 2 1 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — April | `0 7 1 4 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — July | `0 7 1 7 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — October | `0 7 1 10 *` | `php /path/cron/sp500_update.php` |

**What `price_update.php` does hourly:**
1. Pulls latest prices for all ~500 S&P 500 tickers plus SPY from Finnhub
2. Computes portfolio snapshots for all users with open holdings
3. Refreshes the portfolio leaderboard cache
4. After 5 PM on weekdays, generates the Daily Adventurer's Brief if not yet done today
5. On the last business day of the month, awards 100 Gold to players beating SPY

**What `sp500_update.php` does quarterly:**
1. Scrapes the S&P 500 constituent list from Wikipedia
2. Adds new entrants, marks removed tickers as inactive

### Monitoring

Admin panel → Cron Health shows status cards for all three cron jobs with appropriate staleness thresholds (hourly price updates go red after 2 hours on weekdays; the quarterly S&P 500 update only goes red after ~3 months). Log windows show newest entries first with per-level filtering checkboxes. The Price Update log hides WARN messages by default since Finnhub returns empty data after market close.

---

## Project Structure

```
lotgd/
├── admin/
│   ├── index.php               Admin dashboard — stats, nav, cron quick view
│   ├── users.php               User management (ban, confirm, delete)
│   ├── settings.php            In-browser settings editor (API keys masked)
│   ├── adventures.php          Scenario manager with full add/edit UI
│   ├── cron.php                Cron health + filterable log windows
│   └── .htaccess
├── api/
│   └── stock_search.php        JSON endpoint for portfolio live search
├── assets/
│   └── css/
│       ├── main.css            Global styles, design system, hamburger nav
│       ├── dashboard.css
│       ├── adventure.css
│       ├── leaderboard.css
│       ├── tavern.css
│       ├── portfolio.css
│       ├── store.css
│       ├── brief.css           Daily Adventurer's Brief card styles
│       └── admin.css
├── config/
│   ├── config.example.php      ← commit this
│   └── config.php              ← DO NOT commit (.gitignore'd)
├── cron/
│   ├── price_update.php        Hourly prices + brief generation after 5 PM
│   ├── sp500_update.php        Quarterly S&P 500 constituent scraper
│   └── generate_brief.php      Manual brief trigger (--force flag available)
├── lib/
│   ├── Database.php            PDO singleton with query helpers
│   ├── Session.php             Auth, CSRF protection, flash messages
│   ├── User.php                User model, XP/leveling, achievements
│   ├── Portfolio.php           Portfolio trading, snapshots, leaderboard
│   ├── Adventure.php           d20 roll engine, scenario selection, item bonuses
│   ├── Store.php               Item store, inventory, effect calculation
│   ├── DailyBrief.php          Claude API call, market data, realm stats, HTML render
│   └── Mailer.php              System email via PHP mail()
├── logs/                       Runtime logs — git-tracked as empty dir
├── pages/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php           Shows Daily Brief + player stats + mini leaderboard
│   ├── adventure.php
│   ├── leaderboard.php         Portfolio % return as primary sort
│   ├── tavern.php
│   ├── portfolio.php
│   ├── store.php
│   ├── confirm_email.php
│   └── confirm_required.php
├── setup/
│   └── index.php               Seven-step installation wizard (self-destructs on completion)
├── sql/
│   ├── schema.sql
│   ├── portfolio_schema.sql
│   ├── email_confirm_schema.sql
│   ├── adventure_schema.sql
│   ├── store_schema.sql
│   └── daily_brief_settings.sql
├── templates/
│   ├── layout.php              Master HTML with hamburger nav
│   └── maintenance.php
├── 404.php                     Auto-redirect with 5-second countdown
├── bootstrap.php               Session expiry detection, maintenance gate, email gate
├── index.php
├── .gitignore
├── .htaccess
├── LICENSE
├── README.md
└── CONTRIBUTING.md
```

---

## Architecture

### Request Flow

Every page starts with `require_once bootstrap.php` which runs:
1. Load `config/config.php`
2. Autoload `lib/ClassName.php`
3. `Session::start()`
4. `$db = Database::getInstance()`
5. Session age check — expired sessions redirect to login cleanly
6. Maintenance mode check
7. Email confirmation gate

### Environment Switching

One line in `config/config.php`:
```php
define('APP_ENV', 'dev');  // 'dev' or 'prod'
```
Controls: database name, error display, debug banner, secure-only cookies.

---

## Game Systems

### Adventure Roll Mechanic

```
final_roll = d20 + level_modifier + class_modifier + item_bonus

level_modifier  = floor(level / 5)           → +1 per 5 levels, max +10
class_modifier  = +3 if category matches class bonus categories
item_bonus      = flat bonus from equipped Tool (category-specific or global)

Outcome vs DC:
  >= DC + 5  → Critical Success  — 150% XP (× weapon multiplier) + 150% Gold
  >= DC      → Success           — 100% XP (× weapon multiplier) + 100% Gold
  <  DC      → Failure           — 0 XP, −25% Gold (× armor multiplier)
  <= DC − 5  → Critical Failure  — 0 XP, −50% Gold (× armor multiplier)
```

| Class | Bonus Categories |
|---|---|
| Investor | investing |
| Debt Slayer | banking, shopping |
| Saver | daily_life, shopping |
| Entrepreneur | work |
| Minimalist | shopping, daily_life |

### Daily Adventurer's Brief

Generated once per day by `cron/price_update.php` after 5 PM on weekdays, or on-demand via `cron/generate_brief.php --force`. Data sources:

- SPY price movement and top 5 S&P 500 gainers/losers from `stock_prices` table
- Up to 3 Finnhub market headlines
- Realm stats: yesterday's adventure count, Gold earned, new players, achievement awards, leaderboard changes, store purchases, tavern activity

All of this is sent in a single Claude Sonnet API call. The response is rendered to HTML and cached in the `daily_brief_html` setting. The dashboard reads from cache — no API calls at page load time. Cost: approximately $0.04/month.

### Item Store

Three permanent equipment slots (Tool, Armor, Weapon). Buying replaces and destroys the current item — no refund. Consumables stack to 5 and replenish daily from the store.

| Effect Type | Description |
|---|---|
| `roll_bonus` | Flat addition to d20 roll for a specific category or all |
| `failure_reduction` | Multiplies Gold penalty on failure |
| `xp_boost` | Multiplies XP reward on success |
| `action_restore` | Adds daily adventure actions on use |
| `roll_boost_once` | Adds to next roll only, then consumed |
| `reroll_once` | Re-rolls most recent failed adventure (once/day) |

### Portfolio System

- 1 Gold = $1,000 USD (configurable)
- Trades at previous market close price from Finnhub `pc` field
- SPY used as benchmark (tracks S&P 500, available on Finnhub free tier)
- Fractional shares to 6 decimal places, weighted average cost basis
- Hourly snapshots during market hours
- Monthly 100 Gold bonus for players whose % return beats SPY

### Adding Adventure Scenarios

Via Admin → Adventure Manager → Add Scenario. Each scenario needs:
- At least 2 choices
- All four narrative fields per choice (success, failure, crit success, crit failure)
- Difficulty (DC) between 5 and 18
- Min/max level range

Or via SQL using `sql/adventure_schema.sql` as a template.

### Adding Store Items

Insert into `store_items` referencing `sql/store_schema.sql`. Key fields: `effect_type`, `effect_value`, `effect_category` (NULL = all categories), `level_req`, `price`.

---

## Settings Reference

All editable via Admin → Settings:

| Key | Default | Description |
|---|---|---|
| `env` | `dev` | Environment |
| `daily_action_limit` | `10` | Adventure actions per player per day |
| `gold_to_usd_rate` | `1000` | 1 Gold = this many USD |
| `portfolio_monthly_bonus` | `100` | Gold for beating SPY monthly |
| `finnhub_api_key` | — | Finnhub free tier key (masked in UI) |
| `claude_api_key` | — | Anthropic API key — overrides config.php (masked in UI) |
| `email_from_address` | — | System email from address |
| `email_confirm_xp_reward` | `10` | XP for confirming email |
| `email_confirmation_enabled` | `1` | Require email confirmation |
| `registration_open` | `1` | Allow new registrations |
| `maintenance_mode` | `0` | Lock site to admins only |
| `max_level` | `50` | Maximum player level |
| `adventure_enabled` | `1` | Enable adventure module |
| `portfolio_enabled` | `1` | Enable portfolio module |
| `store_enabled` | `1` | Enable item store |
| `consumable_daily_limit` | `5` | Max consumables per player |
| `daily_brief_enabled` | `1` | Show daily brief on dashboard |
| `daily_brief_date` | — | Auto-managed: date of last brief |
| `daily_brief_html` | — | Auto-managed: cached brief HTML |
| `spx_inception_date` | — | Auto-managed: first price date |
| `spx_inception_price` | — | Auto-managed: SPY baseline price |
| `portfolio_last_price_update` | — | Auto-managed: last price run timestamp |
| `portfolio_bonus_last_awarded` | — | Auto-managed: prevents duplicate monthly bonuses |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

MIT — see [LICENSE](LICENSE).
