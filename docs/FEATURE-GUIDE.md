# WP Flame — Current Capability Guide

This guide describes what the current plugin can observe, where capture is conditional, and how to interpret the result. It is a product-truth reference for users, documentation, support, and marketing.

This guide describes the current candidate. Future plans are not present-tense feature claims.

## What WP Flame is

WP Flame is a self-hosted WordPress performance monitoring plugin. It stores bounded traces locally and renders supported server-side spans as an interactive flame-style timeline in wp-admin.

It is best understood as a WordPress request recorder, not a complete PHP call-stack profiler:

- Capture begins at the earliest WP Flame bootstrap point available, not at the web server's absolute request start.
- Persistence work is excluded from the observed request duration.
- Requests served by a page cache or CDN without executing PHP are not visible.
- Direct PHP calls are not automatically profiled.
- Database, callback, GraphQL, cache, and early-lifecycle detail depends on the capabilities available for that request.

## Instrumentation modes

### Safe

Safe mode records supported lifecycle intervals and WordPress HTTP API activity. It is the least intrusive starting point for compatibility-sensitive sites.

Safe mode does not request database or callback capture. Their absence is not evidence that no database or callback work occurred.

### Standard

Standard mode adds database spans when the active `$wpdb` is the core WordPress class and the current instrumentation strategy can be installed.

Queries that ran before database instrumentation registered are not visible. Custom database subclasses and drop-ins may make database capture unavailable.

### Deep

Deep mode adds timing wrappers around supported WordPress hook callbacks. It is intended for short, focused debugging sessions.

Deep mode:

- Observes hook callbacks, not arbitrary PHP functions.
- Mutates the WordPress hook callback table for the request.
- Skips callbacks that cannot be wrapped safely, including supported by-reference and return-by-reference exclusions.
- Applies a duration threshold to retained callback spans, but wrapper invocation still has a cost.
- Runs only for a one-shot forced request or a bounded override of at most 15 minutes; permanent Deep selections are downgraded to Standard.
- Must not be described as an always-on, complete function profiler.

## Interactive flame-style timeline

### What it shows

The graph displays the spans retained for one observed request:

- Observed lifecycle intervals.
- Compatible database spans.
- WordPress HTTP API spans.
- Supported Deep-mode callbacks.
- Experimental GraphQL spans when the expected hooks fire.

Bar width represents inclusive observed wall time. Parent/child nesting reflects the Collector's span stack; it does not prove that every internal operation was observed.

### Current interactions

- Hover a span to see its name, observed duration, source, and percentage.
- Select a span with a pointer, Enter, or Space to inspect its inclusive time, self time, source/version, and bounded type-specific evidence.
- Select a parent span to inspect it and zoom into its children.
- Use breadcrumbs to return to a broader view.
- Search span names, sources, types, and bounded metadata, or filter by source and type. Non-matches are removed from the keyboard focus order.
- Read the millisecond time axis and observed-request overview bar.

The detail panel exposes applicable hook/priority/callback context, a WordPress-content-relative caller location, normalized SQL fingerprint and shape, HTTP result metadata, template/operation context, and truncation/auto-close state. It does not expose unbounded raw payloads.

## Capability-aware performance score

The current score uses observed duration, WordPress HTTP time, database query count, database time ratio, and slow callback counts.

Score v2 treats telemetry that was not requested or was unavailable as unknown. It renormalizes configured weights only across factors that were actually observed and stores the exact factor snapshot used for the historical trace.

Interpret it within its capture cohort:

- Safe mode intentionally lacks DB and callback observations.
- Standard mode intentionally lacks callback observations.
- Unsupported database layers can make DB observations unavailable.
- Legacy Score v1 traces remain displayable but are not mixed into Score v2 route trends.
- Historical scores can still reflect different modes, request types, and capture completeness.

Do not compare scores across incompatible modes, request types, score versions, or capture capabilities.

## Performance findings

WP Flame runs a bounded rule engine over observed trace data. Current rules cover selected slow HTTP, repeated/high query, slow callback, early HTTP, and cache patterns.

Findings are evidence prompts, not guaranteed root causes. Each current finding identifies applicable span evidence, measured impact, evidence count, capability requirement, confidence, the safest next action, and a verification instruction:

