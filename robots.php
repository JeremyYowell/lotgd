<?php
/**
 * robots.php — Served as /robots.txt via .htaccess rewrite.
 * Dev environment blocks all crawlers; prod allows the landing page only.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

if (IS_DEV):
?>
# Dev environment — block all crawlers
User-agent: *
Disallow: /
<?php else: ?>
User-agent: *
Allow: /$
Allow: /pages/privacy.php

# Game pages require login — no value in crawling
Disallow: /pages/
Disallow: /admin/
Disallow: /api/
Disallow: /cron/
Disallow: /lib/
Disallow: /sql/
Disallow: /config/
Disallow: /templates/
Disallow: /logs/
Disallow: /assets/audio/

# Allow CSS, JS, and images so crawlers can render the landing page
Allow: /assets/css/
Allow: /assets/img/

Sitemap: https://lotgd.money/sitemap.xml
<?php endif; ?>
