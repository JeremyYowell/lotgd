#!/usr/bin/env php
<?php
/**
 * cron/generate_brief.php — Manual brief generation trigger
 * ==========================================================
 * Run this any time to regenerate the daily brief immediately.
 * Useful for testing and for the first run after deployment.
 *
 * Usage:
 *   php /path/to/lotgd/cron/generate_brief.php
 *
 * Force regeneration (ignores today's cache):
 *   php /path/to/lotgd/cron/generate_brief.php --force
 */

define('CRON_RUNNING', true);
require_once __DIR__ . '/../bootstrap.php';

$force = in_array('--force', $argv ?? []);

echo "[" . date('Y-m-d H:i:s') . "] Generating Daily Adventurer's Brief...\n";
if ($force) echo "[" . date('Y-m-d H:i:s') . "] Force mode — ignoring existing cache.\n";

$brief = new DailyBrief();
$ok    = $brief->generate($force);

if ($ok) {
    echo "[" . date('Y-m-d H:i:s') . "] Success! Brief generated for " . $brief->getLastDate() . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] View it at your dashboard URL.\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Failed. Check that:\n";
    echo "  - Stock prices have been loaded (run price_update.php first)\n";
    echo "  - ANTHROPIC_API_KEY is set in config.php\n";
    echo "  - finnhub_api_key is set in the settings table\n";
}
