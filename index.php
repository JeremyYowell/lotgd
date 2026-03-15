<?php
/**
 * index.php — Application root
 * Redirects logged-in users to the game, guests to login/register.
 */
require_once __DIR__ . '/bootstrap.php';

if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
} else {
    redirect('/pages/login.php');
}
