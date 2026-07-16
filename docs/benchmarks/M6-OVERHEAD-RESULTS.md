# WP Flame M6 overhead benchmark

Recorded: 2026-07-16T09:25:09Z
Harness schema: `wp-flame-overhead.v1`
Warm-up: 10 sequential requests per row
Measured sample: 100 sequential requests per row

## Environment

- Host: macOS Darwin 25.5.0, Apple M3 Pro, 12 logical CPUs, 18 GiB memory.
- Runtime: Docker Desktop, WordPress 7.0.1, PHP 8.3.32, MariaDB 12.3.2.
- Node client: v24.16.0.
- Full-stack plugins: WP Flame, WooCommerce, Elementor, bbPress, and WPGraphQL.
- Sensitive trace capture remained disabled.

## Results

Wall-time deltas include loopback transport, application work, and WP Flame shutdown persistence. Negative values are measurement variation, not a speed claim.

| Scenario | Mode | n | p50 ms | p95 ms | p50 delta | p95 delta | peak-memory delta bytes | avg trace bytes | DB growth bytes | dropped | truncated | status/body equivalent |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| vanilla-home | disabled | 100 | 40.972 | 45.987 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| vanilla-home | sampled-out | 100 | 43.715 | 60.621 | 2.743 | 14.634 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| vanilla-home | Safe | 100 | 52.849 | 68.248 | 11.877 | 22.261 | 0 | 4,287 | 428,671 | 0 | 0 | yes/yes |
| vanilla-home | Standard | 100 | 52.219 | 69.769 | 11.247 | 23.782 | 0 | 13,937 | 1,393,711 | 0 | 0 | yes/yes |
| vanilla-home | Deep | 100 | 53.784 | 66.021 | 12.812 | 20.034 | 0 | 16,991 | 1,699,080 | 0 | 0 | yes/yes |
| stack-home | disabled | 100 | 88.928 | 139.540 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| stack-home | Standard | 100 | 114.079 | 163.451 | 25.151 | 23.911 | 0 | 62,453 | 6,245,326 | 0 | 0 | yes/yes |
| WooCommerce product | disabled | 100 | 102.434 | 147.394 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| WooCommerce product | Standard | 100 | 101.198 | 114.685 | -1.236 | -32.709 | 0 | 62,433 | 6,243,255 | 0 | 0 | yes/yes |
| WooCommerce checkout | disabled | 100 | 131.587 | 142.838 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/yes |
| WooCommerce checkout | Standard | 100 | 154.455 | 191.017 | 22.868 | 48.179 | n/a | 48,846 | 9,818,137 | 0 | 0 | yes/yes |
| Elementor page | disabled | 100 | 97.359 | 115.016 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| Elementor page | Standard | 100 | 123.993 | 148.353 | 26.634 | 33.337 | 0 | 63,163 | 6,316,347 | 0 | 0 | yes/yes |
| REST index | disabled | 100 | 79.797 | 111.681 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/yes |
| REST index | Standard | 100 | 91.053 | 140.894 | 11.256 | 29.213 | n/a | 38,952 | 3,895,242 | 0 | 0 | yes/yes |
| AJAX unknown action | disabled | 100 | 56.522 | 70.131 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/yes |
| AJAX unknown action | Standard | 100 | 69.482 | 104.393 | 12.960 | 34.262 | n/a | 40,686 | 4,068,583 | 0 | 0 | yes/yes |
| cron runner | disabled | 100 | 53.262 | 63.759 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/yes |
| cron runner | Standard | 100 | 63.855 | 109.309 | 10.593 | 45.550 | n/a | 33,013 | 3,301,287 | 0 | 0 | yes/yes |
| GraphQL operation | disabled | 100 | 87.006 | 100.863 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/yes |
| GraphQL operation | Standard | 100 | 91.809 | 110.626 | 4.803 | 9.763 | n/a | 28,862 | 2,886,240 | 0 | 0 | yes/yes |
| 100-query fixture | disabled | 100 | 94.035 | 118.116 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| 100-query fixture | Standard | 100 | 119.549 | 158.405 | 25.514 | 40.289 | 0 | 101,722 | 10,172,191 | 0 | 0 | yes/yes |
| 2,000-span fixture | disabled | 100 | 85.227 | 102.314 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| 2,000-span fixture | Standard | 100 | 110.784 | 137.834 | 25.557 | 35.520 | 4,194,304 | 414,385 | 41,438,514 | 0 | 0 | yes/yes |
| maximum trace | disabled | 100 | 92.066 | 111.877 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/yes |
| maximum trace | Standard | 100 | 143.717 | 289.829 | 51.651 | 177.952 | 14,680,064 | 916,314 | 91,631,372 | 113,400 | 0 | yes/yes |
| WP Flame dashboard | disabled | 100 | 175.737 | 199.000 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | yes/dynamic |
| WP Flame dashboard | Standard | 100 | 173.408 | 227.545 | -2.329 | 28.545 | 0 | 0 | 0 | 0 | 0 | yes/dynamic |
| WooCommerce admin | disabled | 100 | 228.644 | 412.336 | 0 | 0 | n/a | 0 | 0 | 0 | 0 | yes/dynamic |
| WooCommerce admin | Standard | 100 | 244.760 | 300.497 | 16.116 | -111.839 | n/a | 67,350 | 13,537,401 | 0 | 0 | yes/dynamic |

