<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit, bounded outcome for one trace persistence attempt.
 */
class StorageResult
{
    public const STORED = 'stored';
    public const ENCODING_FAILED = 'encoding_failed';
    public const SIZE_LIMIT_FAILED = 'size_limit_failed';
    public const INSERT_FAILED = 'insert_failed';
    public const QUOTA_REACHED = 'quota_reached';

    public bool $success;
    public string $status;
    public int $stored_bytes;
    public bool $trace_truncated;

    private function __construct( bool $success, string $status, int $stored_bytes, bool $trace_truncated )
    {
        $this->success = $success;
        $this->status = $status;
        $this->stored_bytes = max( 0, $stored_bytes );
        $this->trace_truncated = $trace_truncated;
    }

    public static function stored( int $stored_bytes, bool $trace_truncated ): self
    {
        return new self( true, self::STORED, $stored_bytes, $trace_truncated );
    }

    public static function failed( string $status ): self
    {
        $allowed = [ self::ENCODING_FAILED, self::SIZE_LIMIT_FAILED, self::INSERT_FAILED, self::QUOTA_REACHED ];
        if ( ! in_array( $status, $allowed, true ) ) {
            $status = self::INSERT_FAILED;
        }

        return new self( false, $status, 0, false );
    }
}
