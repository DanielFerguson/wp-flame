# Trace schema v2

**Status:** M1 contract

Trace schema v2 keeps the v1 identity, timing, aggregate, span, and metadata fields while adding a bounded capture report at the top level. `Trace::fromArray()` remains the compatibility boundary for stored JSON.

## Timing contract

- `observed_duration_ms` is the canonical captured interval and remains mirrored to legacy `total_ms` readers.
- `request_start_reference_ms` is nullable. It is stored only when the runtime has a trustworthy request-start reference.
- `unobserved_prebootstrap_ms` is nullable and must never be presented as measured WordPress execution.
- Persistence work remains outside the observed interval.

## Capture identity

- `capture_session_id` and `environment_snapshot_id` are nullable bounded identifiers.
- `capture_phase` is `observation`, `baseline`, or `after`.
- `capture_origin` is `manual`, `forced`, `sampled`, `background`, or `legacy`.
- `capture_policy` is a bounded stable policy identifier, not arbitrary settings JSON.
- `sample_rate` records the configured one-in-N denominator; `effective_sample_probability` records the actual probability for this trace.
- `request_type` and `route_key` support later indexed cohort selection. An empty v2 route key means routing has not yet produced a safe stable key.

## Capability contract

Every v2 trace contains the same capability keys: early lifecycle, database, callbacks, WordPress HTTP API, GraphQL, and cache counters. Each contains:

- `status`: `captured`, `not_requested`, `unavailable`, or `failed`.
- `reason`: an optional bounded machine-readable explanation.

`not_requested` is an intentional mode or policy choice and does not by itself make a trace incomplete. `unavailable` and `failed` must be accompanied by capture context and may add an `incomplete_reasons` entry when the missing capability was requested.

## Completeness contract

`incomplete_reasons` is a bounded, deduplicated list. Dropped spans, auto-closed spans, and storage trimming also have explicit counts/flags (`dropped_span_count`, `auto_closed_span_count`, `trimmed_span_count`, and `trace_truncated`) so downstream scores and findings do not need to infer completeness from arbitrary metadata.

Legacy v1 traces hydrate with `capture_origin=legacy`, unknown mode/start/request type, unavailable capability statuses with `legacy_trace` reasons, and `legacy_capture_metadata_unavailable`. Known v1 dropped, auto-closed, and truncation signals are preserved. Re-serializing the trace upgrades its in-memory representation to v2 without discarding its spans or legacy metadata.
