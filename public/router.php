<?php
/**
 * Router script for PHP built-in web server.
 * This script routes all requests through index.php for Symfony routing.
 */

// Decline static file requests back to the PHP built-in webserver
if (php_sapi_name() === 'cli-server') {
    $path = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    
    // Check if file exists and is not index.php
    if ($path && is_file($path) && $path !== __FILE__) {
        return false;
    }
}

// Otherwise, route through index.php
require __DIR__ . '/index.php';

