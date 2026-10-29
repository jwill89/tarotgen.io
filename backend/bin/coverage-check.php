<?php

// `composer test:coverage`: fails the run when line or method coverage in
// PHPUnit's clover report falls below the floor (AUDIT §8.7). PHPUnit has no
// threshold of its own. The floor sits a little under what the suite reached
// when it was set (2026-10-28: 95.7% of lines, 89.1% of methods, with Xdebug;
// CI measures with pcov): raise it as tests land, never lower it.

const FLOOR = ['lines' => 94.0, 'methods' => 87.0];

$file = $argv[1] ?? '';
$report = is_file($file) ? simplexml_load_file($file) : false;
if ($report === false) {
    fwrite(STDERR, "No clover report at '{$file}'.\n");
    exit(1);
}

$metrics = $report->project->metrics;
$measured = [
    'lines' => percent((int)$metrics['coveredstatements'], (int)$metrics['statements']),
    'methods' => percent((int)$metrics['coveredmethods'], (int)$metrics['methods']),
];

$failed = false;
foreach (FLOOR as $kind => $floor) {
    $ok = $measured[$kind] >= $floor;
    $failed = $failed || !$ok;
    printf("%-7s %5.1f%% (floor %4.1f%%)%s\n", $kind, $measured[$kind], $floor, $ok ? '' : '  BELOW THE FLOOR');
}
exit($failed ? 1 : 0);

function percent(int $covered, int $total): float
{
    return $total === 0 ? 100.0 : 100 * $covered / $total;
}
