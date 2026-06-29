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

// Build the PHP-DI container with autowiring plus our explicit definitions
// (PDO + repository interface bindings). In production, compile the container
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

// Deck Controllers
$app->group('/deck', function (RouteCollectorProxy $group) {
    $group->post('/submit[/]', DeckController::class . ':submitDeck');
    $group->get('/{deck_id}/cards[/]', DeckController::class . ':getDeckCards');
    $group->get('/[{deck_id}[/]]', DeckController::class . ':getDeck');
});

// Deck System Controllers
$app->group('/deck-system', function (RouteCollectorProxy $group) {
    $group->post('/submit[/]', DeckSystemController::class . ':submitSystem');
    $group->get('/[/]', DeckSystemController::class . ':getSystems');
    $group->get('/{id}[/]', DeckSystemController::class . ':getSystem');
});

// Reading Controllers
$app->group('/reading', function (RouteCollectorProxy $group) {
    $group->post('/new[/]', ReadingController::class . ':newReading');
    $group->post('/custom[/]', ReadingController::class . ':customReading');
    $group->put('/{reading_id}/placement[/]', ReadingController::class . ':updatePlacement');
    $group->post('/{reading_id}/draw[/]', ReadingController::class . ':drawCards');
    $group->put('/{reading_id}/finalize[/]', ReadingController::class . ':finalizeReading');
    $group->post('/{reading_id}/unlock[/]', ReadingController::class . ':unlockReading');
    $group->get('/{reading_id}[/]', ReadingController::class . ':getReading');
});

// Account (signed-in user self-service: readings, spreads, settings, deletion)
$app->group('/account', function (RouteCollectorProxy $group) {
    $group->get('/readings[/]', AccountController::class . ':myReadings');
    $group->put('/readings/{reading_id}[/]', AccountController::class . ':updateReadingMeta');
    $group->delete('/readings/{reading_id}[/]', AccountController::class . ':deleteReading');
    $group->get('/spreads[/]', AccountController::class . ':mySpreads');
    $group->post('/spreads[/]', AccountController::class . ':createSpread');
    $group->put('/spreads/{user_spread_id}[/]', AccountController::class . ':updateSpread');
    $group->delete('/spreads/{user_spread_id}[/]', AccountController::class . ':deleteSpread');
    $group->post('/spreads/{user_spread_id}/submit[/]', AccountController::class . ':submitSpreadAsPublic');
    $group->get('/favorites[/]', AccountController::class . ':myFavorites');
    $group->post('/favorites[/]', AccountController::class . ':addFavorite');
    $group->delete('/favorites[/]', AccountController::class . ':removeFavorite');
    $group->get('/favorite-decks[/]', AccountController::class . ':myFavoriteDecks');
    $group->post('/favorite-decks[/]', AccountController::class . ':addFavoriteDeck');
    $group->delete('/favorite-decks[/]', AccountController::class . ':removeFavoriteDeck');
    $group->put('/display-name[/]', AccountController::class . ':changeDisplayName');
    $group->put('/password[/]', AccountController::class . ':changePassword');
    $group->delete('[/]', AccountController::class . ':deleteAccount');
})->add(UserAuth::class);

// Spread Controllers (public read + public submission)
$app->group('/spread', function (RouteCollectorProxy $group) {
    $group->post('/submit[/]', SpreadController::class . ':submitSpread');
    $group->get('/[{spread_id}[/]]', SpreadController::class . ':getSpread');
});

// Contact Form (public submission)
$app->group('/contact', function (RouteCollectorProxy $group) {
    $group->post('/[/]', ContactController::class . ':submit');
});

// Changelog Controllers (public read)
$app->group('/changelog', function (RouteCollectorProxy $group) {
    $group->get('/[{entry_id}[/]]', ChangelogController::class . ':getChangelog');
});

// Public runtime config (non-secret values the SPA needs, e.g. Turnstile site key)
$app->get('/config[/]', ConfigController::class . ':get');

