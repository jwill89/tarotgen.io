<?php

/**
 * Nightly data retention (AUDIT.md §1.7, §9.9). Deletes guest readings — saved
 * without an account — past the expiry their maker chose (1, 7 or 30 days), and
 * any a year after they were created, as the privacy policy's "Data Retention"
 * section states; then refreshes SQLite's query-planner statistics. (Reads treat
 * an expired reading as gone the moment it expires; this frees the rows.)
 *
 * Run by /etc/cron.d/tarotgen straight after a successful nightly backup, as the
 * web-server user (AGENTS.md §7):
 *   runuser -u www-data -- php scripts/retention.php
 *   runuser -u www-data -- php scripts/retention.php --dry-run   # report only
 *
 * --dry-run runs the same DELETEs inside a transaction and rolls them back, so
 * the counts it reports are exactly what a real run would remove.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Data\ReadingData;
use Tarot\Database\Connection;
use Tarot\Repository\ReadingRepository;

/** The window the privacy policy promises — change both together. */
const GUEST_READING_DAYS = 365;

$dryRun = in_array('--dry-run', $argv, true);

$db = Connection::getInstance();
$readings = new ReadingRepository(new ReadingData($db));

['expired' => $expired, 'aged' => $aged] = $readings->purgeGuests(GUEST_READING_DAYS, $dryRun);

$report = "{$expired} guest reading(s) past their chosen expiry and "
    . "{$aged} guest reading(s) older than " . GUEST_READING_DAYS . ' days';

if ($dryRun) {
    echo "dry run: {$report} would be deleted\n";
    exit(0);
}

echo "deleted {$report}\n";

// Nothing had ever run ANALYZE (AUDIT §2.7). Plain ANALYZE, not PRAGMA optimize:
// production's SQLite (3.45) ignores optimize's check-every-table bit (3.46+) and
// only analysed the one table this connection had touched. The database is small
// and analysis_limit caps each index scan, so a full pass costs nothing nightly.
$db->exec('PRAGMA analysis_limit = 400');
$db->exec('ANALYZE');
