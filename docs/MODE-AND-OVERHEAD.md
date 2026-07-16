# Capture modes and measured overhead

WP Flame adds work only to requests that reach WordPress/PHP. New installations are manual-only; automatic sampling requires an explicit administrator opt-in.

| Mode | Intended use | Captured capability | Operating rule |
| --- | --- | --- | --- |
| Safe | Compatibility-first inspection | Supported lifecycle and WordPress HTTP API evidence | Database and callbacks are not requested. |
| Standard | Normal diagnosis | Safe evidence plus compatible core-`wpdb` queries | Recommended first capture for most slow workflows. |
| Deep | Focused developer diagnosis | Standard evidence plus supported WordPress callback timing | Exactly one request or no more than five minutes; never leave enabled. |

Capability reporting is authoritative for each trace. A custom database drop-in, unavailable early mu-plugin, unsupported GraphQL hook, or opaque cache backend is shown as unavailable/degraded instead of being converted to zero.

## Tested budget, not a universal promise

The dated 100-run M6 publication records p50/p95 wall-time deltas, peak-memory evidence where exposed, trace bytes, database growth, dropped/truncated spans, and status/body equivalence across 31 rows. The local/container budgets range from a 100 ms p50 ceiling for ordinary vanilla Safe/Standard captures to a 500 ms p50 ceiling for the deliberately extreme maximum-supported trace and authenticated admin fixtures.

These are regression ceilings for the documented environment, not a promise that every host or site will add a fixed number or percentage. Use conservative sampling on each target site and compare against its own baseline. The benchmark includes shutdown persistence in external wall time even though the stored observed-duration metric stops before persistence.

Full results and reproduction steps: [benchmarks/M6-OVERHEAD-RESULTS.md](benchmarks/M6-OVERHEAD-RESULTS.md).
