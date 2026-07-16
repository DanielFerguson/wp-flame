<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bounded, trace-level description of what one capture did and did not observe.
 */
class CaptureReport
{
    public const CAPABILITY_KEYS = [
        'early_lifecycle',
        'database',
        'callbacks',
        'http',
        'graphql',
        'cache_counters',
    ];

    public const CAPABILITY_STATUSES = [
        'captured',
        'not_requested',
        'unavailable',
        'failed',
    ];

    private const CAPTURE_PHASES = [ 'baseline', 'after', 'observation' ];
    private const CAPTURE_ORIGINS = [ 'manual', 'forced', 'session', 'sampled', 'background', 'legacy' ];
    private const INSTRUMENTATION_MODES = [ 'safe', 'standard', 'deep', 'unknown' ];
    private const MAX_ID_BYTES = 128;
    private const MAX_POLICY_BYTES = 128;
    private const MAX_REQUEST_TYPE_BYTES = 40;
    private const MAX_ROUTE_KEY_BYTES = 512;
    private const MAX_STAGE_BYTES = 80;
    private const MAX_VERSION_BYTES = 64;
    private const MAX_REASON_BYTES = 200;
    private const MAX_INCOMPLETE_REASONS = 20;
    private const MAX_COUNT = 1000000;

    public ?string $capture_session_id;
    public string $capture_phase;
    public string $capture_origin;
    public string $capture_policy;
    public string $request_type;
    public string $route_key;
    public ?int $http_status;
    public string $instrumentation_mode;
    public int $sample_rate;
    public float $effective_sample_probability;
    public string $capture_start_stage;
    public float $observed_duration_ms;
    public ?float $request_start_reference_ms;
    public ?float $unobserved_prebootstrap_ms;
    public string $wp_flame_version;
    public int $score_version;
    public ?string $environment_snapshot_id;
    /** @var array<string, array{status: string, reason: string}> */
    public array $capabilities;
    /** @var string[] */
    public array $incomplete_reasons;
    public int $dropped_span_count;
    public int $auto_closed_span_count;
    public int $trimmed_span_count;
    public bool $trace_truncated;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct( array $data, float $observed_duration_ms )
    {
        $session_id = self::bounded_string( $data['capture_session_id'] ?? '', self::MAX_ID_BYTES );
        $snapshot_id = self::bounded_string( $data['environment_snapshot_id'] ?? '', self::MAX_ID_BYTES );

        $this->capture_session_id = $session_id !== '' ? $session_id : null;
        $this->capture_phase = self::enum_value( $data['capture_phase'] ?? '', self::CAPTURE_PHASES, 'observation' );
        $this->capture_origin = self::enum_value( $data['capture_origin'] ?? '', self::CAPTURE_ORIGINS, 'sampled' );
        $this->capture_policy = self::bounded_string( $data['capture_policy'] ?? 'configured_sampling', self::MAX_POLICY_BYTES );
        $this->request_type = self::bounded_string( $data['request_type'] ?? 'unknown', self::MAX_REQUEST_TYPE_BYTES );
        $this->route_key = self::bounded_string( $data['route_key'] ?? '', self::MAX_ROUTE_KEY_BYTES );
        $this->http_status = self::nullable_bounded_int( $data['http_status'] ?? null, 100, 599 );
        $this->instrumentation_mode = self::enum_value( $data['instrumentation_mode'] ?? '', self::INSTRUMENTATION_MODES, 'unknown' );
        $this->sample_rate = self::bounded_int( $data['sample_rate'] ?? 1, 1, 1, self::MAX_COUNT );
        $this->effective_sample_probability = self::bounded_float( $data['effective_sample_probability'] ?? 1.0, 1.0, 0.0, 1.0 );
        $this->capture_start_stage = self::bounded_string( $data['capture_start_stage'] ?? 'unknown', self::MAX_STAGE_BYTES );
        $this->observed_duration_ms = self::bounded_float( $observed_duration_ms, 0.0, 0.0, 86400000.0 );
        $this->request_start_reference_ms = self::nullable_bounded_float( $data['request_start_reference_ms'] ?? null, 0.0, PHP_FLOAT_MAX );
        $this->unobserved_prebootstrap_ms = self::nullable_bounded_float( $data['unobserved_prebootstrap_ms'] ?? null, 0.0, 86400000.0 );
        $this->wp_flame_version = self::bounded_string( $data['wp_flame_version'] ?? '', self::MAX_VERSION_BYTES );
        $this->score_version = self::bounded_int( $data['score_version'] ?? 1, 1, 1, 100000 );
        $this->environment_snapshot_id = $snapshot_id !== '' ? $snapshot_id : null;
        $this->capabilities = self::normalize_capabilities( $data['capabilities'] ?? [] );
        $this->incomplete_reasons = self::normalize_reasons( $data['incomplete_reasons'] ?? [] );
        $this->dropped_span_count = self::bounded_int( $data['dropped_span_count'] ?? 0, 0, 0, self::MAX_COUNT );
        $this->auto_closed_span_count = self::bounded_int( $data['auto_closed_span_count'] ?? 0, 0, 0, self::MAX_COUNT );
        $this->trimmed_span_count = self::bounded_int( $data['trimmed_span_count'] ?? 0, 0, 0, self::MAX_COUNT );
        $this->trace_truncated = Config::boolean( $data['trace_truncated'] ?? false );

        if ( $this->capture_policy === '' ) {
            $this->capture_policy = 'configured_sampling';
        }
        if ( $this->request_type === '' ) {
            $this->request_type = 'unknown';
        }
        if ( $this->capture_start_stage === '' ) {
            $this->capture_start_stage = 'unknown';
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $legacy_meta
     * @param Span[]               $spans
     */
    public static function from_trace_data(
        array $data,
        float $observed_duration_ms,
        array $legacy_meta,
        array $spans,
        bool $legacy
    ): self {
        if ( ! $legacy ) {
            return new self( $data, $observed_duration_ms );
        }

        $auto_closed = 0;
        foreach ( $spans as $span ) {
            if ( $span instanceof Span && ! empty( $span->meta['auto_closed'] ) ) {
                $auto_closed++;
            }
        }

        $capabilities = [];
        foreach ( self::CAPABILITY_KEYS as $key ) {
            $capabilities[ $key ] = [
                'status' => 'unavailable',
                'reason' => 'legacy_trace',
            ];
        }

        return new self(
            [
                'capture_phase'              => 'observation',
                'capture_origin'             => 'legacy',
                'capture_policy'             => 'legacy',
                'request_type'               => 'unknown',
                'instrumentation_mode'       => 'unknown',
                'capture_start_stage'        => 'unknown',
                'wp_flame_version'           => self::bounded_string( $legacy_meta['wp_flame_version'] ?? '', self::MAX_VERSION_BYTES ),
                'score_version'              => 1,
                'capabilities'               => $capabilities,
                'incomplete_reasons'         => [ 'legacy_capture_metadata_unavailable' ],
                'dropped_span_count'         => $legacy_meta['wp_flame_dropped_spans'] ?? 0,
                'auto_closed_span_count'     => $auto_closed,
                'trimmed_span_count'         => max(
                    0,
                    self::bounded_int( $legacy_meta['wp_flame_original_span_count'] ?? 0, 0, 0, self::MAX_COUNT )
                        - self::bounded_int( $legacy_meta['wp_flame_stored_span_count'] ?? 0, 0, 0, self::MAX_COUNT )
                ),
                'trace_truncated'            => $legacy_meta['wp_flame_trace_truncated'] ?? false,
            ],
            $observed_duration_ms
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            'capture_session_id'             => $this->capture_session_id,
            'capture_phase'                  => $this->capture_phase,
            'capture_origin'                 => $this->capture_origin,
            'capture_policy'                 => $this->capture_policy,
            'request_type'                   => $this->request_type,
            'route_key'                      => $this->route_key,
            'http_status'                    => $this->http_status,
            'instrumentation_mode'           => $this->instrumentation_mode,
            'sample_rate'                    => $this->sample_rate,
            'effective_sample_probability'   => $this->effective_sample_probability,
            'capture_start_stage'            => $this->capture_start_stage,
            'observed_duration_ms'            => $this->observed_duration_ms,
            'request_start_reference_ms'     => $this->request_start_reference_ms,
            'unobserved_prebootstrap_ms'      => $this->unobserved_prebootstrap_ms,
            'wp_flame_version'               => $this->wp_flame_version,
            'score_version'                  => $this->score_version,
            'environment_snapshot_id'        => $this->environment_snapshot_id,
            'capabilities'                   => $this->capabilities,
            'incomplete_reasons'             => $this->incomplete_reasons,
            'dropped_span_count'             => $this->dropped_span_count,
            'auto_closed_span_count'         => $this->auto_closed_span_count,
            'trimmed_span_count'             => $this->trimmed_span_count,
            'trace_truncated'                => $this->trace_truncated,
        ];
    }

    public function capability_data(): string
    {
        $json = wp_json_encode( $this->capabilities, JSON_UNESCAPED_SLASHES );

        return is_string( $json ) ? substr( $json, 0, 4096 ) : '{}';
    }

    public function capability_cohort(): string
    {
        return hash( 'sha256', $this->capability_data() );
    }

    /**
     * @param mixed $value
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
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
            return '';
        }

        $value = (string) $value;

        return strlen( $value ) <= $max_bytes ? $value : substr( $value, 0, $max_bytes );
    }

    /**
     * @param mixed $value
     */
    private static function bounded_int( $value, int $fallback, int $min, int $max ): int
    {
        if ( ! is_numeric( $value ) ) {
            return $fallback;
        }

        return min( $max, max( $min, (int) $value ) );
    }

    /**
     * @param mixed $value
     */
    private static function nullable_bounded_int( $value, int $min, int $max ): ?int
    {
        if ( $value === null || ! is_numeric( $value ) ) {
            return null;
        }

        return min( $max, max( $min, (int) $value ) );
    }

    /**
     * @param mixed $value
     */
    private static function bounded_float( $value, float $fallback, float $min, float $max ): float
    {
        if ( ! is_numeric( $value ) ) {
            return $fallback;
        }

        $number = (float) $value;
        if ( ! is_finite( $number ) ) {
            return $fallback;
        }

        return min( $max, max( $min, $number ) );
    }

    /**
     * @param mixed $value
     */
    private static function nullable_bounded_float( $value, float $min, float $max ): ?float
    {
        if ( $value === null || ! is_numeric( $value ) ) {
            return null;
        }

        $number = (float) $value;
        if ( ! is_finite( $number ) ) {
            return null;
        }

        return min( $max, max( $min, $number ) );
    }

    /**
     * @param mixed $capabilities
     * @return array<string, array{status: string, reason: string}>
     */
    private static function normalize_capabilities( $capabilities ): array
    {
        $capabilities = is_array( $capabilities ) ? $capabilities : [];
        $normalized = [];

        foreach ( self::CAPABILITY_KEYS as $key ) {
            $value = $capabilities[ $key ] ?? [];
            if ( is_string( $value ) ) {
                $value = [ 'status' => $value ];
            }
            $value = is_array( $value ) ? $value : [];
            $status = self::enum_value( $value['status'] ?? '', self::CAPABILITY_STATUSES, 'not_requested' );
            $reason = self::bounded_string( $value['reason'] ?? '', self::MAX_REASON_BYTES );

            $normalized[ $key ] = [
                'status' => $status,
                'reason' => $reason,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $reasons
     * @return string[]
     */
    private static function normalize_reasons( $reasons ): array
    {
        if ( ! is_array( $reasons ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $reasons as $reason ) {
            if ( count( $normalized ) >= self::MAX_INCOMPLETE_REASONS ) {
                break;
            }

            $reason = self::bounded_string( $reason, self::MAX_REASON_BYTES );
            if ( $reason !== '' && ! in_array( $reason, $normalized, true ) ) {
                $normalized[] = $reason;
            }
        }

        return $normalized;
    }
}
