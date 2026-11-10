<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Allow encrypted workspace payloads to be saved within a bounded PHP memory limit.
if (ini_get('memory_limit') !== '-1' && ini_parse_quantity((string) ini_get('memory_limit')) < 512 * 1024 * 1024) {
    ini_set('memory_limit', '512M');
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
