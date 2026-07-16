# Storage quota and retention contract

Status: accepted for the public v1 release candidate
Decision date: 2026-07-16

## Decision

WP Flame applies two independent hard ceilings to trace storage for each WordPress site:

- 10,000 stored trace rows by default.
- 512 MB of encoded trace JSON by default.

The first ceiling reached stops further trace persistence. Automatic capture pauses until cleanup, a purge, or a quota increase creates room. Manual capture uses the same ceiling and reports that the operator must purge data or increase the quota before retrying. WP Flame never evicts an unexpired trace merely to accept a newer trace.

The limits are configurable within bounded ranges. The row limit is 100 to 1,000,000 traces. The byte limit is 16 MB to 10,240 MB. A single trace remains independently bounded by the maximum trace-size setting.

## Scope

Quotas are per site, not per WordPress network. Each site's prefixed trace table and site options are measured independently. A multisite network therefore cannot use one busy site to consume another site's WP Flame allowance.

Network-level maintenance will report the number of sites checked, sites at quota, sites with cleanup backlog, failures, and the continuation cursor. It will not combine the per-site ceilings into a misleading network-wide allowance.

## Benchmark evidence

The initial ceiling was checked against the repository's live WordPress compatibility fixture on 2026-07-16. The fixture contained WooCommerce 10.9.4, Elementor 4.1.5, bbPress 2.6.14, WPGraphQL 2.17.0, and a generated child theme. It exercised Safe, Standard, and bounded one-request Deep capture.

The retained local fixture contained 409 traces and 72.26 MB of encoded trace JSON:

| Mode | Traces | Mean bytes | P50 bytes | P95 bytes | Maximum bytes |
|---|---:|---:|---:|---:|---:|
| Safe | 163 | 10,567 | 2,369 | 3,221 | 620,043 |
| Standard | 128 | 45,224 | 34,581 | 51,675 | 619,657 |
| Deep | 118 | 578,423 | 618,350 | 620,867 | 622,145 |

This is an adversarial compatibility fixture rather than a representative production workload: Deep capture is deliberately over-represented, and a small number of Safe and Standard requests were forced near the trace-size cap. It is useful for ceiling design because it includes both normal small traces and bounded worst cases.

At the measured Standard mean, 10,000 traces use about 431 MB, so the row ceiling normally controls first while remaining below 512 MB. At the measured Deep mean, the byte ceiling controls at roughly 900 traces. Deep is also manual and expiring, so it cannot create an unnoticed continuous stream. Safe traces normally remain far smaller.

The default is intentionally a ceiling, not a storage target. Retention removes expired traces before the quota is enforced, and first-run v1 capture is manual-only. M6 release benchmarks must repeat these measurements on representative site fixtures. Changing the default before the release candidate requires updating this decision, the settings defaults, tests, and release notes together.

## Retention behavior

Scheduled retention deletes at most 500 expired traces per SQL statement. One cleanup invocation runs no more than ten batches and has a 250 ms application time budget. If expired rows remain, WP Flame schedules a single continuation and repeats bounded work until the backlog clears.

When a persistence attempt would exceed a ceiling, WP Flame first runs one bounded expired-row deletion and recalculates usage. It accepts the trace only if that creates sufficient room. Otherwise it records the quota state, pauses automatic capture, and leaves all unexpired rows intact.

Storage health exposes:

- Current trace count and estimated encoded JSON bytes.
- Row and byte limits and percentage used.
- The quota reason and paused-capture state.
- The oldest expired row, if any.
- The most recent cleanup timestamp and bounded result.

The byte figure measures encoded trace JSON rather than the database engine's total allocated table size. It is therefore a deterministic application quota and an estimate of disk use, not a promise about InnoDB allocation or index overhead.

## Failure and recovery contract

- A quota refusal does not affect the monitored application response.
- Insert and encoding failures are distinct from quota refusal.
- Purging or deleting traces and then retrying clears a quota pause when current usage is below both ceilings.
- Increasing a ceiling takes effect on the next quota check.
- Reducing a ceiling below current usage pauses new capture but does not delete retained evidence.
- Cleanup never deletes unexpired traces and remains bounded even after cron has been unavailable for a long period.
