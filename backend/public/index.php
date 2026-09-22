<?php

declare(strict_types=1);

use Leads\Core\App;
use Leads\Core\Cors;
use Leads\Core\Env;
use Leads\Core\Request;
use Leads\Http\Routes;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
date_default_timezone_set('UTC');
ini_set('display_errors', Env::bool('APP_DEBUG') ? '1' : '0');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
Cors::apply();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    return;
}

$app = new App($root);
$router = Routes::build($app);

try {
    $request = Request::fromGlobals();
} catch (\Leads\Core\HttpException $e) {
    \Leads\Core\Response::error($e->getMessage(), $e->status())->send();
    return;
}

$router->dispatch($request)->send();
