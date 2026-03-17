<?php

declare(strict_types=1);

namespace WPFlame;

class CallbackWrapper
{
    /** @var callable */
    private $original;
    private Collector $collector;
    private string $hook_name;
    private int $priority;
    private string $span_name;
    private array $span_source;
    private float $min_duration_ms;

    /**
     * @param callable $original       The original callback to wrap
     * @param Collector $collector     The span collector
     * @param string $hook_name        WordPress hook name
     * @param int $priority            Hook priority
     * @param string $span_name        Pre-resolved callback name
     * @param array $span_source       Pre-resolved ['type' => ..., 'source' => ...]
     * @param float $min_duration_ms   Minimum duration to retain span
     */
    public function __construct(
        $original,
        Collector $collector,
        string $hook_name,
        int $priority,
        string $span_name,
        array $span_source,
        float $min_duration_ms
    ) {
        $this->original        = $original;
        $this->collector       = $collector;
        $this->hook_name       = $hook_name;
        $this->priority        = $priority;
        $this->span_name       = $span_name;
        $this->span_source     = $span_source;
        $this->min_duration_ms = $min_duration_ms;
    }

    /**
     * Invoke the wrapped callback with span timing.
     *
     * @return mixed
     */
    public function __invoke(...$args)
    {
        $span_id = $this->collector->start_span(
            $this->span_name,
            $this->span_source['type'],
            $this->span_source['source'],
            ['hook' => $this->hook_name, 'priority' => $this->priority]
        );

        $result = null;
        try {
            $result = call_user_func_array($this->original, $args);
        } finally {
            $this->collector->end_span_filtered($span_id, $this->min_duration_ms);
        }

        return $result;
    }

    /**
     * Get the original unwrapped callback.
     *
     * @return callable
     */
    public function get_original()
    {
        return $this->original;
    }
}
