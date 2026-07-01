<?php

// Required Autoloader
require_once('../vendor/autoload.php');

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use DI\ContainerBuilder;
use Routes\Internal\{AccountController,
    AdminController,
    ChangelogController,
    ConfigController,
    ContactController,
    DeckController,
    DeckSystemController,
    GoogleAuthController,
    PasskeyController,
    ReadingController,
    SpreadController,
    UserController};
use Routes\Middleware\AdminAuth;
use Routes\Middleware\OriginGuard;
use Routes\Middleware\UserAuth;
use Tarot\Config\Env;

// Load environment variables
Env::load(__DIR__ . '/../.env');

$is_production = Env::isProduction();

// Harden session cookies before any session is started.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $is_production,
]);

// Build the PHP-DI container with autowiring plus our one explicit definition
// (just PDO; everything else is autowired). In production, compile the container
// to disk so definition resolution isn't recomputed on every request.
$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions(__DIR__ . '/dependencies.php');

if ($is_production) {
    $builder->enableCompilation(sys_get_temp_dir() . '/tarot_di_cache');
}

$container = $builder->build();

// Register Container
AppFactory::setContainer($container);

// Setup the app/log
$app = AppFactory::create();

// Set base path
$app->setBasePath('/api');

// Setup Middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Setup Error Handling — never leak details/stack traces in production.
$error_middleware = $app->addErrorMiddleware($is_production === false, true, true);

// Security headers + opt-in CORS. The SPA is same-origin, so cross-origin
// access is only enabled when APP_ORIGIN is explicitly configured (never '*'
// alongside credentialed session cookies).
$allowed_origin = Env::get('APP_ORIGIN');
$app->add(function ($request, $handler) use ($allowed_origin) {
    $response = $handler->handle($request);

    if ($allowed_origin) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowed_origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }

    return $response
        ->withHeader('X-Frame-Options', 'SAMEORIGIN')
        ->withHeader('X-Content-Type-Options', 'nosniff');
});

// CSRF defense-in-depth: reject state-changing requests from a foreign origin.
// Added last so it runs first (outermost), short-circuiting before routing.
// Safe methods (incl. the GET OAuth callback) and same-origin requests pass.
$app->add(OriginGuard::class);

