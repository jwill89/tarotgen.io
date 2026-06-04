<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Repository\UserRepository;
use Tarot\Service\GoogleOAuthService;
use Tarot\Utility\Session;

/**
 * Google OAuth2 endpoints:
 *  - GET  /auth/google          → Redirect user to Google consent screen
 *  - GET  /auth/google/callback → Handle OAuth callback (login / register / link)
 *  - POST /auth/google/unlink   → Unlink Google from account (requires session)
 *
 * The flow uses session state to determine the intent (login, register, or link):
 *   $_SESSION['google_oauth_intent']  — 'login' | 'register' | 'link'
 *   $_SESSION['google_oauth_state']   — CSRF token
 */
class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly GoogleOAuthService $google,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Initiate the OAuth flow. Accepts an optional ?intent query param
     * ('login', 'register', or 'link') so the callback knows what to do.
     */
    public function redirect(Request $request, Response $response): Response|ResponseInterface
    {
        if (!$this->google->isConfigured()) {
            return $response->withJson(['error' => 'Google OAuth is not configured.'], 503);
        }

        $this->startSession();

        $intent = $request->getQueryParams()['intent'] ?? 'login';
        if (!in_array($intent, ['login', 'register', 'link'], true)) {
            $intent = 'login';
        }

        // For 'link', the user must already be logged in.
        if ($intent === 'link' && empty($_SESSION['user_id'])) {
            return $response->withJson(['error' => 'You must be logged in to link a Google account.'], 401);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state']  = $state;
        $_SESSION['google_oauth_intent'] = $intent;

        $url = $this->google->buildAuthUrl($state);

        return $response->withRedirect($url, 302);
    }

    /**
     * Handle the OAuth callback from Google.
     */
    public function callback(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $params = $request->getQueryParams();
        $code   = (string)($params['code'] ?? '');
        $state  = (string)($params['state'] ?? '');

        // Validate CSRF state.
        $expectedState = $_SESSION['google_oauth_state'] ?? '';
        unset($_SESSION['google_oauth_state']);

        if ($state === '' || !hash_equals($expectedState, $state)) {
            return $this->redirectToFrontend($response, '/login', 'Invalid OAuth state. Please try again.');
        }

        if ($code === '') {
            // User cancelled or Google returned an error.
            $error = (string)($params['error'] ?? 'OAuth was cancelled.');
            return $this->redirectToFrontend($response, '/login', $error);
        }

        // Exchange code for access token.
        $tokens = $this->google->exchangeCode($code);
        if ($tokens === null) {
            return $this->redirectToFrontend($response, '/login', 'Failed to authenticate with Google.');
        }

        // Fetch user info.
        $info = $this->google->fetchUserInfo($tokens['access_token']);
        if ($info === null) {
            return $this->redirectToFrontend($response, '/login', 'Could not retrieve your Google profile.');
        }

        $googleId = (string)$info['sub'];
        $email    = strtolower(trim((string)$info['email']));
        $name     = trim((string)($info['name'] ?? ''));

        $intent = $_SESSION['google_oauth_intent'] ?? 'login';
        unset($_SESSION['google_oauth_intent']);

        return match ($intent) {
            'link'     => $this->handleLink($response, $googleId),
            'register' => $this->handleRegister($response, $googleId, $email, $name),
            default    => $this->handleLogin($response, $googleId, $email, $name),
        };
    }

    /**
     * Unlink Google from the currently logged-in account.
     */
    public function unlink(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withJson(['error' => 'User not found.'], 404);
        }

        // Don't allow unlinking if the user has no password (Google-only account).
        $hash = $this->users->getPasswordHash($userId);
        if ($hash === null || $hash === '') {
            return $response->withJson([
                'error' => 'Cannot unlink Google — your account has no password set. Please set a password first.',
            ], 400);
        }

        $this->users->setGoogleId($userId, null);

        return $response->withJson(['success' => true, 'user' => $this->users->findById($userId)]);
    }

    // ── Private handlers ──────────────────────────────────────────

    private function handleLogin(Response $response, string $googleId, string $email, string $name): Response|ResponseInterface
    {
        // Try to find user by Google ID first.
        $user = $this->users->findByGoogleId($googleId);

        // If not found by Google ID, try by email and auto-link.
        if ($user === null) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                // Auto-link the Google account to the existing user.
                $this->users->setGoogleId($user->getUserId(), $googleId);
            }
        }

        if ($user === null) {
            // No account exists — auto-register.
            $displayName = $this->uniqueDisplayName($name ?: 'User');
            $user = $this->users->createFromGoogle($email, $displayName, $googleId);
            if ($user === null) {
                return $this->redirectToFrontend($response, '/login', 'Could not create your account.');
            }
        }

        if (!$user->isActive()) {
            // Activate since Google has verified the email.
            $this->users->activate($user->getUserId());
        }

        $this->users->touchLogin($user->getUserId());

        // Establish session.
        Session::regenerate(persistent: true);
        $_SESSION['user_id'] = $user->getUserId();

        // OAuth login implies strong intent — always persist the session.
        $this->persistSession();

        return $this->redirectToFrontend($response, '/');
    }

    private function handleRegister(Response $response, string $googleId, string $email, string $name): Response|ResponseInterface
    {
        // Check if Google account is already linked.
        if ($this->users->googleIdExists($googleId)) {
            // Already registered — just log them in.
            return $this->handleLogin($response, $googleId, $email, $name);
        }

        // Check if email already exists.
        if ($this->users->emailExists($email)) {
            return $this->redirectToFrontend(
                $response,
                '/register',
                'An account with that email already exists. Try logging in with Google instead.'
            );
        }

        $displayName = $this->uniqueDisplayName($name ?: 'User');
        $user = $this->users->createFromGoogle($email, $displayName, $googleId);
        if ($user === null) {
            return $this->redirectToFrontend($response, '/register', 'Could not create your account.');
        }

        $this->users->touchLogin($user->getUserId());

        Session::regenerate(persistent: true);
        $_SESSION['user_id'] = $user->getUserId();

        // New OAuth registration — persist the session.
        $this->persistSession();

        return $this->redirectToFrontend($response, '/');
    }

    private function handleLink(Response $response, string $googleId): Response|ResponseInterface
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return $this->redirectToFrontend($response, '/login', 'Session expired. Please log in and try again.');
        }

        // Ensure this Google account isn't already linked to another user.
        $existing = $this->users->findByGoogleId($googleId);
        if ($existing !== null && $existing->getUserId() !== $userId) {
            return $this->redirectToFrontend(
                $response,
                '/account/settings',
                'That Google account is already linked to a different user.'
            );
        }

        $this->users->setGoogleId($userId, $googleId);

        return $this->redirectToFrontend($response, '/account/settings', null, 'Google account linked successfully!');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function startSession(): void
    {
        Session::start();
    }

    /**
     * Redirect back to the frontend SPA with an optional error/success message
     * passed as a query parameter.
     */
    private function redirectToFrontend(
        Response $response,
        string $path,
        ?string $error = null,
        ?string $success = null,
    ): Response|ResponseInterface {
        $query = [];
        if ($error !== null) {
            $query['oauth_error'] = $error;
        }
        if ($success !== null) {
            $query['oauth_success'] = $success;
        }

        $url = $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withRedirect($url, 302);
    }

    /**
     * Generate a unique display name by appending a numeric suffix if needed.
     * @throws RandomException
     */
    private function uniqueDisplayName(string $base): string
    {
        // Trim and cap at 25 chars to leave room for suffix.
        $base = mb_substr(trim($base), 0, 25);
        if ($base === '') {
            $base = 'User';
        }

        if (!$this->users->displayNameExists($base)) {
            return $base;
        }

        for ($i = 1; $i < 100; $i++) {
            $candidate = $base . $i;
            if (!$this->users->displayNameExists($candidate)) {
                return $candidate;
            }
        }

        // Fallback: random suffix.
        return $base . bin2hex(random_bytes(3));
    }
}