## Tested release budgets

These ceilings are regression gates for the documented local/container method, not promises for arbitrary hosting:

| Profile | p50 delta ceiling | p95 delta ceiling |
| --- | ---: | ---: |
| Sampled-out vanilla | 50 ms | 100 ms |
| Safe vanilla | 100 ms | 150 ms |
| Standard vanilla | 100 ms | 200 ms |
| One-shot Deep vanilla | 150 ms | 250 ms |
| Routine full-stack Standard route | 250 ms | 500 ms |
| 100-query Standard | 250 ms | 500 ms |
| 2,000-span Standard | 300 ms | 600 ms |
| Maximum supported trace Standard | 500 ms | 1,000 ms |
| Authenticated admin Standard | 500 ms | 1,000 ms |

All rows must additionally preserve response status, preserve the normalized anonymous body, have zero request failures and trace truncations, average no more than 1.25 MB per trace, and grow trace JSON by no more than 1.5 MB per measured request. `bin/check-performance-budget.mjs` enforces these conditions. Admin bodies are dynamic and use status equivalence plus the independent browser suite.

The ceilings deliberately leave CI/host noise above the observed sample. They are intended to catch material regressions such as a payload-sized table scan, not to turn one laptop result into a universal percentage claim. New public quantitative claims require a new dated run.

## Release-blocking issue discovered and corrected

The first valid 100-run pass found increasing capture cost as the trace table grew. Quota enforcement executed `SUM(LENGTH(trace_data))` over every stored `LONGTEXT` payload on each persistence. After the maximum-trace fixture had generated roughly 90 MB per 100 requests, later Standard captures paid a payload-sized scan.

Schema v6 adds `trace_bytes`, stores the exact encoded size for new traces, and backfills legacy rows in bounded 500-row migration batches. Quota totals then scan the compact numeric field while preserving the same exact row/byte ceiling. The focused WordPress/MySQL gate passes 25 tests and 393 assertions, including a 501-row resumable backfill and byte-total equality. The corrected publication run above supersedes the pre-fix reports in `build/benchmarks/`.

## Method and limitations

- Public requests use Node's monotonic client clock and a fresh loopback connection. Warm-ups are excluded; measurements are sequential to avoid manufactured cross-request contention.
- Anonymous response bodies normalize only the benchmark memory marker, nonce values, and bbPress's timestamp asset version.
- Authenticated admin documents use a logged-in Playwright request context and measure server render plus transfer without loading subresources. M5 separately exercises client rendering and the maximum-trace graph.
- Captured trace duration stops before persistence by contract; external wall time includes shutdown persistence and is the safety reference.
- CPU deltas are unavailable because Docker Desktop does not expose trustworthy per-request CPU accounting in this setup.
- A near-end HTML marker provides disabled/captured peak-memory comparison. REST, AJAX, cron, GraphQL, and some dynamic admin responses do not expose it, so memory delta is `n/a`.
- The maximum fixture attempts 6,000 spans against a configured 5,000-span collector bound. The reported 113,400 dropped spans are the aggregate over 100 measured requests plus fixture lifecycle; zero traces were truncated.
- Automatic capture is off on a new install. The active-mode rows model an explicitly captured request, not every production visitor.

## Reproduction

```bash
WP_FLAME_BENCHMARK_RUNS=100 WP_FLAME_BENCHMARK_WARMUP=10 node bin/benchmark-overhead.mjs
WP_FLAME_PERFORMANCE_MIN_RUNS=100 node bin/check-performance-budget.mjs build/benchmarks
```

The harness records its environment and restores the pre-run WP Flame options when it exits. Fewer than 100 measured runs are rejected unless the explicit development-only override is supplied.
