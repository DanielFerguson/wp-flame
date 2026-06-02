<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CLI
{
    private const MAX_TRACE_ID_BYTES = 128;
    private const MAX_URL_BYTES = 2048;
    private const MAX_METHOD_BYTES = 20;
    private const MAX_DATE_BYTES = 64;

    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List recent traces.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of traces to show. Default 20.
     *
     * [--url=<url>]
     * : Filter by URL pattern.
     *
     * [--format=<format>]
     * : Output format. Default table. Options: table, json, csv.
     *
     * ## EXAMPLES
     *
     *     wp flame list
     *     wp flame list --limit=50
     *     wp flame list --url=/shop/ --format=json
     *
     * @subcommand list
     */
    public function list_traces($args, $assoc_args): void
    {
        $filters = [];
        $filters['per_page'] = Config::bounded_int( $assoc_args['limit'] ?? 20, 20, 1, 200 );
        $filters['page'] = 1;

        if (!empty($assoc_args['url'])) {
            $filters['url'] = $assoc_args['url'];
        }

        $traces = $this->storage->list_traces($filters);

        if (empty($traces)) {
            \WP_CLI::log('No traces found.');
            return;
        }

        $format = $this->format_arg( $assoc_args['format'] ?? 'table', [ 'table', 'json', 'csv' ], 'table' );

        $items = array_map([$this, 'format_trace_row'], $traces);

        \WP_CLI\Utils\format_items($format, $items, ['trace_id', 'url', 'method', 'duration', 'queries', 'memory', 'date']);
    }

    /**
     * Show a single trace.
     *
     * ## OPTIONS
     *
     * <trace_id>
     * : The trace ID (UUID) to show.
     *
     * [--format=<format>]
     * : Output format. Default json. Options: json, yaml.
     *
     * ## EXAMPLES
     *
     *     wp flame show abc-123-def
     */
    public function show($args, $assoc_args): void
    {
        $trace_id = Config::string_value( $args[0] ?? '', '' );
        $trace_id = $this->limit_string( $trace_id, self::MAX_TRACE_ID_BYTES );
        if ( $trace_id === '' ) {
            \WP_CLI::error( 'Missing trace ID.' );
            return;
        }

        $trace = $this->storage->get_trace($trace_id);

        if (!$trace) {
            \WP_CLI::error('Trace not found: ' . $trace_id);
            return;
        }

        $format = $this->format_arg( $assoc_args['format'] ?? 'json', [ 'json', 'yaml' ], 'json' );
        $data = $trace->toArray();

        if ($format === 'json') {
            $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE );
            \WP_CLI::log( is_string( $json ) ? $json : '{}' );
        } else {
            \WP_CLI::print_value($data, ['format' => $format]);
        }
    }

    /**
     * Prune old traces.
     *
     * ## OPTIONS
     *
     * [--days=<number>]
     * : Delete traces older than this many days. Default: use retention setting.
     *
     * [--all]
     * : Delete all traces.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp flame prune
     *     wp flame prune --days=3
     *     wp flame prune --all --yes
     */
    public function prune($args, $assoc_args): void
    {
        if (!empty($assoc_args['all'])) {
            if (empty($assoc_args['yes'])) {
                \WP_CLI::confirm('Delete ALL traces?');
            }
            $count = $this->storage->purge_all();
            \WP_CLI::success("Deleted {$count} trace(s).");
            return;
        }

        $days = Config::bounded_int(
            $assoc_args['days'] ?? Config::instance()->get( 'wp_flame_retention_days', Config::DEFAULT_RETENTION_DAYS ),
            Config::DEFAULT_RETENTION_DAYS,
            1,
            Config::MAX_RETENTION_DAYS
        );
        $this->storage->prune_old($days);
        \WP_CLI::success("Pruned traces older than {$days} day(s).");
    }

    /**
     * @param mixed $row
     * @return array<string, mixed>
     */
    private function format_trace_row($row): array
    {
        if (! is_array($row)) {
            $row = [];
        }

        $duration_ms = max(0.0, $this->number($row['total_ms'] ?? 0, 0.0));
        $memory      = Config::bounded_int($row['peak_memory'] ?? 0, 0, 0, PHP_INT_MAX);

        return [
            'trace_id' => $this->limit_string(Config::string_value($row['trace_id'] ?? '', ''), self::MAX_TRACE_ID_BYTES),
            'url'      => $this->limit_string(Config::string_value($row['url'] ?? '', ''), self::MAX_URL_BYTES),
            'method'   => $this->limit_string(Config::string_value($row['method'] ?? '', ''), self::MAX_METHOD_BYTES),
            'duration' => round($duration_ms, 1) . 'ms',
            'queries'  => Config::bounded_int($row['query_count'] ?? 0, 0, 0, PHP_INT_MAX),
            'memory'   => round($memory / 1048576, 1) . 'MB',
            'date'     => $this->limit_string(Config::string_value($row['created_at'] ?? '', ''), self::MAX_DATE_BYTES),
        ];
    }

    /**
     * @param mixed $value
     */
    private function number($value, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : $fallback;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $number = (float) trim($value);
            return is_finite($number) ? $number : $fallback;
        }

        return $fallback;
    }

    /**
     * @param mixed        $value
     * @param array<int,string> $allowed
     */
    private function format_arg( $value, array $allowed, string $fallback ): string
    {
        $format = strtolower( trim( Config::string_value( $value, $fallback ) ) );

        return in_array( $format, $allowed, true ) ? $format : $fallback;
    }

    private function limit_string(string $value, int $max_bytes): string
    {
        if (strlen($value) <= $max_bytes) {
            return $value;
        }

        return substr($value, 0, $max_bytes);
    }
}
