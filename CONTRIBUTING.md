# Contributing to Legends of the Green Dollar

Thanks for your interest in contributing. This is a personal finance RPG — contributions that improve the game mechanics, add adventure scenarios, improve accessibility, or fix bugs are all welcome.

## Getting Started

1. Fork the repository
2. Clone your fork and follow the [Installation](README.md#installation) steps
3. Create a feature branch: `git checkout -b feature/your-feature-name`
4. Make your changes
5. Test against the dev database (`lotgd_dev`)
6. Submit a pull request with a clear description of what changed and why

## Code Style

- PHP files use 4-space indentation
- Class files live in `lib/` and are autoloaded by name (e.g. `Portfolio` → `lib/Portfolio.php`)
- All DB queries use parameterized statements via the `Database` helper — no raw string interpolation into SQL
- Output is always escaped with `e()` before rendering in HTML
- New pages follow the pattern: `require_once bootstrap.php` → data fetching → `ob_start()` → HTML → `$pageContent = ob_get_clean()` → `require layout.php`

## Adding Adventure Scenarios

The easiest contribution is new adventure scenarios. Add them to `sql/adventure_schema.sql` following the existing pattern. Good scenarios:

- Are grounded in real personal finance situations people actually face
- Have 2–3 meaningful choices with genuinely different risk/reward profiles
- Give each choice a `hint_text` that hints at the approach without giving away the outcome
- Have all four narrative fields written with some personality and flavor
- Set difficulty between 5 (trivial) and 18 (legendary)

## Reporting Bugs

Open a GitHub issue with:
- What you expected to happen
- What actually happened
- Steps to reproduce
- PHP/MySQL version if relevant

## What We Won't Accept

- Changes that introduce external PHP dependencies (no Composer packages)
- Features that require paid third-party APIs
- Anything that stores or processes real financial account data
- Changes to the MIT license

## Questions

Open a GitHub Discussion or an issue tagged `question`.
