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

    // Push an alert — a retired Claude model 404'd every day from 2026-06-16
    // to 2026-09-28 with nothing but this log line to show for it.
    $detail = 'See logs/app.log.';
    $appLog = ROOT_PATH . '/logs/app.log';
    if (is_readable($appLog)) {
        $errors = preg_grep('/\[ERROR\] DailyBrief:/', array_slice(file($appLog), -200));
        if ($errors) $detail = trim(substr(end($errors), 0, 400));
    }
    if (defined('NTFY_TOPIC') && NTFY_TOPIC) {
        $base = defined('NTFY_URL') ? rtrim(NTFY_URL, '/') : 'https://ntfy.sh';
        @file_get_contents($base . '/' . NTFY_TOPIC, false, stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Title: LotGD Daily Brief failed\r\nPriority: high\r\nTags: warning,scroll",
                'content'       => $detail,
                'timeout'       => 5,
                'ignore_errors' => true,
            ]
        ]));
        echo "[" . date('Y-m-d H:i:s') . "] ntfy alert sent.\n";
    }
    exit(1);
}
