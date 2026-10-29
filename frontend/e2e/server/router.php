<?php

/**
 * Router for PHP's built-in server in the Playwright smoke run
 * (playwright.config.ts): backend/.htaccess's routing, so the run exercises what
 * production serves — the built SPA under og.php's Content-Security-Policy and
 * the real API. Started with the built SPA (frontend/dist) as the document root.
 */

declare(strict_types=1);

$backend = dirname(__DIR__, 3) . '/backend';
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// /api/* → the Slim app, run from api/ as Apache runs it.
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    chdir($backend . '/api');
    require $backend . '/api/index.php';
    return true;
}

// Deck images and other uploads live outside dist/, under backend/assets.
if (str_starts_with($uri, '/assets/')) {
    $file = realpath($backend . $uri);
    if ($file === false || !str_starts_with($file, realpath($backend . '/assets') . DIRECTORY_SEPARATOR) || !is_file($file)) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
    readfile($file);
    return true;
}

// A built file: the server sends it from the document root, with its MIME type.
if ($uri !== '/' && $uri !== '/index.html' && is_file($_SERVER['DOCUMENT_ROOT'] . $uri)) {
    return false;
}

// A missing asset is a 404, not the app shell (as in .htaccess).
if (preg_match('#^/(plugin|_app|fonts)/#', $uri) === 1) {
    http_response_code(404);
    return true;
}

// Every page: the shell, through og.php.
require $backend . '/og.php';
return true;
