# Legends of the Green Dollar

A multiplayer fantasy RPG where your adventures take place in the financial world. Go on adventures, build an S&P 500 portfolio, equip your character, compete on leaderboards, and challenge fellow adventurers to combat.

**Built with:** PHP 8.1 · MySQL 8 · Vanilla JS · DreamHost shared hosting  
**Live at:** [lotgd.money](https://lotgd.money)

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

- **Adventure System** — Face financial scenarios (car dealerships, salary negotiations, market crashes) with RPG-style d20 rolls. Critical success on a natural 20 only. Action costs consumed on choice submission, not scenario display. DB-backed session state eliminates race conditions. 10 actions per day with a live countdown to reset.
- **PvP Combat** — Challenge players at your level or higher to turn-based combat. Initiative rolled once per battle and persists for the entire fight. HP scales with level (base 20 + 2 per level). Gear modifies attack, defense, and damage. Up to 10 rounds with flee option. XP rewards for winner, loser, and draw. Full victory/defeat screen with combat log.
- **Player Profiles** — Public profile pages showing adventure stats, category win rates, portfolio performance, login streak, equipped gear, and achievement history. Item profile pages showing effect, rarity, and who has it equipped. Profile links throughout leaderboard, tavern, dashboard, and portfolio pages.
- **S&P 500 Simulated Portfolio** — Trade any S&P 500 stock using in-game Gold at real previous-close prices. Hourly price updates via Finnhub free tier. SPY used as benchmark. Monthly Gold bonus for beating the index.
- **Leaderboard** — Primary ranking by portfolio % return. Also sortable by XP, level, and login streak. Hall of fame strip for top performers. Class distribution chart.
- **The Tavern** — Community message board with pinning, moderation, and anti-spam cooldown.
- **Item Store** — 15 purchasable items across Tool, Armor, Weapon, and Consumable slots. Gear affects adventure rolls and PvP combat stats. Three permanent equipment slots.
- **Daily Adventurer's Brief** — AI-generated fantasy market recap using Claude Sonnet. Covers S&P 500 movement, top movers, and realm activity. Generated once daily, cached in the settings table.
- **Email via Resend.com** — Resend.com API as primary email driver with automatic PHP mail() fallback. Configurable via admin settings.
- **Admin Panel** — User management, settings editor, adventure scenario manager with full add/edit UI, cron health check with filterable logs.
- **Five Player Classes** — Investor, Debt Slayer, Saver, Entrepreneur, Minimalist — each with +3 roll bonus in specific adventure categories and PvP attack modifiers.
- **Achievement System** — 14 seeded achievements with XP and Gold rewards, awarded automatically.
- **Progressive Leveling** — XP curve tuned for level 2 in a good first day of adventuring. Gold reward on level-up (level × 50 Gold). Level-up banner with animation on the adventure result screen.

---

## Requirements

- PHP 8.1+
- MySQL 8.0+
- Apache with mod_rewrite and `.htaccess` support
- [Finnhub](https://finnhub.io) free API key (stock prices)
- [Resend.com](https://resend.com) free account (email — optional, falls back to PHP mail())
- Anthropic API key (Daily Brief — optional, ~$0.04/month)
- A hosted email address on your domain
- SSH access for cron jobs

---

## Installation

### 1. Clone and configure

```bash
git clone https://github.com/JeremyYowell/lotgd.git
cd lotgd
cp config/config.example.php config/config.php
```

Edit `config/config.php` — fill in database credentials, base URL, timezone, and API keys.

### 2. Create the logs directory

```bash
mkdir logs
chmod 755 logs
chmod 600 config/config.php
```

### 3. Protect sensitive directories

Add an `.htaccess` with `Deny from all` to each of: `config/`, `logs/`, `cron/`

### 4. Force HTTPS

The root `.htaccess` already handles this. Ensure `BASE_URL` in config uses `https://`.

---

## Database Setup

### Run SQL files in order (both dev and prod)

```bash
mysql -u USER -p lotgd_dev < sql/schema.sql
mysql -u USER -p lotgd_dev < sql/portfolio_schema.sql
mysql -u USER -p lotgd_dev < sql/email_confirm_schema.sql
mysql -u USER -p lotgd_dev < sql/adventure_schema.sql
mysql -u USER -p lotgd_dev < sql/store_schema.sql
mysql -u USER -p lotgd_dev < sql/daily_brief_settings.sql
mysql -u USER -p lotgd_dev < sql/adventure_sessions.sql
mysql -u USER -p lotgd_dev < sql/pvp_schema.sql
```

> **Note:** Foreign key constraint names must be globally unique in MySQL. If you see error #1826, drop any partially created PvP tables and re-run `pvp_schema.sql`.

### Grant yourself admin access

After registering your first account:

```sql
UPDATE users SET is_admin = 1, email_confirmed = 1
WHERE username = 'your_username';
```

### Configure required settings

```sql
UPDATE settings SET setting_value = 'your_finnhub_key'
WHERE setting_key = 'finnhub_api_key';

UPDATE settings SET setting_value = 'noreply@yourdomain.com'
WHERE setting_key = 'email_from_address';

-- Optional: Resend.com email driver
UPDATE settings SET setting_value = 'resend' WHERE setting_key = 'email_driver';
UPDATE settings SET setting_value = 're_your_key' WHERE setting_key = 'resend_api_key';

-- Optional: Daily Brief
UPDATE settings SET setting_value = 'sk-ant-your_key' WHERE setting_key = 'claude_api_key';
```

---

## Cron Jobs

### First-time manual run (required)

```bash
# 1. Populate S&P 500 stocks (~500 tickers from Wikipedia)
/usr/local/php81/bin/php /path/to/lotgd/cron/sp500_update.php

# 2. Download first closing prices (~9 minutes due to Finnhub rate limiting)
/usr/local/php81/bin/php /path/to/lotgd/cron/price_update.php

# 3. Generate first Daily Adventurer's Brief (requires claude_api_key)
/usr/local/php81/bin/php /path/to/lotgd/cron/generate_brief.php --force
```

### Recurring cron schedule

| Schedule | Expression | Command |
|---|---|---|
| Hourly price update (weekdays) | `0 * * * 1-5` | `php /path/cron/price_update.php` |
| Daily Brief (weekdays) | `0 9 * * 1-5` | `php /path/cron/generate_brief.php` |
| S&P 500 update — January | `0 7 2 1 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — April | `0 7 1 4 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — July | `0 7 1 7 *` | `php /path/cron/sp500_update.php` |
| S&P 500 update — October | `0 7 1 10 *` | `php /path/cron/sp500_update.php` |

> Use the full PHP path on DreamHost: `/usr/local/php81/bin/php`
> Append `>> /path/logs/cron_brief.log 2>&1` to capture output for debugging.

**`price_update.php` does each hour:**
1. Pulls previous-close prices for all ~500 S&P 500 tickers + SPY from Finnhub
2. Builds portfolio snapshots for all users with holdings
3. Refreshes the portfolio leaderboard cache
4. On the last business day of the month: awards Gold bonus to users beating SPY

**`generate_brief.php` does each morning:**
1. Pulls SPY movement and top S&P 500 movers from the local database
2. Fetches up to 3 Finnhub market headlines
3. Gathers realm stats (adventures, achievements, leaderboard changes)
4. Sends one Claude Sonnet API call, caches result in `daily_brief_html` setting

---

## Project Structure

```
lotgd/
├── admin/
│   ├── index.php               Admin dashboard
│   ├── users.php               User management (ban, confirm, delete)
│   ├── settings.php            In-browser settings editor (API keys masked)
│   ├── adventures.php          Scenario manager — full add/edit/delete UI
│   └── cron.php                Cron health + filterable log windows
├── api/
│   └── stock_search.php        JSON endpoint for portfolio live stock search
├── assets/css/
│   ├── main.css                Global styles and design system
│   ├── dashboard.css
│   ├── adventure.css           Adventure page + level-up banner animations
│   ├── leaderboard.css
│   ├── tavern.css
│   ├── portfolio.css
│   ├── store.css
│   ├── brief.css               Daily Brief card styles
│   ├── profile.css             Player profile and item profile pages
│   ├── pvp.css                 PvP combat page + HP meters
│   └── admin.css
├── config/
│   ├── config.example.php      ← commit this
│   └── config.php              ← DO NOT commit (.gitignore'd)
├── cron/
│   ├── price_update.php        Hourly prices + snapshots + leaderboard
│   ├── sp500_update.php        Quarterly S&P 500 constituent scraper
│   └── generate_brief.php      Daily AI brief generator (run separately)
├── lib/
│   ├── Database.php            PDO singleton with query helpers
│   ├── Session.php             Auth, CSRF protection, flash messages
│   ├── User.php                User model, XP/leveling, Gold rewards, HP
│   ├── Portfolio.php           Portfolio trading, snapshots, leaderboard
│   ├── Adventure.php           d20 roll engine, scenario selection
│   ├── Store.php               Item store, inventory, effect calculation
│   ├── DailyBrief.php          Claude API call, market data, HTML render
│   ├── Mailer.php              Email via Resend.com API or PHP mail()
│   └── Pvp.php                 PvP combat engine, initiative, HP, XP
├── pages/
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── adventure.php           DB-backed session state, countdown timer
│   ├── leaderboard.php         Portfolio return primary sort
│   ├── tavern.php
│   ├── portfolio.php           Top performers + The Dungeon
│   ├── store.php
│   ├── pvp.php                 PvP combat (idle / fighting / result states)
│   ├── profile.php             Public player profile
│   ├── item.php                Public item profile with rarity + who has it
│   ├── confirm_email.php
│   └── confirm_required.php
├── sql/
│   ├── schema.sql
│   ├── portfolio_schema.sql
│   ├── email_confirm_schema.sql
│   ├── adventure_schema.sql        13 seeded scenarios across 6 categories
│   ├── store_schema.sql            15 seeded items
│   ├── daily_brief_settings.sql
│   ├── adventure_sessions.sql      DB-backed adventure state
│   ├── pvp_schema.sql              PvP sessions, log, and stats tables
│   ├── pvp_initiative_migration.sql  Adds initiative_order column
│   ├── email_driver_settings.sql   Resend.com driver settings
│   └── xp_curve_migration.sql      Updates existing users to new XP curve
├── templates/
│   ├── layout.php              Master HTML with hamburger nav
│   └── maintenance.php
├── landing.php                 Marketing landing page (shown to guests)
├── bootstrap.php               App entry — redirects to /setup/ if not installed
├── index.php                   Guests → landing page, logged in → dashboard
├── 404.php                     Auto-redirect with countdown
├── .gitignore
├── .htaccess                   HTTPS redirect, security rules
├── LICENSE
├── README.md
└── CONTRIBUTING.md
```

---

## Architecture

### Request Flow

Every page starts with `require_once bootstrap.php` which:

1. Redirects to `/setup/` if `config/config.php` doesn't exist yet
2. Loads `config/config.php` constants
3. Registers PSR-0 autoloader for `lib/` classes
4. `Session::start()` — secure cookie settings, CSRF token
5. `$db = Database::getInstance()` — PDO singleton
6. Session expiry detection — expired sessions redirect cleanly to login
7. Maintenance mode gate
8. Email confirmation gate

### Database Access

```php
$db->fetchOne($sql, $params)    // single row or false
$db->fetchAll($sql, $params)    // all rows
$db->fetchValue($sql, $params)  // single scalar value
$db->run($sql, $params)         // execute, returns PDOStatement
$db->getSetting($key, $default) // settings table lookup
$db->setSetting($key, $value)   // settings table update
$db->beginTransaction()
$db->commit()
$db->rollBack()
```

### Environment Switching

One line in `config/config.php`:
```php
define('APP_ENV', 'dev');  // or 'prod'
```
Controls: database name, error display, debug banner, secure cookies.

---

## Game Systems

### Adventure Roll Mechanic

```
final_roll = d20 + level_modifier + class_modifier

level_modifier = floor(level / 5)        // +1 per 5 levels
class_modifier = +3 if scenario category matches class bonus

Outcomes:
  natural 20 (raw die)  → Critical Success  — 150% XP + Gold
  final_roll >= DC       → Success           — 100% XP + Gold
  final_roll < DC        → Failure           — 0 XP, -25% Gold
  final_roll < DC - 4    → Critical Failure  — 0 XP, -50% Gold
```

DC values are hidden from players. Critical success requires a natural 20 on the d20, not just beating the DC by a margin.

| Class | Bonus Categories |
|---|---|
| Investor | investing |
| Debt Slayer | banking, shopping |
| Saver | daily_life, shopping |
| Entrepreneur | work |
| Minimalist | shopping, daily_life |

### Leveling System

```
XP formula: FLOOR(250 * level^1.8)

Level 2:  ~870 XP   (~1 day of adventuring)
Level 3:  ~1,806 XP (~3 days)
Level 5:  ~4,529 XP (~7 days)
Level 10: ~15,773 XP (~24 days)

HP per level: 20 + (level - 1) * 2
  Level 1: 20 HP   Level 5: 28 HP   Level 10: 38 HP   Level 50: 118 HP

Gold reward on level-up: level × 50 Gold
```

### PvP Combat System

```
Initiative: d20 + floor(level/5) — rolled ONCE at fight start, persists all rounds
Attack:     d20 + floor(level/5) + weapon_bonus vs defender d20 + floor(level/5) + armor_bonus
Damage:     rand(3, 8) + weapon_effect_value
Flee DC:    12 (roll d20 + floor(level/5) to escape)
Max rounds: 10 (draw if neither fighter falls)

Gear effects in PvP:
  Weapon → +2 attack modifier + damage bonus
  Armor  → +2 defense modifier + HP bonus (based on failure_reduction value)

XP rewards:
  Win:  50 XP + (level_difference × 10) bonus XP
  Draw: 15 XP each
  Loss: 5 XP
  Flee: 0 XP
```

Rules: challengers may only target players at their level or higher. One challenge per target per day.

### Portfolio System

- 1 Gold = $1,000 USD (configurable via `gold_to_usd_rate`)
- Trades at previous market close price from Finnhub `pc` field
- Fractional shares to 6 decimal places, weighted average cost basis
- SPY used as benchmark (available on Finnhub free tier)
- Hourly snapshots during market hours
- Monthly 100 Gold bonus for players beating SPY

### Item Store

Three permanent equipment slots (Tool, Armor, Weapon). Buying replaces and destroys the current item — no refund. Consumables stack to 5 and replenish daily.

| Effect Type | Description |
|---|---|
| `roll_bonus` | Flat addition to d20 roll for a specific category or all |
| `failure_reduction` | Multiplies Gold penalty on failure (also grants PvP HP bonus) |
| `xp_boost` | Multiplies XP on success (also grants PvP attack bonus) |
| `action_restore` | Adds daily adventure actions |
| `roll_boost_once` | One-time +roll bonus, then consumed |
| `reroll_once` | Re-roll most recent failed adventure |

### Email Delivery

Two drivers configurable via Admin → Settings:

| Setting | Value | Behavior |
|---|---|---|
| `email_driver` | `resend` | Resend.com API (recommended) |
| `email_driver` | `php` | PHP mail() fallback |

If Resend fails, the system automatically falls back to PHP mail(). Set `resend_api_key` in settings after verifying your domain at resend.com.

---

## Settings Reference

All editable via Admin → Settings:

| Key | Default | Description |
|---|---|---|
| `daily_action_limit` | `10` | Adventures per player per day |
| `gold_to_usd_rate` | `1000` | 1 Gold = this many USD |
| `portfolio_monthly_bonus` | `100` | Gold for beating SPY monthly |
| `finnhub_api_key` | — | Finnhub free tier key (masked) |
| `claude_api_key` | — | Anthropic API key for Daily Brief (masked) |
| `email_from_address` | — | System email from address |
| `email_driver` | `php` | `php` or `resend` |
| `resend_api_key` | — | Resend.com API key (masked) |
| `email_confirm_xp_reward` | `10` | XP for confirming email |
| `email_confirmation_enabled` | `1` | Require email confirmation to play |
| `registration_open` | `1` | Allow new registrations |
| `maintenance_mode` | `0` | Lock site to admins only |
| `max_level` | `50` | Maximum player level |
| `adventure_enabled` | `1` | Enable adventure module |
| `portfolio_enabled` | `1` | Enable portfolio module |
| `store_enabled` | `1` | Enable item store |
| `daily_brief_enabled` | `1` | Show Daily Brief on dashboard |
| `consumable_daily_limit` | `5` | Max consumable uses per day |

---

## Adventure Scenarios

Add and edit scenarios via Admin → Adventure Manager. Each scenario needs:

- At least 2 choices
- All four narrative fields per choice (success, failure, crit success, crit failure)
- A difficulty (DC) between 8 and 18 — DC values are hidden from players
- Min/max level range

DC values below 8 are not recommended — even a roll of 1 should carry meaningful failure risk.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

MIT — see [LICENSE](LICENSE).