// User Accounts (public: register, activate, login, logout, session check)
$app->group('/user', function (RouteCollectorProxy $group) {
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

// Passkey / WebAuthn (registration requires auth; login is public)
$app->group('/auth/passkey', function (RouteCollectorProxy $group) {
    $group->post('/register/options[/]', PasskeyController::class . ':registerOptions');
    $group->post('/register[/]', PasskeyController::class . ':register');
    $group->post('/login/options[/]', PasskeyController::class . ':loginOptions');
    $group->post('/login[/]', PasskeyController::class . ':login');
    $group->get('[/]', PasskeyController::class . ':list');
    $group->put('/password-login[/]', PasskeyController::class . ':togglePasswordLogin');
    $group->put('/{id}[/]', PasskeyController::class . ':rename');
    $group->delete('/{id}[/]', PasskeyController::class . ':delete');
});

// Admin Routes (require a logged-in, active, is_admin user account)
$app->group('/admin', function (RouteCollectorProxy $group) {
    $group->get('/auth-check[/]', AdminController::class . ':checkAuth');

    // Dashboard usage stats + one-shot summary (counts + stats)
    $group->get('/stats[/]', AdminController::class . ':getStats');
    $group->get('/summary[/]', AdminController::class . ':getSummary');


    // Decks
    $group->get('/decks[/]', AdminController::class . ':getDecks');
    $group->get('/decks/pending[/]', AdminController::class . ':getPendingDecks');
    $group->post('/decks[/]', AdminController::class . ':createDeck');
    // Static thumbnail routes registered before the {deck_id} placeholder.
    $group->post('/decks/thumbnails[/]', AdminController::class . ':generateAllThumbnails');
    $group->post('/decks/{deck_id}/approve[/]', AdminController::class . ':approveDeck');
    $group->post('/decks/{deck_id}/usable[/]', AdminController::class . ':markDeckUsable');
    $group->post('/decks/{deck_id}/thumbnails[/]', AdminController::class . ':generateDeckThumbnails');
    $group->put('/decks/{deck_id}[/]', AdminController::class . ':updateDeck');
    $group->delete('/decks/{deck_id}[/]', AdminController::class . ':deleteDeck');

    // Deck Systems
    $group->get('/deck-systems[/]', AdminController::class . ':getDeckSystems');
    $group->get('/deck-systems/pending[/]', AdminController::class . ':getPendingDeckSystems');
    $group->get('/deck-systems/{id}[/]', AdminController::class . ':getDeckSystem');
    $group->post('/deck-systems/{id}/approve[/]', AdminController::class . ':approveDeckSystem');
    $group->put('/deck-systems/{id}[/]', AdminController::class . ':updateDeckSystem');
    $group->delete('/deck-systems/{id}[/]', AdminController::class . ':deleteDeckSystem');

    // Special Cards
    $group->get('/special-cards[/]', AdminController::class . ':getSpecialCards');
    $group->post('/special-cards[/]', AdminController::class . ':createSpecialCard');
    $group->put('/special-cards/{deck_id}/{card_id}[/]', AdminController::class . ':updateSpecialCard');
    $group->delete('/special-cards/{deck_id}/{card_id}[/]', AdminController::class . ':deleteSpecialCard');

    // Spreads
    $group->get('/spreads[/]', AdminController::class . ':getSpreads');
    $group->post('/spreads[/]', AdminController::class . ':createSpread');
    $group->put('/spreads/{spread_id}[/]', AdminController::class . ':updateSpread');
    $group->delete('/spreads/{spread_id}[/]', AdminController::class . ':deleteSpread');

    // Pending Spreads (user submissions awaiting approval)
    $group->get('/pending-spreads[/]', AdminController::class . ':getPendingSpreads');
    $group->post('/pending-spreads/{pending_id}/approve[/]', AdminController::class . ':approvePendingSpread');
    $group->delete('/pending-spreads/{pending_id}[/]', AdminController::class . ':rejectPendingSpread');

    // Readings
    $group->get('/readings[/]', AdminController::class . ':getReadings');
    $group->post('/readings/clean[/]', AdminController::class . ':cleanReadings');
    $group->delete('/readings/{reading_id}[/]', AdminController::class . ':deleteReading');

    // Changelog
    $group->get('/changelog[/]', AdminController::class . ':getChangelog');
    $group->post('/changelog[/]', AdminController::class . ':createChangelogEntry');
    $group->put('/changelog/{entry_id}[/]', AdminController::class . ':updateChangelogEntry');
    $group->delete('/changelog/{entry_id}[/]', AdminController::class . ':deleteChangelogEntry');

    // Users (management)
    $group->get('/users[/]', AdminController::class . ':getUsers');
    $group->post('/users/{user_id}/activate[/]', AdminController::class . ':activateUser');
    $group->post('/users/{user_id}/admin[/]', AdminController::class . ':setUserAdmin');
    $group->post('/users/{user_id}/resend-activation[/]', AdminController::class . ':resendActivation');
    $group->delete('/users/{user_id}[/]', AdminController::class . ':deleteUser');

    // Contacts (submitted via public contact form)
    $group->get('/contacts[/]', AdminController::class . ':getContacts');
    $group->post('/contacts/{contact_id}/read[/]', AdminController::class . ':markContactRead');
})->add(AdminAuth::class);

// Run the App
$app->run();
