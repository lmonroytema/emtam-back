<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

function findLaravelBasePath(string $publicDir): ?string
{
    $candidates = [
        realpath($publicDir.'/..') ?: null,
        realpath($publicDir.'/backend') ?: null,
        realpath($publicDir.'/emta-backend') ?: null,
        realpath($publicDir.'/laravel') ?: null,
        realpath($publicDir.'/../emta-backend') ?: null,
        realpath($publicDir.'/../laravel') ?: null,
        getenv('LARAVEL_BASE_PATH') ?: null,
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }

        $autoload = $candidate.'/vendor/autoload.php';
        $bootstrap = $candidate.'/bootstrap/app.php';

        if (is_file($autoload) && is_file($bootstrap)) {
            return $candidate;
        }
    }

    return null;
}

$basePath = findLaravelBasePath(__DIR__);

if (!$basePath) {
    http_response_code(500);
    echo 'No se pudo localizar el proyecto Laravel (vendor/autoload.php).';
    exit(1);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basePath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
