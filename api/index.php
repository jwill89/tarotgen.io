<?php

// Required Autoloader
require_once('../vendor/autoload.php');

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use DI\Container;
use Routes\Internal\{DeckController,
    ReadingController};

// Create Container using PHP-DI
$container = new Container();

// Register Container
AppFactory::setContainer($container);

// Setup the app/log
$app = AppFactory::create();

// Set base path
$app->setBasePath('/api');

// Setup Middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Setup Error Handling
$error_middleware = $app->addErrorMiddleware(true, true, true);

// Setup Allowables and Response Origins
$app->add(function($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('X-Frame-Options', 'SAMEORIGIN');
});

// Deck Controllers
$app->group('/deck', function (RouteCollectorProxy $group) {
    $group->get('/[{deck_id}[/]]', DeckController::class . ':getDeck');
});

// Reading Controllers
$app->group('/reading', function (RouteCollectorProxy $group) {
    $group->get('/{reading_id}[/]', ReadingController::class . ':getReading');
    $group->post('/new[/]', ReadingController::class . ':newReading');
});

// Run the App
$app->run();
