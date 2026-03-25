<?php
/**
 * api/voice_mode.php — Toggle voice mode preference for current user
 */
require_once __DIR__ . '/../bootstrap.php';
Session::requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST required']);
    exit;
}

Session::verifyCsrfPost(); // expects $_POST['csrf_token']

$enabled = !empty($_POST['enabled']) && $_POST['enabled'] === '1' ? 1 : 0;
$userId  = Session::userId();

$db->run("UPDATE users SET voice_mode = ? WHERE id = ?", [$enabled, $userId]);

echo json_encode(['ok' => true, 'voice_mode' => $enabled]);
