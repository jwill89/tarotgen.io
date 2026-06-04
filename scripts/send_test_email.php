<?php

/**
 * Send a test email to verify SMTP (e.g. Google Workspace) configuration.
 *
 * Reads the same .env the app uses, prints the resolved (non-secret) settings,
 * then attempts to send a test message via the Mailer service.
 *
 * Usage (from the project root):
 *   php scripts/send_test_email.php you@example.com
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Config\Env;
use Tarot\Service\Mailer;

$to = $argv[1] ?? '';
if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php scripts/send_test_email.php <recipient@example.com>\n");
    exit(1);
}

Env::load(__DIR__ . '/../.env');

// Surface SMTP errors on the console for this diagnostic run.
ini_set('display_errors', '1');
ini_set('error_log', '');

echo "SMTP configuration:\n";
echo '  SMTP_HOST     = ' . (Env::get('SMTP_HOST') ?? '(unset)') . "\n";
echo '  SMTP_PORT     = ' . (Env::get('SMTP_PORT', '587')) . "\n";
echo '  SMTP_SECURE   = ' . (Env::get('SMTP_SECURE', 'tls')) . "\n";
echo '  SMTP_USER     = ' . (Env::get('SMTP_USER') ?? '(unset)') . "\n";
echo '  SMTP_PASS     = ' . (Env::get('SMTP_PASS') !== null ? '(set)' : '(unset)') . "\n";
echo '  MAIL_FROM     = ' . (Env::get('MAIL_FROM') ?? Env::get('SMTP_USER') ?? '(unset)') . "\n";
echo '  MAIL_FROM_NAME= ' . (Env::get('MAIL_FROM_NAME', 'TarotGen.io')) . "\n\n";

$mailer = new Mailer();

if (!$mailer->isConfigured()) {
    fwrite(STDERR, "SMTP is not configured (SMTP_HOST is empty or PHPMailer is missing). "
        . "Set the SMTP_* values in .env and run `composer install`.\n");
    exit(1);
}

echo "Sending test email to {$to} ...\n";

if ($mailer->sendTest($to)) {
    echo "Success — check the inbox (and spam folder) for \"TarotGen.io SMTP test\".\n";
    exit(0);
}

fwrite(STDERR, "Send failed. See the [Mailer] error above for the SMTP reason "
    . "(common causes: wrong app password, 2-Step Verification not enabled, or the From "
    . "address isn't allowed for this account).\n");
exit(1);
