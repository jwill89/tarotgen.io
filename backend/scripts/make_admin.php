<?php

/**
 * Grant or revoke admin on a user account from the command line.
 *
 * This is the break-glass / bootstrap tool for the account-based admin system:
 * if you're ever locked out of the admin area, run this on the server to grant
 * yourself admin (it also activates the account, so a pending account can still
 * be promoted).
 *
 * Usage (from the project root):
 *   php scripts/make_admin.php you@example.com           # grant admin (+ activate)
 *   php scripts/make_admin.php you@example.com --revoke  # remove admin
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Database\Connection;
use Tarot\Data\UserData;
use Tarot\Repository\UserRepository;

$email  = $argv[1] ?? '';
$revoke = in_array('--revoke', $argv, true);

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php scripts/make_admin.php <email> [--revoke]\n");
    exit(1);
}

$users = new UserRepository(new UserData(Connection::getInstance()));

$user = $users->findByEmail($email);
if ($user === null) {
    fwrite(STDERR, "No account found for {$email}.\n");
    exit(1);
}

if ($revoke) {
    $users->setAdmin($user->user_id, false);
    echo "Revoked admin from {$email} (user #{$user->user_id}).\n";
    exit(0);
}

$users->setAdmin($user->user_id, true);
$users->activate($user->user_id); // ensure a pending account can still sign in
echo "Granted admin to {$email} (user #{$user->user_id}) and ensured the account is active.\n";
exit(0);
