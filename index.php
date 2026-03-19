<?php
/**
 * index.php — Application root
 * Logged-in users → dashboard
 * Guests → marketing landing page
 */
require_once __DIR__ . '/bootstrap.php';

if (Session::isLoggedIn()) {
    redirect('/pages/dashboard.php');
}

// Show the landing page for guests
require_once __DIR__ . '/landing.php';
