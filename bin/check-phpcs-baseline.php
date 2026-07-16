<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$baselineFile = $root . '/quality/phpcs-baseline.json';
$writeBaseline = in_array('--write-baseline', $argv, true);
$phpcs = $root . '/vendor/bin/phpcs';

if (! is_file($phpcs)) {
    fwrite(STDERR, "PHP_CodeSniffer is not installed. Run composer install.\n");
    exit(2);
}

$command = [
    PHP_BINARY,
    $phpcs,
    '--standard=' . $root . '/quality/phpcs.xml.dist',
    '--report=json',
    '--basepath=' . $root,
    $root . '/wp-flame.php',
    $root . '/uninstall.php',
    $root . '/src',
    $root . '/mu-plugin',
];

$pipes = [];
$process = proc_open(
    $command,
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root
);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start PHP_CodeSniffer.\n");
    exit(2);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode > 2 || ! is_string($stdout)) {
    fwrite(STDERR, $stderr ?: "PHP_CodeSniffer failed unexpectedly.\n");
    exit(2);
}

$report = json_decode($stdout, true);
if (! is_array($report) || ! isset($report['files']) || ! is_array($report['files'])) {
    fwrite(STDERR, $stderr ?: "PHP_CodeSniffer did not return a valid JSON report.\n");
    exit(2);
}

$violations = [];
foreach ($report['files'] as $file => $result) {
    if (! is_array($result) || ! isset($result['messages']) || ! is_array($result['messages'])) {
        continue;
    }

    $relativeFile = str_replace('\\', '/', (string) $file);
    foreach ($result['messages'] as $message) {
        if (! is_array($message)) {
            continue;
        }

        $source = isset($message['source']) ? (string) $message['source'] : 'unknown';
        $type = isset($message['type']) ? strtolower((string) $message['type']) : 'unknown';
        $key = $relativeFile . '|' . $type . '|' . $source;
        $violations[$key] = ($violations[$key] ?? 0) + 1;
    }
}
ksort($violations);

if ($writeBaseline) {
    $encoded = json_encode(
        [
            'schema_version' => 1,
            'description' => 'Maximum existing WPCS violations by file, severity, and sniff. Counts may decrease but must not increase.',
            'violations' => $violations,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if (! is_string($encoded) || file_put_contents($baselineFile, $encoded . "\n") === false) {
        fwrite(STDERR, "Unable to write PHPCS baseline.\n");
        exit(2);
    }

    printf("Wrote PHPCS baseline with %d violation groups.\n", count($violations));
    exit(0);
}

if (! is_file($baselineFile)) {
    fwrite(STDERR, "Missing quality/phpcs-baseline.json. Generate it deliberately with composer standards:baseline.\n");
    exit(2);
}

$baseline = json_decode((string) file_get_contents($baselineFile), true);
$allowed = is_array($baseline) && isset($baseline['violations']) && is_array($baseline['violations'])
    ? $baseline['violations']
    : null;

if ($allowed === null) {
    fwrite(STDERR, "Invalid PHPCS baseline format.\n");
    exit(2);
}

$regressions = [];
foreach ($violations as $key => $count) {
    $maximum = isset($allowed[$key]) ? (int) $allowed[$key] : 0;
    if ($count > $maximum) {
        $regressions[$key] = [$count, $maximum];
    }
}

if ($regressions !== []) {
    fwrite(STDERR, "WPCS baseline regression detected:\n");
    foreach ($regressions as $key => [$count, $maximum]) {
        fwrite(STDERR, sprintf("- %s: %d found, %d allowed\n", $key, $count, $maximum));
    }
    fwrite(STDERR, "Fix the regression. Regenerate the baseline only after an intentional standards review.\n");
    exit(1);
}

printf(
    "WPCS baseline passed: %d violations across %d groups; no group exceeded its committed maximum.\n",
    array_sum($violations),
    count($violations)
);
