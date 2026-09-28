<?php

declare(strict_types=1);

/*
 * Open Brand Exchange node (PHP). Every request goes through this file.
 * Web root = this "public" folder. See README.md for installation.
 */

$root = dirname(__DIR__);

// Never show PHP warnings to visitors (some hosts ship display_errors=On); they go to the log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (PHP_SAPI === 'cli-server') {
    // php -S: let the built-in server serve real files in public/ (not index.php itself).
    $file = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file) && !str_ends_with($file, '.php') && !str_contains($file, '/ui/')) {
        return false;
    }
}

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This portal needs PHP 8.2 or newer (this server runs PHP " . PHP_VERSION . ").\nChoose a newer PHP version in your hosting control panel.\n";
    exit;
}

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The vendor/ folder is missing. Upload the complete release zip, or run `composer install --no-dev` in a development checkout.\n";
    exit;
}

require $root . '/vendor/autoload.php';

Obx\Node\Web::run($root);
