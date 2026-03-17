<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CLI
{
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
        $filters['per_page'] = (int) ($assoc_args['limit'] ?? 20);
        $filters['page'] = 1;

        if (!empty($assoc_args['url'])) {
            $filters['url'] = $assoc_args['url'];
        }

        $traces = $this->storage->list_traces($filters);

        if (empty($traces)) {
            \WP_CLI::log('No traces found.');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        // Format the data for display
        $items = array_map(function($row) {
            return [
                'trace_id'   => $row['trace_id'],
                'url'        => $row['url'],
                'method'     => $row['method'],
                'duration'   => round((float) $row['total_ms'], 1) . 'ms',
                'queries'    => $row['query_count'],
                'memory'     => round((int) $row['peak_memory'] / 1048576, 1) . 'MB',
                'date'       => $row['created_at'],
            ];
        }, $traces);

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
        $trace_id = $args[0];
        $trace = $this->storage->get_trace($trace_id);

        if (!$trace) {
            \WP_CLI::error('Trace not found: ' . $trace_id);
            return;
        }

        $format = $assoc_args['format'] ?? 'json';
        $data = $trace->toArray();

        if ($format === 'json') {
            \WP_CLI::log(json_encode($data, JSON_PRETTY_PRINT));
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

        $days = (int) ($assoc_args['days'] ?? get_option('wp_flame_retention_days', 7));
        $this->storage->prune_old($days);
        \WP_CLI::success("Pruned traces older than {$days} day(s).");
    }
}
