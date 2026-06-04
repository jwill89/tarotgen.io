<?php

namespace Routes\Internal;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Tarot\Config\Env;
use Tarot\Repository\PasskeyRepository;
use Tarot\Repository\UserRepository;
use Tarot\Utility\Session;

/**
 * WebAuthn/Passkey endpoints:
 *
 *  POST /auth/passkey/register/options   → Get credential creation options (requires session)
 *  POST /auth/passkey/register           → Complete passkey registration
 *  POST /auth/passkey/login/options      → Get assertion options (public, requires email)
 *  POST /auth/passkey/login              → Authenticate with passkey
 *  GET  /auth/passkey                    → List current user's passkeys
 *  PUT  /auth/passkey/{id}               → Rename a passkey
 *  DELETE /auth/passkey/{id}             → Delete a passkey
 *  PUT  /auth/passkey/password-login     → Toggle password login on/off
 */
class PasskeyController extends AbstractController
{
    public function __construct(
        private readonly PasskeyRepository $passkeys,
        private readonly UserRepository $users,
    ) {
    }

    // ── Registration ─────────────────────────────────────────────────

    /**
     * Generate WebAuthn credential creation options for the logged-in user.
     */
    public function registerOptions(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withJson(['error' => 'User not found.'], 404);
        }

        $webAuthn = $this->createWebAuthn();

        // Exclude already-registered credentials to prevent re-registration.
        $existingIds = $this->passkeys->getCredentialIds($userId);
        $excludeCredentials = array_map(
            fn(string $id) => new ByteBuffer(self::base64UrlDecode($id)),
            $existingIds
        );

        $createArgs = $webAuthn->getCreateArgs(
            hex2bin(str_pad(dechex($userId), 16, '0', STR_PAD_LEFT)), // user id as binary
            $user->getEmail(),
            $user->getDisplayName(),
            60, // timeout seconds
            'preferred', // resident key
            'preferred', // user verification
            null, // cross-platform (allow both)
            $excludeCredentials
        );

        // Store challenge in session for verification.
        $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();

