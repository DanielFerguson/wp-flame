# Database capture strategy for public v1

**Status:** Accepted for public v1

**Decision date:** 2026-07-16

## Context

WP Flame needs useful query timing without changing database behavior, overstating coverage, or requiring a PHP extension. WordPress installations may use core `wpdb`, a subclass supplied by a host or plugin, a query logger, or an entirely different profiler agent.

## Options considered

### Replace an exact core `wpdb` instance with the WP Flame wrapper

This captures queries from the point the wrapper is installed and preserves the core object's state. It offers per-query timing and attribution without requiring `SAVEQUERIES`. Replacing an arbitrary subclass is unsafe because subclass behavior, private state, connection routing, or host integrations may be lost.

### Use `SAVEQUERIES` and `log_query_custom_data`

This is a supported WordPress mechanism and avoids replacing `wpdb`. It increases WordPress query logging overhead and only applies after `SAVEQUERIES` is enabled. WP Flame uses this path for an applicable WPGraphQL request where the operation/resolver adapter needs the supported query hook, but it is not the default general database strategy.

### Add adapters for custom database layers

An adapter can preserve a custom layer and expose its own supported timing hooks. Public v1 keeps an extension point for adapters, but ships no unverified host-specific adapter. Absence of an adapter is reported as unavailable, not as zero database time.

### Use an external profiler agent

An extension such as an APM/profiler agent could provide broader and earlier coverage. That would change installation requirements and product scope, so it remains a future optional integration rather than a v1 dependency.

### Explicitly report database capture as unavailable

This is the safe fallback whenever the selected strategy cannot prove compatibility or registration fails. The trace stays usable, but database-dependent scoring and recommendations remain unknown.

## Decision

Public v1 uses the exact-core-`wpdb` wrapper for general Standard and Deep captures. `DB::can_replace()` must remain strict: only an object whose concrete class is `wpdb` may be replaced. WPGraphQL may use the WordPress query hook after it is identified as applicable. Filter-provided instrumentors may add explicit adapters.

Each trace reports the chosen strategy, its capture start stage, and whether registration succeeded. WP Flame never claims queries that ran before the instrumentor registered. Custom subclasses remain untouched and produce a visible `custom_database_layer_unsupported` capability reason unless an adapter succeeds.

Once the Collector stops, the DB wrapper and query-hook callbacks immediately delegate or return without normalization, hashing, backtraces, or attribution work. Failed queries may record a bounded boolean failure signal, but raw database error messages are not stored because they may contain SQL values or infrastructure details.

## Consequences

- Standard and Deep database telemetry is useful on normal core-`wpdb` sites.
- Some managed hosts and database plugins will show database telemetry as unavailable.
- Missing database telemetry is excluded from score certainty rather than treated as excellent performance.
- Early queries that occurred before the reported capture start are outside the measurement contract.
- Future adapters must preserve the same capability and start-point reporting contract.