- They can reason only over retained spans and request metadata.
- A missing finding does not prove that a category is healthy.
- Source attribution is best-effort.
- Cache counters and backend details may be unavailable.
- Incomplete or unavailable capture lowers confidence and is stated explicitly.

Use a finding to decide what to inspect next, then reproduce the workflow and verify the effect of any change.

## Database query capture

When compatible database instrumentation succeeds, WP Flame can retain:

- Observed query duration.
- Normalized SQL fingerprint or label.
- Best-effort WordPress source.
- Optional bounded query text.

It does not currently guarantee:

- Every query from the beginning of WordPress bootstrap.
- Capture from custom `$wpdb` subclasses.
- Complete caller/function attribution.
- Complete untruncated raw SQL.

Full query text is privacy-sensitive and remains opt-in.

## WordPress HTTP API monitoring

WP Flame observes supported calls made through the WordPress HTTP API. It can retain host, method, status, duration, source, and optionally a bounded full URL.

It does not observe direct cURL, socket, or other network work that bypasses WordPress HTTP hooks.

An HTTP span shows measured waiting time. Whether a request can be removed, deferred, cached, or made asynchronous depends on the owning integration and must be verified.

## Object-cache counters

WP Flame reads public hit/miss counters when the active object-cache implementation exposes compatible values.

A missing counter means unknown, not zero. A class name alone is not reliable evidence of cache backend type or health. Cache guidance uses WordPress's external-cache signal and only draws counter-based conclusions when compatible values are available.

## Cron and background requests

WP Flame can record WordPress cron requests and their supported child spans.

Cron uses an explicit, independently configurable background capture policy. It is manual-only by default and remains subject to bounded retention, storage, and trace limits.

## WPGraphQL support

WP Flame records named operations and supported root-resolver spans when the current WPGraphQL hooks are present. The live compatibility gate covers WPGraphQL 2.17 across Safe, Standard, and bounded Deep modes:

- Request recognition and detailed capture still depend on compatible WPGraphQL lifecycle hooks.
- Resolver timing covers supported root types rather than every nested resolver.
- Safe mode omits GraphQL database spans; Standard and Deep can include them when core `wpdb` instrumentation is available.
- Missing or incompatible hooks produce unavailable/incomplete capability evidence rather than a zero-activity claim.

Marketing should state the tested version and root-resolver boundary rather than imply universal WPGraphQL or arbitrary nested-resolver coverage.

## Trace list and dashboard

The admin area provides:

- Stored trace pagination and filtering.
- Observed duration, method, query, memory, HTTP, and score columns.
- Aggregate cards and recent rankings.
- Slowest request paths and callbacks.
- Observed-duration distribution.
- Optional user/IP groupings when those identity settings are enabled.

Dashboard source/type and callback summaries use versioned, bounded rollups and disclose summarized samples, capability cohorts, score versions, modes, algorithm version, and pending traces. Treat site-wide previews as descriptive: route-specific p50/p95 conclusions and before/after verification require compatible route, capability, mode, environment, and score cohorts.

Score v2 persists the exact factor snapshot used at capture time. A factor whose telemetry was not requested or unavailable is shown as unknown and receives no applied weight. Findings are capability-gated and include their evidence, measured impact, confidence, next action, and verification instruction. Compatible route-cohort summaries use bounded histogram p50/p95 estimates and disclose their population and versions; see `docs/architecture/SCORE-V2.md`.

## Trace This Page

The admin-bar action forces a trace of the current frontend page and links to the stored result.

The admin bar offers an ordinary forced trace using the configured Safe/Standard mode and a separate one-shot Deep trace. Force cookies expire after five minutes and are consumed by the matching request; a bounded Deep override can run for no more than 15 minutes. The stored capture report records the effective mode and capability state, and success is shown only after trace insertion succeeds.

## Guided workflow capture and comparison

Tools > WP Flame can arm a Standard session of up to 20 requests for at most 15 minutes, or one Deep request for at most five minutes. The user chooses the workflow population—frontend, admin, AJAX, REST, or GraphQL—before arming. Requests from other browser populations are ignored, and the first matching reproduced route pins the rest of that session to one comparable cohort. WP Flame does not replay authenticated URLs or sign in on the user's behalf.

