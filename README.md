# Legends of the Green Dollar

A multiplayer personal finance RPG web game inspired by Legend of the Red Dragon (LORD). Players go on financial adventures, equip gear from the store, build simulated S&P 500 portfolios, compete on leaderboards, and level up by making smart real-world financial decisions.

**Built with:** PHP 8.1 · MySQL 8 · Vanilla JS · DreamHost shared hosting

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Database Setup](#database-setup)
- [Cron Jobs](#cron-jobs)
- [Project Structure](#project-structure)
- [Architecture](#architecture)
- [Game Systems](#game-systems)
- [Settings Reference](#settings-reference)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Adventure System** — Players face real-world financial scenarios (car dealerships, salary negotiations, market crashes) with RPG-style d20 rolls modified by level, class, and equipped gear. 13 seeded scenarios across 6 categories with full narrative outcomes for every result
- **Item Store** — 15 purchasable items across four types (Tools, Armor, Weapons, Consumables). Tools boost category-specific rolls, Armor reduces failure penalties, Weapons increase XP on success. Three equipment slots — buying a new item permanently replaces the old one
- **S&P 500 Simulated Portfolio** — Trade any S&P 500 stock using in-game Gold at previous market close prices. Daily price updates via Finnhub free tier. Monthly Gold bonus for players who beat the index
- **Leaderboard** — Primary ranking by portfolio % return. Also sortable by XP, Level, and Login Streak. Hall of fame strip for top portfolio, most XP, most adventures, and most devoted players
- **The Tavern** — Community message board with admin pinning, moderation, anti-spam cooldown, and active-today sidebar
- **Email Confirmation** — Required before playing, with resend support, 48-hour token expiry, and XP reward on confirm
- **Admin Panel** — User management (ban, confirm, delete), settings editor, adventure scenario manager, cron health check with log tails
- **Five Player Classes** — Investor, Debt Slayer, Saver, Entrepreneur, Minimalist — each with +3 roll bonus in relevant scenario categories
- **Achievement System** — 14 seeded achievements with XP and Gold rewards, awarded automatically
- **Daily Action Limits** — 10 adventures per day per player, resetting at midnight
- **Session Expiry Handling** — Expired sessions redirect cleanly to login with a flash message rather than showing errors
- **Mobile-Responsive Navigation** — Hamburger menu at ≤860px with animated open/close

---

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Apache with `.htaccess` support (DreamHost shared hosting works out of the box)
- A [Finnhub](https://finnhub.io) free API key (for daily stock price updates)
- A hosted email address on your domain (for confirmation emails via PHP `mail()`)
- SSH access to run cron jobs and the initial data population scripts

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/YOURUSERNAME/lotgd.git
cd lotgd
```

### 2. Create your config file

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php` and fill in:
- Database hostname, username, password
- Your base URL for dev and prod
- Timezone

### 3. Create the logs directory

```bash
mkdir logs
chmod 755 logs
```

### 4. Protect sensitive directories

Add an `.htaccess` with `Deny from all` to each of these directories:

```
config/.htaccess
logs/.htaccess
cron/.htaccess
```

### 5. Lock down config file permissions

```bash
chmod 600 config/config.php
```

---

## Database Setup

### Create two databases

In your hosting control panel create:
- `lotgd_dev` — development
- `lotgd_prod` — production

### Run SQL files in order

Run each against **both** databases:

```bash
# 1. Core schema — users, sessions, achievements, leaderboard cache
mysql -u USER -p lotgd_dev < sql/schema.sql

# 2. Portfolio module — stocks, prices, holdings, trades, snapshots
mysql -u USER -p lotgd_dev < sql/portfolio_schema.sql

# 3. Email confirmation columns
mysql -u USER -p lotgd_dev < sql/email_confirm_schema.sql

# 4. Adventure system — scenarios, choices, log + 13 seeded scenarios
mysql -u USER -p lotgd_dev < sql/adventure_schema.sql

# 5. Item store — store_items, user_inventory + 15 seeded items
mysql -u USER -p lotgd_dev < sql/store_schema.sql
```

### Grant yourself admin access

After registering your first account:

```sql
UPDATE users SET is_admin = 1, email_confirmed = 1
WHERE username = 'your_username';
```

### Configure required settings

```sql
-- Finnhub API key (get free at finnhub.io)
UPDATE settings SET setting_value = 'your_finnhub_key_here'
WHERE setting_key = 'finnhub_api_key';

-- From address for confirmation emails (must be a real mailbox on your domain)
UPDATE settings SET setting_value = 'noreply@yourdomain.com'
WHERE setting_key = 'email_from_address';
```

---

## Cron Jobs

### First-time manual setup (required before cron is active)

Run these once via SSH in this order:

```bash
# 1. Populate S&P 500 stocks table (~500 rows scraped from Wikipedia)
php /path/to/lotgd/cron/sp500_update.php

# 2. Download first closing prices and set SPX inception baseline
#    Takes ~9-10 minutes due to Finnhub rate limiting (60 calls/min)
php /path/to/lotgd/cron/price_update.php
```

### Recurring cron schedule

| Schedule | Expression | Command |
|---|---|---|
| Nightly price update (weekdays) | `0 18 * * 1-5` | `php /path/cron/price_update.php` |
| S&P 500 update — January | `0 7 2 1 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — April | `0 7 1 4 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — July | `0 7 1 7 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — October | `0 7 1 10 1-5` | `php /path/cron/sp500_update.php` |

> January uses day 2 since January 1 is always a market holiday.

**What `price_update.php` does nightly:**
1. Pulls previous-close price for all ~500 S&P 500 tickers + `^GSPC` from Finnhub
2. Builds daily `portfolio_snapshots` for every user with open holdings
3. Refreshes the portfolio leaderboard cache
4. On the last business day of the month: awards 100 Gold to users beating the S&P 500

**What `sp500_update.php` does quarterly:**
1. Scrapes the S&P 500 constituent list from Wikipedia
2. Adds new entrants, marks removed tickers as inactive
3. Logs all changes to `logs/cron_sp500.log`

### Monitoring cron health

Admin panel → Cron Health shows last run timestamps and the last 40 lines of each log. Or directly:

```bash
tail -50 logs/cron_prices.log
tail -50 logs/cron_sp500.log
```

---

## Project Structure

```
lotgd/
├── admin/
│   ├── index.php               Admin dashboard with stats
│   ├── users.php               User management (ban, confirm, delete)
│   ├── settings.php            In-browser settings table editor
│   ├── adventures.php          Adventure scenario enable/disable
│   ├── cron.php                Cron health check + log tails
│   └── .htaccess               Disables directory listing
├── api/
│   └── stock_search.php        JSON endpoint for portfolio live search
├── assets/
│   └── css/
│       ├── main.css            Global styles, design system, mobile nav
│       ├── dashboard.css
│       ├── adventure.css
│       ├── leaderboard.css
│       ├── tavern.css
│       ├── portfolio.css
│       ├── store.css
│       └── admin.css
├── config/
│   ├── config.example.php      ← commit this
│   └── config.php              ← DO NOT commit (.gitignore'd)
├── cron/
│   ├── price_update.php        Nightly price download + snapshot builder
│   └── sp500_update.php        Quarterly S&P 500 constituent scraper
├── lib/
│   ├── Database.php            PDO singleton with query helpers
│   ├── Session.php             Auth, CSRF protection, flash messages
│   ├── User.php                User model, XP/leveling, achievements
│   ├── Portfolio.php           Portfolio trading, snapshots, leaderboard
│   ├── Adventure.php           d20 roll engine, scenario selection
│   ├── Store.php               Item store, inventory, effect calculation
│   └── Mailer.php              System email via PHP mail()
├── logs/                       Runtime logs — git-tracked as empty dir
├── pages/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── adventure.php
│   ├── leaderboard.php
│   ├── tavern.php
│   ├── portfolio.php
│   ├── store.php
│   ├── confirm_email.php
│   └── confirm_required.php
├── sql/
│   ├── schema.sql              Core schema + achievements + leaderboard cache
│   ├── portfolio_schema.sql    Portfolio module
│   ├── email_confirm_schema.sql
│   ├── adventure_schema.sql    Adventure system + 13 seeded scenarios
│   └── store_schema.sql        Item store + 15 seeded items
├── templates/
│   ├── layout.php              Master HTML wrapper with mobile hamburger nav
│   └── maintenance.php         503 maintenance page
├── 404.php                     Custom 404 with 5-second auto-redirect
├── bootstrap.php               App entry point — autoloader, session, gates
├── index.php                   Root redirect
├── .gitignore
├── .htaccess                   ErrorDocument routing, directory protection
├── LICENSE
├── README.md
└── CONTRIBUTING.md
```

---

## Architecture

### Request Flow

Every page starts with:
```php
require_once __DIR__ . '/../bootstrap.php';
```

`bootstrap.php` runs in order:
1. Load `config/config.php`
2. Register class autoloader (`lib/ClassName.php`)
3. `Session::start()` with secure cookie settings
4. `$db = Database::getInstance()` — singleton PDO wrapper
5. Session age check — expired sessions redirect to login cleanly
6. Maintenance mode check
7. Email confirmation gate — unconfirmed users redirected to reminder page

### Database Helpers

```php
$db->fetchOne($sql, $params)    // single row as assoc array
$db->fetchAll($sql, $params)    // all rows
$db->fetchValue($sql, $params)  // single scalar value
$db->run($sql, $params)         // execute, returns PDOStatement
$db->getSetting($key, $default) // settings table (cached per request)
$db->setSetting($key, $value)   // update settings table
```

### Environment Switching

One line in `config/config.php`:
```php
define('APP_ENV', 'dev');  // 'dev' or 'prod'
```

Controls: which database is used, error display, debug mode, secure-only cookies, dev banner in footer.

---

## Game Systems

### Adventure Roll Mechanic

```
final_roll = d20 + level_modifier + class_modifier + item_bonus

level_modifier  = floor(level / 5)      → +1 per 5 levels, max +10 at level 50
class_modifier  = +3 if scenario category matches class bonus categories
item_bonus      = flat bonus from equipped Tool item (category-specific or global)

Outcome thresholds (final_roll vs DC):
  >= DC + 5  → Critical Success  — 150% XP (× weapon multiplier) + 150% Gold
  >= DC      → Success           — 100% XP (× weapon multiplier) + 100% Gold
  <  DC      → Failure           — 0 XP, −25% base Gold (× armor multiplier)
  <= DC − 5  → Critical Failure  — 0 XP, −50% base Gold (× armor multiplier)
```

**Class roll bonuses:**

| Class | Bonus Categories |
|---|---|
| Investor | investing |
| Debt Slayer | banking, shopping |
| Saver | daily_life, shopping |
| Entrepreneur | work |
| Minimalist | shopping, daily_life |

### Item Store

Three permanent equipment slots (Tool, Armor, Weapon) plus consumable inventory. Buying a new item for an occupied slot **permanently destroys** the current item — no refund. Consumables stack to 5 and are available to re-purchase daily.

**Effect types:**

| Type | What it does |
|---|---|
| `roll_bonus` | Flat addition to d20 roll (category-specific or global) |
| `failure_reduction` | Multiplies Gold penalty on failure (e.g. 0.25 = 25% less penalty) |
| `xp_boost` | Multiplies XP reward on success (e.g. 0.15 = 15% more XP) |
| `action_restore` | Adds daily adventure actions immediately on use |
| `roll_boost_once` | Adds to very next adventure roll, then consumed |
| `reroll_once` | Re-rolls most recent failed adventure (once per day) |

### Portfolio System

- 1 Gold = $1,000 USD (configurable via `gold_to_usd_rate` setting)
- All trades execute at previous market close (`pc` field from Finnhub `/quote`)
- Fractional shares supported to 6 decimal places
- Weighted average cost basis recalculated on each buy
- Daily cron builds `portfolio_snapshots` — % return vs S&P 500 since first trade
- Monthly bonus: 100 Gold for any player whose return beats the index that month
- Leaderboard ranks all players by portfolio % return; players with no portfolio sort to the bottom

### Adding New Adventure Scenarios

All scenarios live in the database. Use `sql/adventure_schema.sql` as a template. Requirements per scenario:

- At least 2 choices
- All four narrative fields: `success_narrative`, `failure_narrative`, `crit_success_narrative`, `crit_failure_narrative`
- `difficulty` between 5 (trivial) and 18 (legendary)
- `min_level` / `max_level` to gate by player level

Toggle active/inactive via Admin → Adventure Manager without touching SQL.

### Adding New Store Items

Insert directly into `store_items`. Refer to `sql/store_schema.sql` for examples. Key fields:

- `effect_type` — one of the six types above
- `effect_value` — flat int for `roll_bonus`/`action_restore`, decimal multiplier for others
- `effect_category` — scenario category name, or `NULL` for all categories
- `level_req` — minimum player level to purchase

---

## Settings Reference

All editable via Admin → Settings without touching the database:

| Key | Default | Description |
|---|---|---|
| `env` | `dev` | Environment: dev or prod |
| `daily_action_limit` | `10` | Adventure actions per player per day |
| `gold_to_usd_rate` | `1000` | 1 Gold = this many USD in portfolio |
| `portfolio_monthly_bonus` | `100` | Gold awarded for beating S&P 500 monthly |
| `finnhub_api_key` | — | Finnhub free tier API key |
| `email_from_address` | — | From address for system emails |
| `email_confirm_xp_reward` | `10` | XP for confirming email address |
| `email_confirmation_enabled` | `1` | Require email confirmation before playing |
| `registration_open` | `1` | Allow new player registrations |
| `maintenance_mode` | `0` | Lock site to admins only |
| `max_level` | `50` | Maximum player level |
| `adventure_enabled` | `1` | Enable/disable adventure module |
| `portfolio_enabled` | `1` | Enable/disable portfolio module |
| `store_enabled` | `1` | Enable/disable item store |
| `consumable_daily_limit` | `5` | Max quantity of each consumable per player |
| `xp_per_dollar_saved` | `10` | XP per dollar logged as savings |
| `xp_per_dollar_invested` | `15` | XP per dollar logged as investment |
| `xp_per_dollar_debt_paid` | `12` | XP per dollar of debt paid |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

MIT — see [LICENSE](LICENSE).
