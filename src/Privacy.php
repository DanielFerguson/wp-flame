<?php

declare(strict_types=1);

namespace WPFlame;

class Privacy
{
    /** @var Storage */
    private $storage;

    public function __construct( Storage $storage )
    {
        $this->storage = $storage;
    }

    public function register(): void
    {
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
    }

    public function register_exporter( array $exporters ): array
    {
        $exporters['wp-flame'] = $this->get_exporter();
        return $exporters;
    }

    public function register_eraser( array $erasers ): array
    {
        $erasers['wp-flame'] = $this->get_eraser();
        return $erasers;
    }

    public function get_exporter(): array
    {
        return [
            'exporter_friendly_name' => 'WP Flame Performance Data',
            'callback'               => [ $this, 'export_user_data' ],
        ];
    }

    public function get_eraser(): array
    {
        return [
            'eraser_friendly_name' => 'WP Flame Performance Data',
            'callback'             => [ $this, 'erase_user_data' ],
        ];
    }

    public function export_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return [ 'data' => [], 'done' => true ];
        }

        $traces = $this->storage->list_traces( [
            'user_id'  => $user->ID,
            'per_page' => 50,
            'page'     => $page,
        ] );

        $export_items = [];
        foreach ( $traces as $trace ) {
            $export_items[] = [
                'group_id'          => 'wp-flame-traces',
                'group_label'       => 'WP Flame Performance Traces',
                'group_description' => 'Performance trace data collected by WP Flame.',
                'item_id'           => 'trace-' . $trace['trace_id'],
                'data'              => [
                    [ 'name' => 'URL', 'value' => $trace['url'] ],
                    [ 'name' => 'Method', 'value' => $trace['method'] ],
                    [ 'name' => 'Duration (ms)', 'value' => (string) $trace['total_ms'] ],
                    [ 'name' => 'Date', 'value' => $trace['created_at'] ],
                    [ 'name' => 'IP Address', 'value' => $trace['ip_address'] ],
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => count( $traces ) < 50,
        ];
    }

    public function erase_user_data( string $email_address, int $page = 1 ): array
    {
        $user = get_user_by( 'email', $email_address );
        if ( ! $user ) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $deleted = $this->storage->delete_traces_by_user( $user->ID );

        return [
            'items_removed'  => $deleted,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