// ── API documentation ────────────────────────────────────────────────────────
// The raw OpenAPI 3.1 spec (generated from the swagger-php attributes via
// `composer docs`) and the Scalar reference UI that renders it. Both are public,
// safe GETs and are deliberately NOT part of the documented API surface.
$app->get('/openapi.json', function ($request, $response) {
    $path = __DIR__ . '/../openapi.json';
    $spec = is_file($path) ? file_get_contents($path) : false;

    if ($spec === false) {
        $response->getBody()->write('{"error":"API specification has not been generated. Run: composer docs"}');
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write($spec);
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Cache-Control', 'public, max-age=300');
});

$app->get('/docs', function ($request, $response) {
    $response->getBody()->write(<<<'HTML'
        <!doctype html>
        <html>
          <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>TarotGen.io — API Reference</title>
          </head>
          <body>
            <script id="api-reference" data-url="/api/openapi.json"></script>
            <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
          </body>
        </html>
        HTML);

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ── Decks (public read + user submission) ────────────────────────────────────
$app->group('/decks', function (RouteCollectorProxy $group) {
    $group->get('/{deck_id}/cards[/]', DeckController::class . ':getDeckCards');
    $group->get('[/{deck_id}[/]]', DeckController::class . ':getDeck');
    $group->post('[/]', DeckController::class . ':submitDeck');
});

// ── Deck systems (public read + user submission) ─────────────────────────────
$app->group('/deck-systems', function (RouteCollectorProxy $group) {
    $group->get('/{id}[/]', DeckSystemController::class . ':getSystem');
    $group->get('[/]', DeckSystemController::class . ':getSystems');
    $group->post('[/]', DeckSystemController::class . ':submitSystem');
});

// ── Readings ─────────────────────────────────────────────────────────────────
$app->group('/readings', function (RouteCollectorProxy $group) {
    $group->post('/generate[/]', ReadingController::class . ':newReading');
    $group->post('[/]', ReadingController::class . ':customReading');
    $group->put('/{reading_id}/placement[/]', ReadingController::class . ':updatePlacement');
    $group->post('/{reading_id}/draw[/]', ReadingController::class . ':drawCards');
    $group->post('/{reading_id}/finalize[/]', ReadingController::class . ':finalizeReading');
    $group->post('/{reading_id}/unlock[/]', ReadingController::class . ':unlockReading');
    $group->get('/{reading_id}[/]', ReadingController::class . ':getReading');
});

// ── Account (signed-in user self-service: readings, spreads, settings, deletion)
$app->group('/account', function (RouteCollectorProxy $group) {
    $group->get('/readings[/]', AccountController::class . ':myReadings');
    $group->patch('/readings/{reading_id}[/]', AccountController::class . ':updateReadingMeta');
    $group->delete('/readings/{reading_id}[/]', AccountController::class . ':deleteReading');
    $group->get('/spreads[/]', AccountController::class . ':mySpreads');
    $group->post('/spreads[/]', AccountController::class . ':createSpread');
    $group->put('/spreads/{user_spread_id}[/]', AccountController::class . ':updateSpread');
    $group->delete('/spreads/{user_spread_id}[/]', AccountController::class . ':deleteSpread');
    $group->post('/spreads/{user_spread_id}/submit[/]', AccountController::class . ':submitSpreadAsPublic');
    $group->get('/favorites[/]', AccountController::class . ':myFavorites');
    $group->post('/favorites[/]', AccountController::class . ':addFavorite');
    $group->delete('/favorites/{spread_type}/{spread_id}[/]', AccountController::class . ':removeFavorite');
    $group->get('/favorite-decks[/]', AccountController::class . ':myFavoriteDecks');
    $group->post('/favorite-decks[/]', AccountController::class . ':addFavoriteDeck');
    $group->delete('/favorite-decks/{deck_id}[/]', AccountController::class . ':removeFavoriteDeck');
    $group->post('/change-password[/]', AccountController::class . ':changePassword');
    $group->patch('[/]', AccountController::class . ':updateProfile');
    $group->delete('[/]', AccountController::class . ':deleteAccount');
})->add(UserAuth::class);

// ── Spreads (public read + public submission) ────────────────────────────────
$app->group('/spreads', function (RouteCollectorProxy $group) {
    $group->get('[/{spread_id}[/]]', SpreadController::class . ':getSpread');
    $group->post('[/]', SpreadController::class . ':submitSpread');
});

// ── Contact form (public submission) ─────────────────────────────────────────
$app->group('/contacts', function (RouteCollectorProxy $group) {
    $group->post('[/]', ContactController::class . ':submit');
});

// ── Changelog (public read) ──────────────────────────────────────────────────
$app->group('/changelog', function (RouteCollectorProxy $group) {
    $group->get('[/{entry_id}[/]]', ChangelogController::class . ':getChangelog');
});

// Public runtime config (non-secret values the SPA needs, e.g. Turnstile site key)
$app->get('/config[/]', ConfigController::class . ':get');

// ── Authentication (credentials + session). Action-oriented by design. ───────
$app->group('/auth', function (RouteCollectorProxy $group) {
    $group->post('/register[/]', UserController::class . ':register');
    $group->post('/activate[/]', UserController::class . ':activate');
    $group->post('/forgot-password[/]', UserController::class . ':forgotPassword');
    $group->post('/reset-password[/]', UserController::class . ':resetPassword');
    $group->post('/login[/]', UserController::class . ':login');
    $group->post('/logout[/]', UserController::class . ':logout');
    $group->get('/me[/]', UserController::class . ':me');
});

// Google OAuth (public initiate + callback, authenticated unlink)
$app->group('/auth/google', function (RouteCollectorProxy $group) {
    $group->get('[/]', GoogleAuthController::class . ':redirect');
    $group->get('/callback[/]', GoogleAuthController::class . ':callback');
    $group->post('/unlink[/]', GoogleAuthController::class . ':unlink');
});

// Passkeys / WebAuthn (registration requires auth; login is public)
$app->group('/auth/passkeys', function (RouteCollectorProxy $group) {
    $group->post('/register/options[/]', PasskeyController::class . ':registerOptions');
    $group->post('/register[/]', PasskeyController::class . ':register');
    $group->post('/login/options[/]', PasskeyController::class . ':loginOptions');
    $group->post('/login[/]', PasskeyController::class . ':login');
    $group->get('[/]', PasskeyController::class . ':list');
    $group->patch('/password-login[/]', PasskeyController::class . ':togglePasswordLogin');
    $group->patch('/{id}[/]', PasskeyController::class . ':rename');
    $group->delete('/{id}[/]', PasskeyController::class . ':delete');
});

// ── Admin Routes (require a logged-in, active, is_admin user account) ─────────
$app->group('/admin', function (RouteCollectorProxy $group) {
    $group->get('/auth-check[/]', AdminController::class . ':checkAuth');

    // Dashboard usage stats + one-shot summary (counts + stats)
    $group->get('/stats[/]', AdminController::class . ':getStats');
    $group->get('/summary[/]', AdminController::class . ':getSummary');

    // Decks. Static routes registered before the {deck_id} placeholder.
    $group->get('/decks[/]', AdminController::class . ':getDecks');
    $group->get('/decks/pending[/]', AdminController::class . ':getPendingDecks');
    $group->post('/decks[/]', AdminController::class . ':createDeck');
    $group->post('/decks/thumbnails[/]', AdminController::class . ':generateAllThumbnails');
    // Special cards, nested under their deck.
    $group->get('/decks/{deck_id}/special-cards[/]', AdminController::class . ':getSpecialCards');
    $group->post('/decks/{deck_id}/special-cards[/]', AdminController::class . ':createSpecialCard');
    $group->put('/decks/{deck_id}/special-cards/{card_id}[/]', AdminController::class . ':updateSpecialCard');
    $group->delete('/decks/{deck_id}/special-cards/{card_id}[/]', AdminController::class . ':deleteSpecialCard');
    $group->post('/decks/{deck_id}/approve[/]', AdminController::class . ':approveDeck');
    $group->post('/decks/{deck_id}/thumbnails[/]', AdminController::class . ':generateDeckThumbnails');
    $group->patch('/decks/{deck_id}[/]', AdminController::class . ':updateDeck');
    $group->delete('/decks/{deck_id}[/]', AdminController::class . ':deleteDeck');

    // Deck Systems
    $group->get('/deck-systems[/]', AdminController::class . ':getDeckSystems');
    $group->get('/deck-systems/pending[/]', AdminController::class . ':getPendingDeckSystems');
    $group->get('/deck-systems/{id}[/]', AdminController::class . ':getDeckSystem');
    $group->post('/deck-systems/{id}/approve[/]', AdminController::class . ':approveDeckSystem');
    $group->put('/deck-systems/{id}[/]', AdminController::class . ':updateDeckSystem');
    $group->delete('/deck-systems/{id}[/]', AdminController::class . ':deleteDeckSystem');

    // Spreads
    $group->get('/spreads[/]', AdminController::class . ':getSpreads');
    $group->post('/spreads[/]', AdminController::class . ':createSpread');
    $group->put('/spreads/{spread_id}[/]', AdminController::class . ':updateSpread');
    $group->delete('/spreads/{spread_id}[/]', AdminController::class . ':deleteSpread');

    // Pending Spreads (user submissions awaiting approval)
    $group->get('/pending-spreads[/]', AdminController::class . ':getPendingSpreads');
    $group->post('/pending-spreads/{pending_id}/approve[/]', AdminController::class . ':approvePendingSpread');
    $group->delete('/pending-spreads/{pending_id}[/]', AdminController::class . ':rejectPendingSpread');

    // Readings. Bulk delete ("/all") registered before the {reading_id} placeholder.
    $group->get('/readings[/]', AdminController::class . ':getReadings');
    $group->delete('/readings/all[/]', AdminController::class . ':cleanReadings');
    $group->delete('/readings/{reading_id}[/]', AdminController::class . ':deleteReading');

    // Changelog
    $group->get('/changelog[/]', AdminController::class . ':getChangelog');
    $group->post('/changelog[/]', AdminController::class . ':createChangelogEntry');
    $group->put('/changelog/{entry_id}[/]', AdminController::class . ':updateChangelogEntry');
    $group->delete('/changelog/{entry_id}[/]', AdminController::class . ':deleteChangelogEntry');

    // Users (management)
    $group->get('/users[/]', AdminController::class . ':getUsers');
    $group->post('/users/{user_id}/activate[/]', AdminController::class . ':activateUser');
    $group->post('/users/{user_id}/resend-activation[/]', AdminController::class . ':resendActivation');
    $group->patch('/users/{user_id}[/]', AdminController::class . ':updateUser');
    $group->delete('/users/{user_id}[/]', AdminController::class . ':deleteUser');

    // Contacts (submitted via public contact form)
    $group->get('/contacts[/]', AdminController::class . ':getContacts');
    $group->patch('/contacts/{contact_id}[/]', AdminController::class . ':updateContact');
})->add(AdminAuth::class);

// Run the App
$app->run();
