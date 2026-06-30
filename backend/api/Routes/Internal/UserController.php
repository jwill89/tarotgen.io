<?php

namespace Routes\Internal;

use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\UserRepository;
use Tarot\Service\AuthService;
use Tarot\Service\TurnstileService;
use Tarot\Utility\RateLimiter;
use Tarot\Utility\Session;

/**
 * Public account endpoints: register, activate, login, logout, and "me".
 *
 * These are intentionally separate from the shared-password admin auth: a user
 * session is keyed on $_SESSION['user_id'], while admin uses
 * $_SESSION['admin_authenticated'], so the two can coexist without interfering.
 */
class UserController extends AbstractController
{
    // Registration: max 5 per IP per hour (spam/abuse guard).
    private const int REGISTER_MAX    = 5;
    private const int REGISTER_WINDOW = 3600;

    // Login: max 10 failed attempts per IP per 15 minutes.
    private const int LOGIN_MAX    = 10;
    private const int LOGIN_WINDOW = 900;

    // Forgot-password requests: max 5 per IP per hour (anti email-bombing).
    private const int FORGOT_MAX    = 5;
    private const int FORGOT_WINDOW = 3600;

    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users,
        private readonly TurnstileService $turnstile,
    ) {
    }

    public function register(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_register', self::REGISTER_MAX, self::REGISTER_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many sign-up attempts. Please try again later.'], 429);
        }

        $params      = $this->parsedBody($request);
        $email       = (string)($params['email'] ?? '');
        $displayName = (string)($params['display_name'] ?? '');
        $password    = (string)($params['password'] ?? '');

        $result = $this->auth->register($email, $displayName, $password);

        if (!$result['ok']) {
            $limiter->hit($ip);
            return $response->withJson(['errors' => $result['errors'] ?? ['Registration failed.']], 422);
        }

        $limiter->hit($ip);

        $payload = [
            'success' => true,
            'message' => 'Account created. Check your email for an activation link to finish signing up.',
        ];

        // In non-production, when no SMTP is configured the email can't be sent,
        // so surface the activation link directly to keep dev/testing usable.
        $isProduction = Env::isProduction();
        if (!$isProduction && empty($result['emailed'])) {
            $payload['activation_link'] = $result['activation_link'] ?? null;
            $payload['message'] .= ' (Email is not configured in this environment — use the activation link below.)';
        }

        return $response->withJson($payload, 201);
    }

    public function activate(Request $request, Response $response): Response|ResponseInterface
    {
        $params = $this->parsedBody($request);
        $token  = (string)($params['token'] ?? '');

        if ($this->auth->activate($token)) {
            return $response->withJson([
                'success' => true,
                'message' => 'Your account is now active. You can log in.',
            ]);
        }

        return $response->withJson([
            'error' => 'This activation link is invalid or has expired.',
        ], 400);
    }

    public function forgotPassword(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_forgot', self::FORGOT_MAX, self::FORGOT_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many requests. Please try again later.'], 429);
        }

        $params = $this->parsedBody($request);
        $email  = (string)($params['email'] ?? '');

        $result = $this->auth->requestPasswordReset($email);
        $limiter->hit($ip);

        // Identical response whether or not the account exists (no enumeration).
        $payload = [
            'success' => true,
            'message' => 'If an account exists for that email, a password-reset link has been sent.',
        ];

        // Dev convenience only: surface the link when the account exists but no
        // SMTP is configured, so resets are testable without email.
        if (
            !Env::isProduction()
            && !empty($result['exists'])
            && empty($result['emailed'])
        ) {
            $payload['reset_link'] = $result['reset_link'] ?? null;
        }

        return $response->withJson($payload);
    }

    public function resetPassword(Request $request, Response $response): Response|ResponseInterface
    {
        $params   = $this->parsedBody($request);
        $token    = (string)($params['token'] ?? '');
        $password = (string)($params['password'] ?? '');

        $result = $this->auth->resetPassword($token, $password);

        if ($result['status'] === 'weak') {
            return $response->withJson(['error' => $result['error'] ?? 'Password is too weak.'], 422);
        }

        if ($result['status'] !== 'ok') {
            return $response->withJson(['error' => 'This reset link is invalid or has expired.'], 400);
        }

        return $response->withJson([
            'success' => true,
            'message' => 'Your password has been updated. You can now log in.',
        ]);
    }

    public function login(Request $request, Response $response): Response|ResponseInterface
    {
        $limiter = new RateLimiter('user_login', self::LOGIN_MAX, self::LOGIN_WINDOW);
        $ip      = $this->clientIp($request);

        if ($limiter->isLimited($ip)) {
            return $response->withJson(['error' => 'Too many attempts. Please try again later.'], 429);
        }

        $params     = $this->parsedBody($request);
        $email      = (string)($params['email'] ?? '');
        $password   = (string)($params['password'] ?? '');
        $rememberMe = !empty($params['remember_me']);

        // Bot/abuse guard: when Turnstile is configured, a valid challenge token
        // is required before we even check credentials.
        if ($this->turnstile->isConfigured()) {
            $token = (string)($params['turnstile_token'] ?? '');
            if (!$this->turnstile->verify($token, $ip)) {
                $limiter->hit($ip);
                return $response->withJson(['error' => 'Captcha verification failed. Please try again.'], 400);
            }
        }

        $result = $this->auth->authenticate($email, $password);

        if ($result['status'] === 'inactive') {
            $limiter->hit($ip);
            return $response->withJson([
                'error' => 'Your account is not activated yet. Please use the activation link sent to your email.',
            ], 403);
        }

        if ($result['status'] === 'password_disabled') {
            $limiter->hit($ip);
            return $response->withJson([
                'error' => 'Password login is disabled for this account. Please sign in with your passkey or Google.',
            ], 403);
        }

        if ($result['status'] !== 'ok' || !isset($result['user'])) {
            $limiter->hit($ip);
            return $response->withJson(['error' => 'Invalid email or password.'], 401);
        }

        $limiter->clear($ip);

        $this->startSessionWithPersistence();
        // Rotate the session ID on privilege change to prevent fixation.
        Session::regenerate(persistent: $rememberMe);
        $_SESSION['user_id'] = $result['user']->user_id;

        // Extend session cookie lifetime when "Remember Me" is checked.
        if ($rememberMe) {
            $this->persistSession();
        }

        return $response->withJson(['success' => true, 'user' => $result['user']]);
    }

    public function logout(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSessionWithPersistence();

        Session::clearUser();
        Session::regenerate();

        return $response->withJson(['success' => true]);
    }

    public function me(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSessionWithPersistence();

        $userId = Session::userId();
        if ($userId === null) {
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            // Stale session (account removed) — clear it.
            Session::clearUser();
            return $response->withJson(['error' => 'Not authenticated'], 401);
        }

        return $response->withJson(['user' => $user]);
    }
}
