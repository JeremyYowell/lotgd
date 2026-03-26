<?php
/**
 * api/dismiss_onboarding.php
 * =============================================================================
 * AJAX endpoint — records that the current user dismissed an onboarding tip.
 *
 * POST params:
 *   csrf_token  string  required
 *   flag        string  one of Onboarding::VALID_FLAGS
 *
 * Response: JSON { "ok": true } or { "error": "..." } with 4xx status.
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Must be logged in
if (!Session::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
Session::verifyCsrfPost();

$flag   = trim($_POST['flag'] ?? '');
$userId = Session::userId();

if (!in_array($flag, Onboarding::VALID_FLAGS, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid flag']);
    exit;
}

Onboarding::dismiss($userId, $flag);
echo json_encode(['ok' => true]);