Baseline and after sessions are compared only when route, request type, instrumentation mode, capability set, score version, and relevant environment context are compatible. Ten compatible observations per cohort can be labelled directional; at least 20 per cohort are required for the verified label and p95. A single before/after request is always insufficient. The local JSON report redacts its route by default and requires an explicit checkbox to include the normalized route key.

## Performance budgets

Current budgets can warn when an observed trace exceeds a configured duration or query count.

The duration:

- Begins when WP Flame capture begins.
- Excludes unobserved pre-bootstrap time.
- Excludes WP Flame persistence work.

Budgets are not yet route-, mode-, or capability-aware and should not be described as complete regression monitoring.

## WP-CLI

Current commands:

- `wp flame list`
- `wp flame show <id>`
- `wp flame prune`

They provide bounded administrative access to stored data. CLI output remains subject to the same trace completeness and attribution limits as wp-admin.

## Privacy and storage

Conservative defaults include:

- Query values redacted from stored request URLs.
- Normalized SQL instead of raw query text.
- Host-only HTTP metadata instead of full URLs.
- GraphQL operation metadata instead of full query text.
- User IDs, IP addresses, and user-agent strings disabled.

Operators can opt into more sensitive capture. Those options require an appropriate purpose, notice, access policy, retention period, export process, and erasure process.

Scheduled cleanup removes expired traces in bounded, resumable batches. Per-site row and byte quotas pause automatic capture safely and expose a recoverable health state.

## Runtime dependencies

The plugin currently requires:

- WordPress 6.0 or later.
- PHP 7.4 or later.

It uses:

- No production Composer packages beyond its generated autoloader.
- No JavaScript framework.
- No required SaaS service.
- No required PHP profiling extension.

These properties do not mean zero overhead. Instrumentation, backtraces, callback wrapping, serialization, and local persistence all have costs that depend on mode and workload.

## Performance overhead

There is no supported fixed-overhead claim yet.

Use:

- Safe mode first.
- Admin-only or conservative sampling.
- Bounded spans and trace size.
- Deep mode only for focused diagnostics.

Public v1 requires reproducible p50/p95 overhead, memory, storage, and behavior-equivalence benchmarks before production-safety language is used.

## Measurement glossary

- **Inclusive time:** observed wall time from a span's start to its end, including retained child spans. Inclusive values must not be added across overlapping ancestors and children.
- **Self time:** inclusive time minus the inclusive time of retained direct children. Because unsupported or dropped work may be absent, self time means "not attributed to retained children," not necessarily time inside one PHP function.
- **Source attribution:** WP Flame's best supported identification of the WordPress core, plugin, theme, mu-plugin, drop-in, callback, or external host associated with observed work. It is evidence of ownership context, not proof that every internal operation was measured.
- **Route:** a bounded, privacy-aware request or workflow identity used to group comparable observations, such as a normalized frontend path, matched REST route, AJAX action, cron runner, CLI command, or GraphQL operation.
- **Dropped span:** an observation that could not be retained because a collection limit was reached. A dropped span makes the trace less complete; it is never equivalent to zero work.
- **Partial trace:** a trace with a known observation gap, unavailable instrumentor, limit, late start, or abnormal finish. The capture report persists these reasons so users do not have to infer them.
- **Capability:** one category of telemetry and its status for a particular trace, such as lifecycle, database, WordPress HTTP API, callback, cache, or GraphQL observation. Unavailable and disabled capabilities are unknown, not healthy zeroes.
- **Confidence:** the strength of a finding or comparison based on its evidence, sample count, trace completeness, attribution quality, and compatible capabilities. Findings expose bounded confidence labels; comparisons additionally use documented sample thresholds.

## Interpretation checklist

Before acting on a trace, ask:

1. When did capture begin?
2. Which mode was used?
3. Which instrumentors actually succeeded?
4. Were spans dropped, trimmed, or auto-closed?
5. Is the source attribution specific or best-effort?
6. Is the request comparable with the baseline population?
7. Does the same contributor appear across multiple compatible observations?
8. Can the proposed change be verified with a compatible baseline/after cohort?

The detail view and comparison builder expose these compatibility facts directly. Broad site-wide summaries remain descriptive; use guided compatible cohorts for verification claims.
