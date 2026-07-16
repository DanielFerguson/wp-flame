<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ComparisonReport
{
    /** @param array<string, mixed> $comparison */
    public static function json( array $comparison, bool $include_sensitive = false ): string
    {
        $report = $comparison;
        $signature = isset( $report['signature'] ) && is_array( $report['signature'] ) ? $report['signature'] : [];
        $route = Config::string_value( $signature['route_key'] ?? '', '' );
        if ( ! $include_sensitive ) {
            $signature['route_key'] = Redactor::redact_request_uri( $route );
        }
        $report['signature'] = $signature;
        $report['report'] = [
            'schema'                    => 'wp-flame-comparison.v1',
            'generated_at_utc'          => gmdate( 'c' ),
            'redacted_by_default'       => true,
            'includes_sensitive_fields' => $include_sensitive,
            'excludes'                  => [ 'raw_spans', 'raw_sql', 'full_http_urls', 'user_ids', 'ip_addresses', 'user_agents' ],
        ];
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? $json : '{}';
    }
}
