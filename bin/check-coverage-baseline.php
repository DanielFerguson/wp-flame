<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cloverFile = $argv[1] ?? $root . '/build/logs/clover.xml';
$baselineFile = $root . '/quality/coverage-baseline.json';
$writeBaseline = in_array('--write-baseline', $argv, true);

if (! is_file($cloverFile)) {
    fwrite(STDERR, "Coverage report not found: {$cloverFile}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "Coverage report does not contain Clover project metrics.\n");
    exit(2);
}

$metrics = $xml->project->metrics->attributes();
$statements = (int) ($metrics['statements'] ?? 0);
$coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);

if ($statements < 1) {
    fwrite(STDERR, "Coverage report contains no executable statements.\n");
    exit(2);
}

$percentage = round(($coveredStatements / $statements) * 100, 2);

if ($writeBaseline) {
    $encoded = json_encode(
        [
            'schema_version' => 1,
            'scope' => 'src',
            'minimum_statement_coverage_percent' => $percentage,
            'measured_covered_statements' => $coveredStatements,
            'measured_statements' => $statements,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if (! is_string($encoded) || file_put_contents($baselineFile, $encoded . "\n") === false) {
        fwrite(STDERR, "Unable to write coverage baseline.\n");
        exit(2);
    }

    printf("Wrote %.2f%% statement coverage baseline (%d/%d).\n", $percentage, $coveredStatements, $statements);
    exit(0);
}

if (! is_file($baselineFile)) {
    fwrite(STDERR, "Missing quality/coverage-baseline.json. Generate it deliberately with composer coverage:baseline.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselineFile), true);
$minimum = is_array($baseline) && isset($baseline['minimum_statement_coverage_percent'])
    ? (float) $baseline['minimum_statement_coverage_percent']
    : null;

if ($minimum === null) {
    fwrite(STDERR, "Invalid coverage baseline format.\n");
    exit(2);
}

printf(
    "Statement coverage: %.2f%% (%d/%d); baseline: %.2f%%.\n",
    $percentage,
    $coveredStatements,
    $statements,
    $minimum
);

if ($percentage + 0.001 < $minimum) {
    fwrite(STDERR, "Coverage fell below the committed baseline. Add tests or deliberately review the baseline.\n");
    exit(1);
}
