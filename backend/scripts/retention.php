<?php

/**
 * Nightly data retention (AUDIT.md §1.7). Deletes guest readings — saved without
 * an account — a year after they were created, as the privacy policy's "Data
 * Retention" section states, then refreshes SQLite's query-planner statistics.
 *
 * Run by /etc/cron.d/tarotgen straight after a successful nightly backup, as the
 * web-server user (AGENTS.md §7):
 *   runuser -u www-data -- php scripts/retention.php
 *   runuser -u www-data -- php scripts/retention.php --dry-run   # report only
 *
 * --dry-run runs the same DELETE inside a transaction and rolls it back, so the
 * count it reports is exactly what a real run would remove.
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

$db->beginTransaction();
$deleted = $readings->cleanGuest(GUEST_READING_DAYS);

if ($dryRun) {
    $db->rollBack();
    echo "dry run: {$deleted} guest reading(s) older than " . GUEST_READING_DAYS . " days would be deleted\n";
    exit(0);
}

$db->commit();
echo "deleted {$deleted} guest reading(s) older than " . GUEST_READING_DAYS . " days\n";

// Nothing had ever run ANALYZE (AUDIT §2.7). optimize only re-analyses tables
// whose stats are missing or stale; 0x10002 makes it check every table, since a
// fresh connection hasn't run the queries its default heuristic watches, and
// analysis_limit keeps each table's scan cheap.
$db->exec('PRAGMA analysis_limit = 400');
$db->exec('PRAGMA optimize = 0x10002');
