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

// /backend/* and /api/* are the Phalcon app (admin UI + JSON API).
if (preg_match('#^/(backend|api)(/|$)#', $path)) {
    require $root . '/public/index.php';
    return;
}

// Everything else that isn't a real file (client-side React routes like
// /users, /account) falls back to the built SPA shell.
header('Content-Type: text/html; charset=UTF-8');
readfile($root . '/public/app/index.html');
