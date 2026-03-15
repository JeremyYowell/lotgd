# Legends of the Green Dollar

A multiplayer personal finance RPG web game inspired by Legend of the Red Dragon (LORD). Players go on financial adventures, build simulated investment portfolios, compete on leaderboards, and level up by making smart real-world financial decisions.

**Built with:** PHP 8.1 · MySQL 8 · Vanilla JS · DreamHost shared hosting

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Cron Jobs](#cron-jobs)
- [Project Structure](#project-structure)
- [Architecture](#architecture)
- [Settings Reference](#settings-reference)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **Adventure System** — Players face real-world financial scenarios (car dealerships, salary negotiations, market crashes) with RPG-style d20 rolls and class-based modifiers
- **S&P 500 Simulated Portfolio** — Trade any S&P 500 stock using in-game Gold, with daily price updates from Finnhub. Portfolio return compared against the S&P 500 index
- **Leaderboard** — Ranked by wealth score, XP, savings, debt paid, and more. Top 10 and bottom 10 portfolio performers
- **The Tavern** — Community message board with pinning, moderation, and anti-spam cooldown
- **Email Confirmation** — Required before playing, with resend support and XP reward on confirm
- **Admin Panel** — User management, settings editor, adventure scenario manager, cron health check
- **Five Player Classes** — Investor, Debt Slayer, Saver, Entrepreneur, Minimalist — each with adventure roll bonuses
- **Achievement System** — 14 seeded achievements with XP and Gold rewards
- **Daily Action Limits** — Players get 10 adventures per day, resetting at midnight

---

## Requirements

- PHP 8.1+
- MySQL 8.0+
- A web server with mod_rewrite (Apache on DreamHost shared hosting works out of the box)
- A [Finnhub](https://finnhub.io) free API key (for daily stock price updates)
- A hosted email address on your domain (for confirmation emails via PHP `mail()`)
- SSH access to run cron jobs and manual scripts

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
- Your base URL (dev and prod)
- Timezone

### 3. Create required directories

```bash
mkdir logs
chmod 755 logs
```

### 4. Protect sensitive directories

Create `.htaccess` files in these directories to block direct HTTP access:

**`config/.htaccess`** and **`logs/.htaccess`** and **`cron/.htaccess`:**
```apache
Order deny,allow
Deny from all
```

### 5. Set file permissions on config

```bash
chmod 600 config/config.php
```

---

## Database Setup

### Create databases

Create two MySQL databases in your hosting panel:
- `lotgd_dev` (development)
- `lotgd_prod` (production)

### Run SQL files in order

Run each file against **both** databases:

```bash
# 1. Core schema (users, sessions, challenges, achievements, leaderboard)
mysql -u USER -p lotgd_dev < sql/schema.sql

# 2. Portfolio module (stocks, prices, holdings, trades, snapshots)
mysql -u USER -p lotgd_dev < sql/portfolio_schema.sql

# 3. Email confirmation columns
mysql -u USER -p lotgd_dev < sql/email_confirm_schema.sql

# 4. Adventure system (scenarios, choices, log — includes all seeded content)
mysql -u USER -p lotgd_dev < sql/adventure_schema.sql
```

### Grant yourself admin access

After registering your first account:

```sql
UPDATE users SET is_admin = 1, email_confirmed = 1
WHERE username = 'your_username';
```

### Add your Finnhub API key

```sql
UPDATE settings
SET setting_value = 'your_finnhub_key_here'
WHERE setting_key = 'finnhub_api_key';
```

### Update the from email address

```sql
UPDATE settings
SET setting_value = 'noreply@yourdomain.com'
WHERE setting_key = 'email_from_address';
```

---

## Cron Jobs

### First-time manual run (required before cron is active)

Run these once manually via SSH, in this order:

```bash
# 1. Populate the S&P 500 stocks table (~500 rows from Wikipedia)
php /path/to/lotgd/cron/sp500_update.php

# 2. Download first closing prices and set SPX inception baseline
#    Takes ~9-10 minutes due to Finnhub rate limiting
php /path/to/lotgd/cron/price_update.php
```

### Recurring cron schedule

Set these up in your hosting control panel:

| Schedule | Expression | Command |
|---|---|---|
| Nightly price update (weekdays) | `0 18 * * 1-5` | `php /path/cron/price_update.php` |
| S&P 500 update — January | `0 7 2 1 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — April | `0 7 1 4 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — July | `0 7 1 7 1-5` | `php /path/cron/sp500_update.php` |
| S&P 500 update — October | `0 7 1 10 1-5` | `php /path/cron/sp500_update.php` |

**What `price_update.php` does each night:**
1. Pulls previous-close prices for all ~500 S&P 500 tickers + `^GSPC` from Finnhub
2. Builds daily portfolio snapshots for all users with holdings
3. Refreshes the portfolio leaderboard cache
4. On the last business day of the month: awards 100 Gold to users who beat the S&P 500

**What `sp500_update.php` does quarterly:**
1. Scrapes the S&P 500 constituent list from Wikipedia
2. Adds new entrants, marks removed stocks as inactive
3. Logs all changes to `logs/cron_sp500.log`

### Monitoring cron health

Visit the admin panel → Cron Health to see last run timestamps and log tails. Or check directly:

```bash
tail -50 /path/to/lotgd/logs/cron_prices.log
tail -50 /path/to/lotgd/logs/cron_sp500.log
```

---

## Project Structure

```
lotgd/
├── admin/                  Admin panel pages
│   ├── index.php           Dashboard with stats
│   ├── users.php           User management
│   ├── settings.php        Settings editor
│   ├── adventures.php      Scenario manager
│   ├── cron.php            Cron health check
│   └── .htaccess           Disables directory listing
├── api/
│   └── stock_search.php    JSON endpoint for portfolio stock search
├── assets/
│   └── css/
│       ├── main.css        Global styles and design system
│       ├── dashboard.css
│       ├── adventure.css
│       ├── leaderboard.css
│       ├── tavern.css
│       ├── portfolio.css
│       └── admin.css
├── config/
│   ├── config.example.php  ← commit this
│   └── config.php          ← DO NOT commit (in .gitignore)
├── cron/
│   ├── price_update.php    Nightly stock price + snapshot cron
│   └── sp500_update.php    Quarterly S&P 500 constituent update
├── lib/
│   ├── Database.php        PDO singleton with helper methods
│   ├── Session.php         Auth, CSRF, flash messages
│   ├── User.php            User model, XP, achievements
│   ├── Portfolio.php       Portfolio trading and snapshots
│   ├── Adventure.php       Adventure engine and roll mechanics
│   └── Mailer.php          System email sender
├── logs/                   Runtime logs (not committed)
├── pages/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── adventure.php
│   ├── leaderboard.php
│   ├── tavern.php
│   ├── portfolio.php
│   ├── confirm_email.php
│   └── confirm_required.php
├── sql/
│   ├── schema.sql              Core schema + seed data
│   ├── portfolio_schema.sql    Portfolio module schema
│   ├── email_confirm_schema.sql Email confirmation columns
│   └── adventure_schema.sql    Adventure system + 13 seeded scenarios
├── templates/
│   ├── layout.php          Master HTML wrapper
│   └── maintenance.php     Maintenance mode page
├── bootstrap.php           Application entry point (included by every page)
├── index.php               Root redirect
├── .gitignore
├── LICENSE
├── README.md
└── CONTRIBUTING.md
```

---

## Architecture

### Request Flow

Every public PHP page begins with:
```php
require_once __DIR__ . '/../bootstrap.php';
```

`bootstrap.php` handles, in order:
1. Load `config/config.php` (constants)
2. Register PSR-0-style autoloader for `lib/` classes
3. Start session (`Session::start()`)
4. Get DB instance (`$db = Database::getInstance()`)
5. Check maintenance mode
6. Check email confirmation gate (redirects unconfirmed users)

### Database Access

All DB access goes through `Database::getInstance()` which returns a singleton PDO wrapper. Helper methods:

```php
$db->fetchOne($sql, $params)   // single row
$db->fetchAll($sql, $params)   // all rows
$db->fetchValue($sql, $params) // single value
$db->run($sql, $params)        // execute, returns PDOStatement
$db->getSetting($key, $default)// settings table lookup (cached per request)
$db->setSetting($key, $value)  // settings table update
```

### Adventure Roll Mechanic

```
final_roll = d20 + level_modifier + class_modifier

level_modifier = floor(level / 5)       // +1 per 5 levels, max +10
class_modifier = +3 if scenario category matches class bonus category

Outcomes vs difficulty (DC):
  final_roll >= DC + 5  → Critical Success (150% XP + 150% Gold)
  final_roll >= DC      → Success (100% XP + 100% Gold)
  final_roll < DC       → Failure (0 XP, -25% base Gold)
  final_roll <= DC - 5  → Critical Failure (0 XP, -50% base Gold)
```

### Portfolio System

- 1 Gold = $1,000 USD (configurable via `gold_to_usd_rate` setting)
- Trades execute at previous market close price (`pc` field from Finnhub `/quote`)
- Fractional shares stored to 6 decimal places
- Average cost basis recalculated on each buy using weighted average
- Daily cron builds `portfolio_snapshots` for every user with holdings
- Monthly bonus: 100 Gold awarded to users whose % return beats SPX

### Environment Switching

Change one line in `config/config.php`:
```php
define('APP_ENV', 'dev');   // or 'prod'
```

This controls: database name, error display, debug mode, secure cookies, and the dev environment banner in the footer.

---

## Settings Reference

Key settings stored in the `settings` table, editable via the admin panel:

| Key | Default | Description |
|---|---|---|
| `env` | `dev` | Environment designation |
| `daily_action_limit` | `10` | Adventures per player per day |
| `gold_to_usd_rate` | `1000` | 1 Gold = this many USD |
| `portfolio_monthly_bonus` | `100` | Gold for beating S&P 500 monthly |
| `finnhub_api_key` | — | Your Finnhub free tier API key |
| `email_from_address` | — | Confirmation email from address |
| `email_confirm_xp_reward` | `10` | XP for confirming email |
| `email_confirmation_enabled` | `1` | Require email confirmation |
| `registration_open` | `1` | Allow new registrations |
| `maintenance_mode` | `0` | Lock site to admins only |
| `max_level` | `50` | Maximum player level |
| `adventure_enabled` | `1` | Enable adventure module |
| `portfolio_enabled` | `1` | Enable portfolio module |

---

## Adding New Adventure Scenarios

New scenarios are added via SQL. Use the existing entries in `sql/adventure_schema.sql` as a template. Each scenario requires:

- At least 2 choices
- All four narrative fields per choice: `success_narrative`, `failure_narrative`, `crit_success_narrative`, `crit_failure_narrative`
- A `difficulty` between 5 (easy) and 18 (legendary)

Toggle scenarios active/inactive via the admin panel without touching the database.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

MIT — see [LICENSE](LICENSE).
