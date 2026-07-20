<?php

/**
 * Router script for PHP's built-in server, mirroring public/.htaccess
 * (which Apache reads but `php -S` doesn't).
 *
 * Usage, from the project root:
 *   php -S localhost:8080 -t public bin/dev-router.php
 */

$root = dirname(__DIR__);
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Real files (assets, built JS/CSS, ...) are served directly by the built-in
// server itself once we return false.
if ($path !== '/' && file_exists($root . '/public' . $path)) {
    return false;
}

// Everything else goes to the Phalcon app (server-rendered AdminLTE
// backend + JSON api module).
require $root . '/public/index.php';
