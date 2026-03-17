<?php

declare(strict_types=1);

namespace WPFlame;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Trace
{
    public string $id;
    public string $url;
    public string $method;
    public string $timestamp;
    public float $total_ms;
    public int $peak_memory;
    public string $php_version;
    public string $wp_version;
    public int $query_count;
    public float $total_query_ms;
    /** @var Span[] */
    public array $spans;
    public array $meta;

    /**
     * @param Span[] $spans
     */
    public function __construct(
        string $id,
        string $url,
        string $method,
        string $timestamp,
        float $total_ms,
        int $peak_memory,
        string $php_version,
        string $wp_version,
        array $spans,
        array $meta = []
    ) {
        $this->id          = $id;
        $this->url         = $url;
        $this->method      = $method;
        $this->timestamp   = $timestamp;
        $this->total_ms    = $total_ms;
        $this->peak_memory = $peak_memory;
        $this->php_version = $php_version;
        $this->wp_version  = $wp_version;
        $this->spans       = $spans;
        $this->meta        = $meta;

        // Compute query aggregates from DB-type spans
        $this->query_count    = 0;
        $this->total_query_ms = 0.0;
        foreach ($spans as $span) {
            if ($span->type === Span::TYPE_DB) {
                $this->query_count++;
                $this->total_query_ms += $span->duration_ms;
            }
        }
    }

    public function toArray(): array
    {
        return [
            'v'              => 1,
            'id'             => $this->id,
            'url'            => $this->url,
            'method'         => $this->method,
            'timestamp'      => $this->timestamp,
            'total_ms'       => $this->total_ms,
            'peak_memory'    => $this->peak_memory,
            'php_version'    => $this->php_version,
            'wp_version'     => $this->wp_version,
            'query_count'    => $this->query_count,
            'total_query_ms' => $this->total_query_ms,
            'spans'          => array_map(fn(Span $s) => $s->toArray(), $this->spans),
            'meta'           => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        $version = isset( $data['v'] ) ? (int) $data['v'] : 1;
        // Future: if ( $version < 2 ) { $data = self::migrate_v1_to_v2( $data ); }

        $spans = array_map(
            fn(array $s) => Span::fromArray($s),
            $data['spans'] ?? []
        );

        return new self(
            (string) $data['id'],
            (string) $data['url'],
            (string) $data['method'],
            (string) $data['timestamp'],
            (float) $data['total_ms'],
            (int) $data['peak_memory'],
            (string) $data['php_version'],
            (string) $data['wp_version'],
            $spans,
            $data['meta'] ?? []
        );
    }
}
