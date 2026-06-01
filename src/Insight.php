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

    public function __construct(
        string $id,
        string $severity,
        string $title,
        string $detail,
        array $affected_span_ids = [],
        ?string $source = null,
        ?array $remediation = null
    ) {
        $this->id                = $id;
        $this->severity          = $severity;
        $this->title             = $title;
        $this->detail            = $detail;
        $this->affected_span_ids = $affected_span_ids;
        $this->source            = $source;
        $this->remediation       = $remediation;
    }

    /**
     * @return array{severity: string, title: string, detail: string}
     */
    public function to_array(): array
    {
        return [
            'severity' => $this->severity,
            'title'    => $this->title,
            'detail'   => $this->detail,
        ];
    }
}
