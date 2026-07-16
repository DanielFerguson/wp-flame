<?php

declare(strict_types=1);

namespace WPFlame;

final class MuPluginManager
{
    public const OWNER_MARKER = 'WP_FLAME_MU_OWNER: wp-flame-early-hooks-v1';

    public const INSTALLED = 'installed';
    public const CURRENT = 'current';
    public const ABSENT = 'absent';
    public const SOURCE_MISSING = 'source_missing';
    public const UNOWNED_COLLISION = 'unowned_collision';
    public const MODIFIED = 'modified';
    public const WRITE_FAILED = 'write_failed';
    public const VERIFICATION_FAILED = 'verification_failed';
    public const REMOVED = 'removed';

    /**
     * Atomically install or upgrade the early-capture file.
     *
     * @param array<int, string> $legacy_owned_hashes Exact hashes of known pre-marker WP Flame loaders.
     * @return array{status: string, hash: string}
     */
    public static function install( string $source, string $destination, string $expected_hash = '', array $legacy_owned_hashes = [] ): array
    {
        $source_hash = self::file_hash( $source );
        if ( $source_hash === '' || ! self::is_owned_file( $source ) ) {
            return self::result( self::SOURCE_MISSING );
        }

        if ( is_file( $destination ) ) {
            $destination_hash = self::file_hash( $destination );
            $legacy_owned     = $destination_hash !== '' && in_array( $destination_hash, $legacy_owned_hashes, true );
            if ( ! self::is_owned_file( $destination ) && ! $legacy_owned ) {
                return self::result( self::UNOWNED_COLLISION );
            }

            if ( $destination_hash !== '' && hash_equals( $source_hash, $destination_hash ) ) {
                return self::result( self::CURRENT, $destination_hash );
            }

            if ( ! $legacy_owned && ( $expected_hash === '' || $destination_hash === '' || ! hash_equals( $expected_hash, $destination_hash ) ) ) {
                return self::result( self::MODIFIED, $destination_hash );
            }
        }

        $directory = dirname( $destination );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Atomic installation requires a local same-directory rename.
        if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
            return self::result( self::WRITE_FAILED );
        }

        $temporary = tempnam( $directory, '.wp-flame-' );
        if ( ! is_string( $temporary ) ) {
            return self::result( self::WRITE_FAILED );
        }

        try {
            if ( ! copy( $source, $temporary ) ) {
                return self::result( self::WRITE_FAILED );
            }

            $source_permissions = fileperms( $source );
            if ( is_int( $source_permissions ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Preserve the packaged file mode on the atomic temporary file.
                chmod( $temporary, $source_permissions & 0777 );
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename is the atomic commit boundary.
            if ( ! rename( $temporary, $destination ) ) {
                return self::result( self::WRITE_FAILED );
            }
            $temporary = '';
        } finally {
            if ( $temporary !== '' && file_exists( $temporary ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup is limited to the manager-created temporary file.
                unlink( $temporary );
            }
        }

        $installed_hash = self::file_hash( $destination );
        if ( ! self::is_owned_file( $destination ) || $installed_hash === '' || ! hash_equals( $source_hash, $installed_hash ) ) {
            return self::result( self::VERIFICATION_FAILED, $installed_hash );
        }

        return self::result( self::INSTALLED, $installed_hash );
    }

    /**
     * Remove only the exact file WP Flame last verified.
     *
     * @return array{status: string, hash: string}
     */
    public static function remove( string $destination, string $expected_hash ): array
    {
        if ( ! file_exists( $destination ) ) {
            return self::result( self::ABSENT );
        }

        if ( ! self::is_owned_file( $destination ) ) {
            return self::result( self::UNOWNED_COLLISION );
        }

        $actual_hash = self::file_hash( $destination );
        if ( $expected_hash === '' || $actual_hash === '' || ! hash_equals( $expected_hash, $actual_hash ) ) {
            return self::result( self::MODIFIED, $actual_hash );
        }

        $removed = function_exists( 'wp_delete_file' )
            ? wp_delete_file( $destination )
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Framework-free fallback; ownership and exact hash were verified above.
            : unlink( $destination );

        return ! file_exists( $destination ) && $removed !== false
            ? self::result( self::REMOVED )
            : self::result( self::WRITE_FAILED, $actual_hash );
    }

    public static function is_owned_file( string $path ): bool
    {
        if ( ! is_readable( $path ) || ! is_file( $path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read only the local ownership header without loading the full file.
        $handle = fopen( $path, 'rb' );
        if ( $handle === false ) {
            return false;
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded local ownership-header read.
            $header = fread( $handle, 4096 );
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the bounded local ownership-header read.
            fclose( $handle );
        }

        return is_string( $header ) && strpos( $header, self::OWNER_MARKER ) !== false;
    }

    public static function file_hash( string $path ): string
    {
        if ( ! is_readable( $path ) || ! is_file( $path ) ) {
            return '';
        }

        $hash = hash_file( 'sha256', $path );
        return is_string( $hash ) ? $hash : '';
    }

    /** @return array{status: string, hash: string} */
    private static function result( string $status, string $hash = '' ): array
    {
        return [
            'status' => $status,
            'hash'   => $hash,
        ];
    }
}
