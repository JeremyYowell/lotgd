<?php
/**
 * diag.php — Temporary diagnostic. DELETE after debugging.
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Bootstrap OK ===\n";
echo "IS_DEV: " . (IS_DEV ? 'true' : 'false') . "\n";
echo "Session logged in: " . (Session::isLoggedIn() ? 'true' : 'false') . "\n";
echo "User ID: " . Session::userId() . "\n";
echo "PHP version: " . PHP_VERSION . "\n\n";

// Test 1: Can Onboarding class be loaded?
echo "=== Test 1: Onboarding class load ===\n";
try {
    $exists = class_exists('Onboarding');
    echo "class_exists(Onboarding): " . ($exists ? 'true' : 'false') . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Test 2: isDismissed call
echo "\n=== Test 2: isDismissed ===\n";
try {
    $userId = (int) Session::userId();
    echo "userId: $userId\n";
    $result = Onboarding::isDismissed($userId, 'onboard_tavern');
    echo "isDismissed result: " . var_export($result, true) . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo "In: " . $e->getFile() . ' line ' . $e->getLine() . "\n";
}

// Test 3: ob_start + isDismissed inside buffer
echo "\n=== Test 3: isDismissed inside ob_start ===\n";
ob_start();
try {
    $userId = (int) Session::userId();
    if (!Onboarding::isDismissed($userId, 'onboard_tavern')) {
        echo "[tip would show]\n";
    } else {
        echo "[tip dismissed]\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION inside ob: " . $e->getMessage() . "\n";
}
$buf = ob_get_clean();
echo "Buffer content: " . $buf . "\n";

// Test 4: DB query for portfolio_holdings
echo "\n=== Test 4: portfolio_holdings query ===\n";
try {
    $userId = (int) Session::userId();
    $result = $db->fetchValue(
        "SELECT 1 FROM portfolio_holdings WHERE user_id = ? AND shares > 0 LIMIT 1",
        [$userId]
    );
    echo "portfolio_holdings query OK, result: " . var_export($result, true) . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . get_class($e) . ': ' . $e->getMessage() . "\n";
}

// Test 5: user_dismissals table
echo "\n=== Test 5: user_dismissals table ===\n";
try {
    $count = $db->fetchValue("SELECT COUNT(*) FROM user_dismissals");
    echo "user_dismissals table exists, rows: $count\n";
} catch (Throwable $e) {
    echo "FAILED (table likely missing): " . $e->getMessage() . "\n";
}

echo "\n=== All tests complete ===\n";