        return $response->withJson($createArgs);
    }

    /**
     * Complete passkey registration: verify attestation and store credential.
     */
    public function register(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);

        if ($challenge === null) {
            return $response->withJson(['error' => 'No pending registration challenge.'], 400);
        }

        $body = $request->getParsedBody() ?? [];
        $clientDataJSON   = self::base64UrlDecode((string)($body['clientDataJSON'] ?? ''));
        $attestationObject = self::base64UrlDecode((string)($body['attestationObject'] ?? ''));
        $name = mb_substr(trim((string)($body['name'] ?? 'My Passkey')), 0, 50) ?: 'My Passkey';

        if ($clientDataJSON === '' || $attestationObject === '') {
            return $response->withJson(['error' => 'Missing attestation data.'], 400);
        }

        try {
            $webAuthn = $this->createWebAuthn();
            $data = $webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                new ByteBuffer($challenge),
                false, // requireUserVerification
                true,  // requireUserPresent
                false  // failIfRootMismatch
            );
        } catch (\Throwable $e) {
            return $response->withJson(['error' => 'Registration failed: ' . $e->getMessage()], 400);
        }

        // Store credential.
        // credentialId is raw binary from the library — encode it to base64url for storage and lookup.
        $credentialId = $data->credentialId instanceof ByteBuffer
            ? self::base64UrlEncode($data->credentialId->getBinaryString())
            : self::base64UrlEncode((string)$data->credentialId);
        $signCount = $data->signatureCounter ?? 0;

        $passkeyId = $this->passkeys->create(
            $userId,
            $credentialId,
            $data->credentialPublicKey,
            $name,
            $signCount
        );

        return $response->withJson([
            'success' => true,
            'passkey' => [
                'passkey_id'  => $passkeyId,
                'name'        => $name,
                'created_at'  => date('Y-m-d H:i:s'),
                'last_used_at' => null,
            ],
        ], 201);
    }

    // ── Authentication ───────────────────────────────────────────────

    /**
     * Generate assertion options for passkey login.
     * Accepts optional email to scope to a specific user's credentials,
     * or no email for a discoverable-credential (resident key) flow.
     */
    public function loginOptions(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $body  = $request->getParsedBody() ?? [];
        $email = strtolower(trim((string)($body['email'] ?? '')));

        $credentialIds = [];

        if ($email !== '') {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                // Don't reveal whether the account exists — return generic options.
                // The assertion will fail gracefully later.
                $credentialIds = [];
            } else {
                // Check if password login is disabled, and check passkeys exist
                $rawIds = $this->passkeys->getCredentialIds($user->getUserId());
                $credentialIds = array_map(
                    static fn(string $id) => new ByteBuffer(self::base64UrlDecode($id)),
                    $rawIds
                );
            }
        }

        $webAuthn = $this->createWebAuthn();
        $getArgs = $webAuthn->getGetArgs(
            $credentialIds,
            60,   // timeout
            true, // USB
            true, // NFC
            true, // BLE
            true, // Hybrid
            true, // Internal
            'preferred' // user verification
        );

        $_SESSION['webauthn_challenge'] = $webAuthn->getChallenge()->getBinaryString();

        return $response->withJson($getArgs);
    }

    /**
     * Verify a passkey assertion and log the user in.
     */
    public function login(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();

        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);

        if ($challenge === null) {
            return $response->withJson(['error' => 'No pending authentication challenge.'], 400);
        }

        $body = $request->getParsedBody() ?? [];
        $credentialIdB64  = (string)($body['id'] ?? '');
        $clientDataJSON   = self::base64UrlDecode((string)($body['clientDataJSON'] ?? ''));
        $authenticatorData = self::base64UrlDecode((string)($body['authenticatorData'] ?? ''));
        $signature        = self::base64UrlDecode((string)($body['signature'] ?? ''));

        if ($credentialIdB64 === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
            return $response->withJson(['error' => 'Missing assertion data.'], 400);
        }

        // Look up credential in database.
        $passkey = $this->passkeys->findByCredentialId($credentialIdB64);
        if ($passkey === null) {
            return $response->withJson(['error' => 'Unknown passkey credential.'], 401);
        }

        try {
            $webAuthn = $this->createWebAuthn();
            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey['public_key_pem'],
                new ByteBuffer($challenge),
                $passkey['sign_count'] > 0 ? $passkey['sign_count'] : null,
                false, // requireUserVerification
                true   // requireUserPresent
            );
        } catch (WebAuthnException $e) {
            return $response->withJson(['error' => 'Passkey authentication failed: ' . $e->getMessage()], 401);
        }

        // Update signature counter.
        $newCount = $webAuthn->getSignatureCounter();
        if ($newCount !== null) {
            $this->passkeys->updateSignCount($passkey['passkey_id'], $newCount);
        }

        // Load user and establish session.
        $user = $this->users->findById($passkey['user_id']);
        if ($user === null) {
            return $response->withJson(['error' => 'Account not found.'], 401);
        }

        if (!$user->isActive()) {
            return $response->withJson(['error' => 'Your account is not activated.'], 403);
        }

        $this->users->touchLogin($user->getUserId());

        Session::regenerate(persistent: true);
        $_SESSION['user_id'] = $user->getUserId();

        // Passkey login implies strong intent — always persist the session.
        $this->persistSession();

        return $response->withJson(['success' => true, 'user' => $user]);
    }

    // ── Management ───────────────────────────────────────────────────

    /**
     * List the logged-in user's passkeys.
     */
    public function list(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        return $response->withJson($this->passkeys->getByUser($userId));
    }

    /**
     * Rename a passkey.
     */
    public function rename(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $passkeyId = (int)($args['id'] ?? 0);
        $body = $request->getParsedBody() ?? [];
        $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 50);

        if ($name === '') {
            return $response->withJson(['error' => 'Name is required.'], 422);
        }

        if (!$this->passkeys->rename($passkeyId, $userId, $name)) {
            return $response->withJson(['error' => 'Passkey not found.'], 404);
        }

        return $response->withJson(['success' => true]);
    }

    /**
     * Delete a passkey.
     */
    public function delete(Request $request, Response $response, array $args): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $passkeyId = (int)($args['id'] ?? 0);

        // If password login is disabled, ensure at least one passkey remains.
        $user = $this->users->findById($userId);
        if ($user !== null && $user->isPasswordLoginDisabled()) {
            $count = $this->passkeys->countByUser($userId);
            if ($count <= 1) {
                return $response->withJson([
                    'error' => 'Cannot delete your last passkey while password login is disabled. Re-enable password login first.',
                ], 400);
            }
        }

        if (!$this->passkeys->delete($passkeyId, $userId)) {
            return $response->withJson(['error' => 'Passkey not found.'], 404);
        }

        return $response->withJson(['success' => true]);
    }

    /**
     * Toggle password login enabled/disabled.
     */
    public function togglePasswordLogin(Request $request, Response $response): Response|ResponseInterface
    {
        $this->startSession();
        $userId = $this->sessionUserId();
        if ($userId < 1) {
            return $response->withJson(['error' => 'Not authenticated.'], 401);
        }

        $body = $request->getParsedBody() ?? [];
        $disable = filter_var($body['disable'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($disable) {
            // Must have at least one passkey (or Google linked) to disable password.
            $count = $this->passkeys->countByUser($userId);
            if ($count < 1) {
                return $response->withJson([
                    'error' => 'You must register at least one passkey before disabling password login.',
                ], 400);
            }
        }

        $this->users->setPasswordLoginDisabled($userId, $disable);

        return $response->withJson([
            'success' => true,
            'password_login_disabled' => $disable,
            'user' => $this->users->findById($userId),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * @throws WebAuthnException
     */
    private function createWebAuthn(): WebAuthn
    {
        $rpName = Env::get('APP_NAME', 'Tarot Generator');
        $rpId   = Env::get('WEBAUTHN_RP_ID', parse_url(Env::get('APP_URL', 'https://tarotgen.io'), PHP_URL_HOST));

        return new WebAuthn($rpName, $rpId, ['none', 'packed', 'fido-u2f', 'android-key', 'apple'], true);
    }

    private function startSession(): void
    {
        Session::start();
    }

    private function sessionUserId(): int
    {
        return Session::userId() ?? 0;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}

