<?php

declare(strict_types=1);

namespace WPFlame;

class Span
{
    public const TYPE_CORE   = 'core';
    public const TYPE_PLUGIN = 'plugin';
    public const TYPE_THEME  = 'theme';
    public const TYPE_DB     = 'db';
    public const TYPE_HTTP   = 'http';
    public const TYPE_PHP    = 'php';

    public string $id;
    public ?string $parent_id;
    public string $name;
    public string $type;
    public string $source;
    public float $start_ms;
    public float $duration_ms;
    public array $meta;

    public function __construct(
        string $id,
        ?string $parent_id,
        string $name,
        string $type,
        string $source,
        float $start_ms,
        float $duration_ms,
        array $meta = []
    ) {
        $this->id          = $id;
        $this->parent_id   = $parent_id;
        $this->name        = $name;
        $this->type        = $type;
        $this->source      = $source;
        $this->start_ms    = $start_ms;
        $this->duration_ms = $duration_ms;
        $this->meta        = $meta;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'name'        => $this->name,
            'type'        => $this->type,
            'source'      => $this->source,
            'start_ms'    => $this->start_ms,
            'duration_ms' => $this->duration_ms,
            'meta'        => $this->meta,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            isset($data['parent_id']) ? (string) $data['parent_id'] : null,
            (string) $data['name'],
            (string) $data['type'],
            (string) $data['source'],
            (float) $data['start_ms'],
            (float) $data['duration_ms'],
            (array) ($data['meta'] ?? [])
        );
    }
}
