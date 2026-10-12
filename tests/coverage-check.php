<?php

/*
 * Fails when the line coverage of a Clover report is below a minimum.
 * Usage: php tests/coverage-check.php build/coverage.xml 90
 */

declare(strict_types=1);

$file = $argv[1] ?? 'build/coverage.xml';
$minimum = (float) ($argv[2] ?? '90');

$dom = new DOMDocument();
$nodes = @$dom->load($file) ? (new DOMXPath($dom))->query('/coverage/project/metrics') : false;
$metrics = $nodes === false ? null : $nodes->item(0);
if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "No Clover coverage report at {$file}\n");
    exit(1);
}
$total = (int) $metrics->getAttribute('statements');
$covered = (int) $metrics->getAttribute('coveredstatements');
$percent = $total === 0 ? 0.0 : 100 * $covered / $total;
printf("Line coverage: %.2f %% (%d/%d lines), minimum %.0f %%\n", $percent, $covered, $total, $minimum);
exit($percent >= $minimum ? 0 : 1);
