# Versioned aggregate rollups

Status: public v1 storage contract
Last verified: 2026-07-16

WP Flame stores raw traces for inspection and derives daily aggregate rows for dashboards, trends, and later budget evaluation. The rollups prevent those screens from repeatedly selecting and decoding an arbitrary subset of large trace JSON documents.

## Source population

Each successfully persisted trace begins with `rollup_version = 0`. A bounded background worker selects at most 25 pending traces, decodes them through the backward-compatible `Trace::fromArray()` boundary, writes their aggregate rows in a transaction, and then checkpoints that trace at the current `Rollup::VERSION`.

The dashboard population disclosure reports:

- Current rollup algorithm version.
- Successfully summarized trace count.
- Number of capability cohorts.
- Number of score versions and instrumentation modes.
- Pending trace count.

Aggregate previews can span multiple compatible and incompatible cohorts. They are descriptive site-wide summaries only. Route conclusions and before/after comparisons must additionally match route, request type, instrumentation mode, capability cohort, and score version.

## Dimensions and measures

Every trace produces one cohort row plus at most 100 source/type/callback dimension rows. Each dimension hash includes:

- UTC day bucket.
- Rollup and score version.
- Request type and normalized route key.
- Instrumentation mode.
- Capability-cohort hash.
- Source, span type, and callback key.

Measures include sample and span counts, observed request duration, inclusive span duration, exclusive/self span duration, query count, score totals, scored samples, and complete samples. Source/type breakdowns use self time so nested spans are not double-counted. Callback rankings retain inclusive time because the measured callback contribution includes supported nested work.

Capability data is stored as bounded status/reason JSON and hashed into the cohort identity. Missing telemetry is therefore never silently mixed with captured telemetry. Scores retain their own algorithm version and unscored samples remain distinguishable.

## Bounded and resumable operation

- Normal request persistence only inserts the raw trace and schedules a single cron continuation; it does not perform aggregate SQL work.
- A worker processes at most 25 traces or 250 ms by default.
- A 60-second option lock prevents concurrent workers from double-counting. Stale locks recover automatically.
- Each trace is projected transactionally. Database failures roll back and leave the trace pending for retry.
- Malformed legacy JSON is reported and checkpointed as examined so one corrupt row cannot block later traces.
- `wp flame rollups --until-complete` provides an explicit maintenance path with a bounded run limit.
- `wp flame health` and wp-admin expose pending rollup state.

## Versioning and rebuilds

`Rollup::VERSION` defines the projection meaning. A future incompatible algorithm must increment it and rebuild from retained raw traces rather than updating current rows in place. Dimension hashes include the rollup version, so generations cannot collide. Historical raw traces remain readable even if their older aggregate generation is no longer used for current comparisons.

Rollups are derived local diagnostic data. Purge-all and uninstall remove them. Individual trace deletion does not attempt to subtract from an already aggregated daily row; the population is therefore the set of successfully summarized captures, not a promise that every contributing raw trace is still retained. Aggregate retention is day-granular and must not outlive the product's documented retention policy.

## Privacy

Rollups contain normalized routes, supported source/callback labels, performance measures, and capability evidence. They do not include WordPress user IDs, IP addresses, user-agent strings, raw SQL, full HTTP URLs, or full GraphQL documents. Sensitive raw fields remain governed by the separate acknowledgement and trace-retention controls described in [PRIVACY.md](../PRIVACY.md).
