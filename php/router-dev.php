<?php
/**
 * Dev router for PHP's built-in server (php -S). NOT used in production —
 * production uses .htaccess. This mimics .htaccess: serve real files
 * (assets, install.php), route everything else through index.php.
 *
 * Note: -t is set to the php/ folder, so paths here are relative to php/.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;

// Serve install.php directly
if ($uri === '/install.php') {
    require __DIR__ . '/install.php';
    return true;
}

// Serve real static files (css, js, images, uploads)
if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server serve it as-is
}

// Everything else -> front controller
require __DIR__ . '/index.php';
