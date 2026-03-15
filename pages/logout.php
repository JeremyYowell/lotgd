<?php
/**
 * pages/logout.php
 */
require_once __DIR__ . '/../bootstrap.php';

Session::requireLogin();
Session::logout();
Session::start(); // restart to set flash on fresh session
Session::setFlash('info', 'You have left the realm. Your progress has been saved.');
redirect('/pages/login.php');
