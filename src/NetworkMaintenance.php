<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Durable, bounded coordinator for operations that must visit every site.
 */
class NetworkMaintenance
{
    public const BATCH_SIZE = 10;
    public const STATE_OPTION = 'wp_flame_network_maintenance';
    public const CRON_HOOK = 'wp_flame_network_maintenance';

    /**
     * @return array<string, mixed>
     */
    public static function start( string $operation ): array
    {
        $allowed = [ 'activate', 'migrate', 'prune', 'deactivate' ];
        if ( ! in_array( $operation, $allowed, true ) ) {
            return self::empty_state( 'invalid', $operation );
        }

        $state = self::empty_state( 'pending', $operation );
        update_site_option( self::STATE_OPTION, $state );
        self::schedule();

        return $state;
    }

    /**
     * @param callable(string, int): array{status: string, message?: string} $operator
     * @return array<string, mixed>
     */
    public static function run_batch( callable $operator ): array
    {
        $stored = get_site_option( self::STATE_OPTION, [] );
        $state = is_array( $stored ) ? $stored : [];
        $operation = Config::string_value( $state['operation'] ?? '', '' );
        if ( ! in_array( $operation, [ 'activate', 'migrate', 'prune', 'deactivate' ], true ) ) {
            return self::empty_state( 'idle', '' );
        }

        $cursor = Config::bounded_int( $state['cursor'] ?? 0, 0, 0, PHP_INT_MAX );
        $retry_sites = self::site_id_list( $state['retry_sites'] ?? [] );
        $enumeration_complete = Config::boolean( $state['enumeration_complete'] ?? false );
        if ( $enumeration_complete ) {
            $site_ids = array_splice( $retry_sites, 0, self::BATCH_SIZE );
        } else {
            $queried = get_sites( [
                'fields' => 'ids',
                'number' => self::BATCH_SIZE,
                'offset' => $cursor,
                'orderby' => 'id',
                'order' => 'ASC',
            ] );
            $site_ids = self::site_id_list( $queried );
            $cursor += count( $site_ids );
            if ( count( $site_ids ) < self::BATCH_SIZE ) {
                $enumeration_complete = true;
            }
        }

        $processed = Config::bounded_int( $state['processed'] ?? 0, 0, 0, PHP_INT_MAX );
        $failures = isset( $state['failures'] ) && is_array( $state['failures'] ) ? $state['failures'] : [];
        foreach ( $site_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            try {
                $outcome = $operator( $operation, $blog_id );
                $status = Config::string_value( $outcome['status'] ?? 'failed', 'failed' );
                if ( $status === 'retry' ) {
                    $retry_sites[] = $blog_id;
                } elseif ( $status !== 'complete' ) {
                    $failures[] = [
                        'blog_id' => $blog_id,
                        'message' => substr( Config::string_value( $outcome['message'] ?? 'Unknown failure.', 'Unknown failure.' ), 0, 300 ),
                    ];
                }
                $processed++;
            } catch ( \Throwable $error ) {
                $failures[] = [
                    'blog_id' => $blog_id,
                    'message' => substr( $error->getMessage(), 0, 300 ),
                ];
            } finally {
                restore_current_blog();
            }
        }

        $retry_sites = array_values( array_unique( $retry_sites ) );
        $complete = $enumeration_complete && $retry_sites === [];
        $state = [
            'status'               => $complete ? ( $failures === [] ? 'complete' : 'complete_with_failures' ) : 'pending',
            'operation'            => $operation,
            'cursor'               => $cursor,
            'processed'            => $processed,
            'retry_sites'          => $retry_sites,
            'enumeration_complete' => $enumeration_complete,
            'failures'             => array_slice( $failures, -50 ),
            'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
        ];
        update_site_option( self::STATE_OPTION, $state );
        if ( ! $complete ) {
            self::schedule();
        }

        return $state;
    }

    public static function schedule(): void
    {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 30, self::CRON_HOOK );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty_state( string $status, string $operation ): array
    {
        return [
            'status'               => $status,
            'operation'            => $operation,
            'cursor'               => 0,
            'processed'            => 0,
            'retry_sites'          => [],
            'enumeration_complete' => false,
            'failures'             => [],
            'updated_at'           => gmdate( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private static function site_id_list( $value ): array
    {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $site_ids = [];
        foreach ( array_slice( $value, 0, self::BATCH_SIZE * 1000 ) as $site_id ) {
            $site_id = Config::bounded_int( $site_id, 0, 0, PHP_INT_MAX );
            if ( $site_id > 0 ) {
                $site_ids[] = $site_id;
            }
        }

        return array_values( array_unique( $site_ids ) );
    }
}
