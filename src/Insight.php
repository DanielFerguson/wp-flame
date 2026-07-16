<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Insight
{
    /** @var string */
    public $id;
    /** @var string */
    public $severity;
    /** @var string */
    public $title;
    /** @var string */
    public $detail;
    /** @var string[] */
    public $affected_span_ids;
    /** @var string|null */
    public $source;
    /** @var array|null */
    public $remediation;
    /** @var string|null */
    public $source_version;
    /** @var float */
    public $measured_impact_ms;
    /** @var int */
    public $evidence_count;
    /** @var string */
    public $confidence;
    /** @var string */
    public $required_capability;
    /** @var string */
    public $action_type;
    /** @var string */
    public $verification;

    public function __construct(
        string $id,
        string $severity,
        string $title,
        string $detail,
        array $affected_span_ids = [],
        ?string $source = null,
        ?array $remediation = null,
        ?string $source_version = null,
        float $measured_impact_ms = 0.0,
        int $evidence_count = 0,
        string $confidence = 'medium',
        string $required_capability = '',
        string $action_type = 'investigate',
        string $verification = ''
    ) {
        $this->id                = $id;
        $this->severity          = $severity;
        $this->title             = $title;
        $this->detail            = $detail;
        $this->affected_span_ids = $affected_span_ids;
        $this->source            = $source;
        $this->remediation       = $remediation;
        $this->source_version    = $source_version;
        $this->measured_impact_ms = is_finite( $measured_impact_ms ) ? max( 0.0, $measured_impact_ms ) : 0.0;
        $this->evidence_count    = max( 0, $evidence_count );
        $this->confidence        = in_array( $confidence, [ 'low', 'medium', 'high' ], true ) ? $confidence : 'medium';
        $this->required_capability = $required_capability;
        $this->action_type       = $action_type;
        $this->verification      = $verification;
    }

    public function enrich_from_trace(
        Trace $trace,
        string $required_capability,
        string $action_type,
        string $verification
    ): void {
        if ( $this->required_capability === '' ) {
            $this->required_capability = $required_capability;
        }
        if ( $this->action_type === 'investigate' ) {
            $this->action_type = $action_type;
        }
        if ( $this->verification === '' ) {
            $this->verification = $verification;
        }
        if ( $this->remediation === null ) {
            $this->remediation = [
                'next_action' => self::default_next_action( $this->action_type ),
            ];
        }

        $span_by_id = [];
        foreach ( $trace->spans as $span ) {
            if ( $span->id !== '' && ! isset( $span_by_id[ $span->id ] ) ) {
                $span_by_id[ $span->id ] = $span;
            }
        }

        $impact = 0.0;
        $evidence = 0;
        foreach ( array_values( array_unique( $this->affected_span_ids ) ) as $span_id ) {
            if ( ! isset( $span_by_id[ $span_id ] ) ) {
                continue;
            }
            $span = $span_by_id[ $span_id ];
            $impact += $span->duration_ms;
            $evidence++;
            if ( $this->source === null && $span->source !== '' ) {
                $this->source = $span->source;
            }
            if ( $this->source_version === null ) {
                $version = Config::string_value( $span->meta['source_version'] ?? '', '' );
                $this->source_version = $version !== '' ? $version : null;
            }
        }
        if ( $this->measured_impact_ms <= 0.0 ) {
            $this->measured_impact_ms = $impact;
        }
        if ( $this->evidence_count <= 0 ) {
            $this->evidence_count = max( 1, $evidence );
        }

        $complete = $trace->capture_report->incomplete_reasons === []
            && ! $trace->capture_report->trace_truncated
            && $trace->capture_report->dropped_span_count === 0
            && $trace->capture_report->auto_closed_span_count === 0
            && $trace->capture_report->trimmed_span_count === 0;
        $capability_captured = $this->required_capability === ''
            || Config::string_value( $trace->capture_report->capabilities[ $this->required_capability ]['status'] ?? '', '' ) === 'captured';
        $this->confidence = $complete && $capability_captured ? 'high' : 'low';
    }

    private static function default_next_action( string $action_type ): string
    {
        $actions = [
            'cache_or_defer_http'       => __( 'Cache the response when correctness allows, defer non-critical work, or give the host/status evidence to the owning developer.', 'wp-flame' ),
            'repair_http_dependency'    => __( 'Check the dependency health and failure path before changing timeouts.', 'wp-flame' ),
            'defer_http'                => __( 'Move non-critical network work out of the blocking lifecycle phase.', 'wp-flame' ),
            'deduplicate_or_cache_query' => __( 'Reuse the result within the request or add an appropriately invalidated cache at the owning source.', 'wp-flame' ),
            'optimize_query'            => __( 'Review the fingerprint, execution plan, indexes, result size, and calling component.', 'wp-flame' ),
            'repair_query'              => __( 'Use database error logs and the query fingerprint to repair the caller safely.', 'wp-flame' ),
            'reduce_query_count'        => __( 'Start with the most repeated fingerprint or largest database-owning source.', 'wp-flame' ),
            'optimize_callback'         => __( 'Profile the named callback in a development copy and reduce, cache, or defer its work.', 'wp-flame' ),
            'configure_persistent_cache' => __( 'Confirm host support, then configure a supported persistent object-cache drop-in.', 'wp-flame' ),
            'improve_cache_usage'       => __( 'Identify repeatedly missed cache groups and correct cache keys or invalidation behavior.', 'wp-flame' ),
            'investigate_route_budget'  => __( 'Address the largest supported contributor for this route first.', 'wp-flame' ),
            'restore_capture_capability' => __( 'Resolve the listed capability limitation before drawing a negative conclusion.', 'wp-flame' ),
        ];

        return $actions[ $action_type ] ?? __( 'Give the measured evidence to the owning developer and repeat the same workflow after one controlled change.', 'wp-flame' );
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'severity' => $this->severity,
            'title'    => $this->title,
            'detail'   => $this->detail,
            'span_ids' => $this->affected_span_ids,
            'source'   => $this->source,
            'source_version' => $this->source_version,
            'measured_impact_ms' => $this->measured_impact_ms,
            'evidence_count' => $this->evidence_count,
            'confidence' => $this->confidence,
            'required_capability' => $this->required_capability,
            'action_type' => $this->action_type,
            'remediation' => $this->remediation,
            'verification' => $this->verification,
        ];
    }
}
