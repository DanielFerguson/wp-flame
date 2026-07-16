<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CaptureSession
{
    public const MAX_STANDARD_REQUESTS = 20;
    public const MAX_STANDARD_TTL_SECONDS = 15 * 60;
    public const MAX_DEEP_TTL_SECONDS = 5 * 60;
    public const BROWSER_REQUEST_TYPES = [ 'frontend', 'admin', 'ajax', 'rest', 'graphql' ];
    private const PHASES = [ 'observation', 'baseline', 'after' ];
    private const STATUSES = [ 'active', 'complete', 'expired', 'cancelled' ];

    public string $id;
    public string $route_key;
    public string $request_type;
    public string $capture_policy;
    public string $instrumentation_mode;
    public int $requested_count;
    public int $captured_count;
    public string $expires_at;
    public string $phase;
    public string $status;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct( array $data )
    {
        $this->id = self::bounded_string( $data['id'] ?? '', 36 );
        $this->route_key = self::bounded_string( $data['route_key'] ?? '', 512 );
        $this->request_type = self::bounded_string( $data['request_type'] ?? 'unknown', 40 );
        $this->capture_policy = self::bounded_string( $data['capture_policy'] ?? 'manual_session', 128 );
        $this->instrumentation_mode = self::enum_value( $data['instrumentation_mode'] ?? '', [ 'safe', 'standard', 'deep' ], 'standard' );
        $this->requested_count = Config::bounded_int( $data['requested_count'] ?? 1, 1, 1, 1000 );
        $this->captured_count = Config::bounded_int( $data['captured_count'] ?? 0, 0, 0, $this->requested_count );
        $this->expires_at = self::bounded_string( $data['expires_at'] ?? '', 19 );
        $this->phase = self::enum_value( $data['phase'] ?? '', self::PHASES, 'observation' );
        $this->status = self::enum_value( $data['status'] ?? '', self::STATUSES, 'active' );

        if ( $this->request_type === '' ) {
            $this->request_type = 'unknown';
        }
        if ( $this->capture_policy === '' ) {
            $this->capture_policy = 'manual_session';
        }
    }

    public function is_expired( ?int $now = null ): bool
    {
        if ( $this->expires_at === '' ) {
            return false;
        }

        $expiry = strtotime( $this->expires_at . ' UTC' );
        if ( $expiry === false ) {
            return true;
        }

        return $expiry <= ( $now ?? time() );
    }

    public function accepts_trace( ?int $now = null ): bool
    {
        return $this->status === 'active'
            && ! $this->is_expired( $now )
            && $this->captured_count < $this->requested_count;
    }

    public function remaining_count(): int
    {
        return max( 0, $this->requested_count - $this->captured_count );
    }

    public function progress_percent(): int
    {
        if ( $this->requested_count < 1 ) {
            return 0;
        }

        return min( 100, (int) floor( ( $this->captured_count / $this->requested_count ) * 100 ) );
    }

    /**
     * Keep guided browser sessions inside the request population the user chose.
     * This prevents an incidental REST/AJAX request from claiming a page capture.
     *
     * @param mixed $value
     */
    public static function browser_request_type( $value ): string
    {
        $value = self::bounded_string( $value, 40 );

        return in_array( $value, self::BROWSER_REQUEST_TYPES, true ) ? $value : 'frontend';
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'session_id'           => $this->id,
            'route_key'            => $this->route_key,
            'request_type'         => $this->request_type,
            'capture_policy'       => $this->capture_policy,
            'instrumentation_mode' => $this->instrumentation_mode,
            'requested_count'      => $this->requested_count,
            'captured_count'       => $this->captured_count,
            'expires_at'           => $this->expires_at,
            'phase'                => $this->phase,
            'status'               => $this->status,
        ];
    }

    /**
     * @param mixed    $value
     * @param string[] $allowed
     */
    private static function enum_value( $value, array $allowed, string $fallback ): string
    {
        $value = self::bounded_string( $value, 40 );

        return in_array( $value, $allowed, true ) ? $value : $fallback;
    }

    /**
     * @param mixed $value
     */
    private static function bounded_string( $value, int $max_bytes ): string
    {
        $value = Config::string_value( $value, '' );

        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }
}
